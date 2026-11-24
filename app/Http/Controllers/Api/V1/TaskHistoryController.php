<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskHistoryEntry;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskHistoryController extends Controller
{
    private function authorizeProject(Organization $organization, Project $project, string $permission): void
    {
        abort_unless($project->organization_id === $organization->id, 404);
        $allPermission = $permission === 'tasks:view' ? 'tasks:view:all' : 'tasks:update:all';
        $this->checkAnyPermission($organization, [$permission, $allPermission]);
        abort_unless($this->hasPermission($organization, $allPermission)
            || Project::query()->whereKey($project->id)->visibleByEmployee($this->user())->exists(), 403);
    }

    public function index(Organization $organization, Project $project, Request $request): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:view');
        $request->validate(['task_id' => ['nullable', 'uuid'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = TaskHistoryEntry::query()->where('project_id', $project->id)
            ->when($request->input('task_id'), fn ($query, $taskId) => $query->where('task_id', $taskId))
            ->with(['timeEntry', 'author:id,name'])->orderBy('occurred_at')->orderBy('id')->paginate(100);

        return response()->json(['data' => $page->getCollection()->map(fn ($entry) => $this->serialize($entry, $organization)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function store(Organization $organization, Project $project, Request $request): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:update');

        return $this->save($organization, $project, new TaskHistoryEntry, $request, 201);
    }

    public function update(Organization $organization, Project $project, TaskHistoryEntry $entry, Request $request): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:update');
        abort_unless($entry->project_id === $project->id, 404);

        return $this->save($organization, $project, $entry, $request);
    }

    private function save(Organization $organization, Project $project, TaskHistoryEntry $entry, Request $request, int $status = 200): JsonResponse
    {
        $input = $request->validate([
            'task_id' => ['required', 'uuid', Rule::exists('tasks', 'id')->where('project_id', $project->id)],
            'title' => ['required', 'string', 'max:500'],
            'kind' => ['required', Rule::in(TaskHistoryEntry::KINDS)],
            'markdown_notes' => ['nullable', 'string', 'max:10000'],
            'outcome' => ['nullable', Rule::in(['unsuccessful', 'resolved', 'verified'])],
            'occurred_at' => ['required', 'date'],
            'issue_id' => ['nullable', 'uuid'], 'parent_id' => ['nullable', 'uuid'], 'time_entry_id' => ['nullable', 'uuid'],
            'version' => [$entry->exists ? 'required' : 'sometimes', 'integer', 'min:0'],
        ]);
        abort_if($entry->exists && $entry->task_id !== $input['task_id'], 422, 'History records cannot be moved to another task.');
        $entry = DB::transaction(function () use ($organization, $project, $entry, $input): TaskHistoryEntry {
            Task::query()->whereKey($input['task_id'])->lockForUpdate()->firstOrFail();
            if ($entry->exists) {
                $entry = TaskHistoryEntry::query()->lockForUpdate()->findOrFail($entry->id);
                abort_unless($entry->version === $input['version'], 422, 'This record changed. Reload it before editing.');
                if ($entry->kind === 'problem' && $input['kind'] !== 'problem') {
                    abort_if(TaskHistoryEntry::query()->where('issue_id', $entry->id)->exists(), 422, 'This problem still has linked records.');
                }
            }
            $issueId = $input['issue_id'] ?? null;
            $parentId = $input['parent_id'] ?? null;
            abort_if($input['kind'] === 'problem' && $issueId !== null, 422, 'A problem is the root of its own thread.');
            if ($issueId !== null) {
                $issue = TaskHistoryEntry::query()->whereKey($issueId)->where('task_id', $input['task_id'])->where('kind', 'problem')->first();
                abort_unless($issue, 422, 'Choose a problem from this task.');
                abort_if($issue->occurred_at->gt($input['occurred_at']), 422, 'A linked record cannot occur before its problem.');
            }
            if ($entry->exists && $entry->issue_id !== $issueId) {
                abort_if(TaskHistoryEntry::query()->where('parent_id', $entry->id)->exists(), 422, 'Relink the following records before changing this problem thread.');
            }
            if ($parentId !== null) {
                $directParent = TaskHistoryEntry::query()->whereKey($parentId)->where('task_id', $input['task_id'])->first();
                abort_unless($directParent && ($directParent->id === $issueId || $directParent->issue_id === $issueId), 422, 'Choose a preceding record from this problem thread.');
            }
            $cursor = $parentId;
            while ($cursor !== null) {
                abort_if($cursor === $entry->id, 422, 'A record cannot depend on itself or its descendants.');
                $parent = TaskHistoryEntry::query()->whereKey($cursor)->where('task_id', $input['task_id'])->first();
                abort_unless($parent, 422, 'Choose a preceding record from this task.');
                abort_if($parent->occurred_at->gt($input['occurred_at']), 422, 'A preceding record cannot occur after this record.');
                $cursor = $parent->parent_id;
            }
            if ($entry->exists) {
                abort_if(TaskHistoryEntry::query()->where(fn ($query) => $query->where('parent_id', $entry->id)->orWhere('issue_id', $entry->id))->where('occurred_at', '<', $input['occurred_at'])->exists(), 422, 'This record cannot occur after a record that follows it.');
            }
            $sourceId = $input['time_entry_id'] ?? null;
            if ($sourceId !== null && $sourceId !== $entry->time_entry_id) {
                $source = TimeEntry::query()->whereKey($sourceId)->where('task_id', $input['task_id'])->first();
                abort_unless($source && ($source->user_id === $this->user()->id || $this->hasPermission($organization, 'time-entries:view:all')), 422, 'Choose an accessible Calendar event from this task.');
            }
            $entry->fill($input);
            $entry->organization_id = $organization->id;
            $entry->project_id = $project->id;
            $entry->user_id ??= $this->user()->id;
            $entry->issue_id = $issueId;
            $entry->parent_id = $parentId;
            $entry->time_entry_id = $sourceId;
            $entry->version = $entry->exists ? $entry->version + 1 : 0;
            $entry->save();

            return $entry;
        });

        return response()->json(['data' => $this->serialize($entry->load(['timeEntry', 'author:id,name']), $organization)], $status);
    }

    public function destroy(Organization $organization, Project $project, TaskHistoryEntry $entry, Request $request): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:update');
        abort_unless($entry->project_id === $project->id, 404);
        $input = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($entry, $input): void {
            Task::query()->whereKey($entry->task_id)->lockForUpdate()->firstOrFail();
            $locked = TaskHistoryEntry::query()->lockForUpdate()->findOrFail($entry->id);
            abort_unless($locked->version === $input['version'], 422, 'This record changed. Reload it before deleting.');
            abort_if(TaskHistoryEntry::query()->where(fn ($query) => $query->where('parent_id', $entry->id)->orWhere('issue_id', $entry->id))->exists(), 422, 'Relink the dependent records before deleting this record.');
            $locked->delete();
        });

        return response()->json(null, 204);
    }

    private function serialize(TaskHistoryEntry $entry, Organization $organization): array
    {
        $source = $entry->timeEntry;
        $canViewSource = $source && $source->task_id === $entry->task_id && ($source->user_id === $this->user()->id || $this->hasPermission($organization, 'time-entries:view:all'));

        return ['id' => $entry->id, 'task_id' => $entry->task_id, 'kind' => $entry->kind, 'title' => $entry->title,
            'markdown_notes' => $entry->markdown_notes, 'outcome' => $entry->outcome,
            'occurred_at' => $entry->occurred_at->toIso8601String(), 'issue_id' => $entry->issue_id,
            'parent_id' => $entry->parent_id, 'time_entry_id' => $entry->time_entry_id, 'version' => $entry->version,
            'author_name' => $entry->author?->name,
            'source' => $canViewSource ? ['id' => $source->id, 'description' => $source->description,
                'start' => $source->start->toIso8601String(), 'end' => $source->end?->toIso8601String()] : null];
    }
}
