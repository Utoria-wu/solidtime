<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Jobs\RecalculateSpentTimeForProject;
use App\Jobs\RecalculateSpentTimeForTask;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimerSession;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskTimerController extends Controller
{
    public function index(Organization $organization): JsonResponse
    {
        $this->checkPermission($organization, 'time-entries:create:own');
        $tasks = Task::query()->whereBelongsTo($organization);
        if (! $this->hasPermission($organization, 'tasks:view:all')) {
            $tasks->visibleByEmployee($this->user());
        }

        $sessions = TaskTimerSession::query()
            ->where('member_id', $this->member($organization)->id)
            ->whereIn('task_id', $tasks->select('id'))
            ->get();

        return response()->json(['data' => $sessions->map(fn (TaskTimerSession $session) => [
            'id' => $session->id,
            'task_id' => $session->task_id,
            'version' => $session->version,
            'started_at' => $session->started_at?->timestamp,
            'elapsed_seconds' => array_sum(array_map(
                static fn (array $segment) => $segment['end'] - $segment['start'],
                $session->segments
            )),
        ])]);
    }

    public function update(Organization $organization, Task $task, Request $request): JsonResponse
    {
        abort_unless($task->organization_id === $organization->id, 404);
        $this->checkPermission($organization, 'time-entries:create:own');
        $project = $task->project;
        $visible = Project::query()->whereKey($project->id)->visibleByEmployee($this->user())->exists();
        abort_unless($visible || $this->hasPermission($organization, 'projects:view:all'), 403);

        $input = $request->validate([
            'action' => 'required|in:start,pause,resume,complete',
            'session_id' => 'required_unless:action,start|uuid',
            'version' => 'required|integer|min:0',
        ]);
        $action = $input['action'];
        if (in_array($action, ['start', 'resume', 'complete'], true) && ! $this->hasPermission($organization, 'tasks:update:all')) {
            $this->checkPermission($organization, 'tasks:update');
            abort_unless($visible, 403);
        }

        $member = $this->member($organization);
        $saved = DB::transaction(function () use ($organization, $task, $project, $member, $input, $action): int {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $session = TaskTimerSession::query()->where('task_id', $task->id)
                ->where('member_id', $member->id)->first();
            $now = Carbon::now()->startOfSecond();

            if ($action === 'start' || $action === 'resume') {
                abort_if($organization->prevent_overlapping_time_entries, 422, 'Allow overlapping time entries in organization settings to use task timers.');
                abort_if($task->done_at !== null || $project->is_archived, 422, 'This task cannot be started.');
            }

            if ($action === 'start') {
                if ($session === null) {
                    $session = new TaskTimerSession;
                    $session->id = (string) Str::uuid();
                    $session->task_id = $task->id;
                    $session->member_id = $member->id;
                    $session->segments = [];
                    $session->started_at = $now;
                    $session->save();
                }

                return 0;
            }

            if ($session === null && $action === 'complete' && $task->done_at !== null) {
                return 0;
            }

            abort_unless(
                $session !== null && $session->id === $input['session_id'] && $session->version === (int) $input['version'],
                422,
                'The timer changed. Please try again.'
            );

            if ($action === 'resume') {
                if ($session->started_at === null) {
                    $session->started_at = $now;
                }
            } elseif ($session->started_at !== null) {
                if ($now->gt($session->started_at)) {
                    $session->segments = [...$session->segments, [
                        'start' => $session->started_at->timestamp,
                        'end' => $now->timestamp,
                    ]];
                }
                $session->started_at = null;
            }

            if ($action !== 'complete') {
                $session->version++;
                $session->save();

                return 0;
            }

            $count = $this->saveEntries($organization, $member, $project, $task, $session);
            $task->done_at ??= $now;
            $task->save();
            $session->delete();

            return $count;
        });

        return response()->json(['saved_entries' => $saved]);
    }

    private function saveEntries(Organization $organization, Member $member, Project $project, Task $task, TaskTimerSession $session): int
    {
        $billable = $project->is_billable && $this->canUseBillableRates($organization);
        foreach ($session->segments as $segment) {
            $start = Carbon::createFromTimestamp($segment['start'], 'UTC');
            $end = Carbon::createFromTimestamp($segment['end'], 'UTC');

            if ($organization->prevent_overlapping_time_entries) {
                $overlaps = TimeEntry::query()->whereBelongsTo($member)
                    ->where('start', '<', $end)
                    ->where(fn ($query) => $query->whereNull('end')->orWhere('end', '>', $start))
                    ->exists();
                abort_if($overlaps, 422, 'These timer segments overlap with an existing entry.');
            }

            $entry = new TimeEntry;
            $entry->organization()->associate($organization);
            $entry->member()->associate($member);
            $entry->user()->associate($this->user());
            $entry->project()->associate($project);
            $entry->task()->associate($task);
            $entry->client()->associate($project->client_id);
            $entry->description = $task->name;
            $entry->start = $start;
            $entry->end = $end;
            $entry->billable = $billable;
            $entry->tags = [];
            $entry->setComputedAttributeValue('billable_rate');
            $entry->save();
        }

        RecalculateSpentTimeForTask::dispatch($task)->afterCommit();
        RecalculateSpentTimeForProject::dispatch($project)->afterCommit();

        return count($session->segments);
    }
}
