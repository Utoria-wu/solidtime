<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\EntityStillInUseApiException;
use App\Http\Requests\V1\Task\TaskIndexRequest;
use App\Http\Requests\V1\Task\TaskStoreRequest;
use App\Http\Requests\V1\Task\TaskUpdateRequest;
use App\Http\Resources\V1\Task\TaskCollection;
use App\Http\Resources\V1\Task\TaskResource;
use App\Jobs\RecalculateSpentTimeForProject;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskHistoryEntry;
use App\Models\TaskRelationship;
use App\Models\TaskTimerSession;
use App\Service\TaskRelationshipService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    protected function checkPermission(Organization $organization, string $permission, ?Task $task = null): void
    {
        parent::checkPermission($organization, $permission);
        if ($task !== null && $task->organization_id !== $organization->id) {
            throw new AuthorizationException('Task does not belong to organization');
        }
    }

    /**
     * Check scoped permission and verify user has access to the project
     *
     * @throws AuthorizationException
     */
    private function checkScopedPermissionForProject(Organization $organization, Project $project, string $permission): void
    {
        $this->checkPermission($organization, $permission);

        $user = $this->user();
        $hasAccess = Project::query()
            ->where('id', $project->id)
            ->visibleByEmployee($user)
            ->exists();

        if (! $hasAccess) {
            throw new AuthorizationException('You do not have permission to '.$permission.' in this project.');
        }
    }

    /**
     * Get tasks
     *
     * @throws AuthorizationException
     *
     * @operationId getTasks
     */
    public function index(Organization $organization, TaskIndexRequest $request): TaskCollection
    {
        $this->checkPermission($organization, 'tasks:view');
        $canViewAllTasks = $this->hasPermission($organization, 'tasks:view:all');
        $user = $this->user();

        $projectId = $request->input('project_id');

        $query = Task::query()
            ->whereBelongsTo($organization, 'organization');

        if ($projectId !== null) {
            $query->where('project_id', '=', $projectId);
        }

        if (! $canViewAllTasks) {
            $query->visibleByEmployee($user);
        }
        $doneFilter = $request->getFilterDone();
        if ($doneFilter === 'true') {
            $query->whereNotNull('done_at');
        } elseif ($doneFilter === 'false') {
            $query->whereNull('done_at')->whereNull('canceled_at');
        }

        $tasks = $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id')
            ->paginate(config('app.pagination_per_page_default'));

        return new TaskCollection($tasks);
    }

    /**
     * Create task
     *
     * @throws AuthorizationException
     *
     * @operationId createTask
     */
    public function store(Organization $organization, TaskStoreRequest $request, TaskRelationshipService $relationships): JsonResource
    {
        /** @var Project $project */
        $project = Project::query()->findOrFail($request->input('project_id'));

        if ($this->hasPermission($organization, 'tasks:create:all')) {
            $this->checkPermission($organization, 'tasks:create:all');
        } else {
            $this->checkScopedPermissionForProject($organization, $project, 'tasks:create');
        }

        $task = new Task;
        $task->name = $request->input('name');
        $task->project_id = $request->input('project_id');
        $task->markdown_notes = $request->validated('markdown_notes');
        $task->category_id = $request->validated('category_id');
        $task->stage = $request->validated('stage');
        $task->start_date = $request->date('start_date', 'Y-m-d');
        $task->due_date = $request->date('due_date', 'Y-m-d');
        if ($this->canAccessPremiumFeatures($organization) && $request->has('estimated_time')) {
            $task->estimated_time = $request->getEstimatedTime();
        }
        $task->organization()->associate($organization);
        $relationship = $request->validated('relationship');
        $task = DB::transaction(function () use ($organization, $task, $project, $relationship, $relationships): Task {
            $newTask = clone $task;
            if ($relationship !== null) {
                $this->checkAnyPermission($organization, ['tasks:view', 'tasks:view:all']);
                $relationships->lockGraph($organization);
                $relatedTask = $relationships->visibleTasks($organization, $this->user(), $this->hasPermission($organization, 'tasks:view:all'))
                    ->whereKey($relationship['related_task_id'])->lockForUpdate()->first();
                if (! $relatedTask) {
                    throw ValidationException::withMessages(['relationship.related_task_id' => 'Choose a task from a project you can view.']);
                }
            }
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $this->ensureUniqueName($lockedProject, $newTask);
            $newTask->save();
            if (isset($relatedTask)) {
                $relationships->link($newTask, $relatedTask, $relationship['kind']);
            }

            return $newTask;
        }, attempts: 3);

        return new TaskResource($task);
    }

    /**
     * Update task
     *
     * @throws AuthorizationException
     *
     * @operationId updateTask
     */
    public function update(Organization $organization, Task $task, TaskUpdateRequest $request): JsonResource
    {
        // Check task belongs to organization
        if ($task->organization_id !== $organization->id) {
            throw new AuthorizationException('Task does not belong to organization');
        }

        $task->name = $request->input('name');
        if ($this->canAccessPremiumFeatures($organization) && $request->has('estimated_time')) {
            $task->estimated_time = $request->getEstimatedTime();
        }
        foreach (['category_id', 'stage'] as $field) {
            if ($request->has($field)) {
                $task->{$field} = $request->validated($field);
            }
        }
        if ($request->has('markdown_notes')) {
            $task->markdown_notes = $request->validated('markdown_notes');
        }
        if ($request->has('start_date') && $request->has('due_date')) {
            $task->start_date = $request->date('start_date', 'Y-m-d');
            $task->due_date = $request->date('due_date', 'Y-m-d');
        }
        DB::transaction(function () use ($organization, $request, &$task): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($this->hasPermission($organization, 'tasks:update:all')) {
                $this->checkPermission($organization, 'tasks:update:all');
            } else {
                $this->checkScopedPermissionForProject($organization, $lockedTask->project, 'tasks:update');
            }
            $lockedTask->forceFill($task->getDirty());
            $destinationId = $request->validated('project_id');
            if ($destinationId !== null && strtolower($destinationId) !== $lockedTask->project_id) {
                $this->moveToProject($organization, $lockedTask, $destinationId);
            } elseif ($lockedTask->isDirty('name')) {
                $project = Project::query()->lockForUpdate()->findOrFail($lockedTask->project_id);
                $this->ensureUniqueName($project, $lockedTask);
            }
            if ($request->boolean('is_canceled')) {
                abort_if($lockedTask->is_done, 422, 'Reopen the completed task before canceling it.');
                $sessions = TaskTimerSession::query()->where('task_id', $lockedTask->id);
                abort_if($sessions->get()->contains(fn ($session) => $session->started_at !== null || $session->segments !== []), 422, 'Save any active or unsaved timer segments before canceling this task.');
                $sessions->delete();
                $lockedTask->canceled_at ??= Carbon::now();
            } elseif ($request->has('is_canceled')) {
                $lockedTask->canceled_at = null;
            }
            if ($request->has('is_done')) {
                abort_if($lockedTask->canceled_at !== null, 422, 'Restore this task before changing its completion status.');
                if ($request->getIsDone() && $request->has('completion_notes')) {
                    $lockedTask->completion_notes = $request->validated('completion_notes');
                }
                $lockedTask->done_at = $request->getIsDone() ? ($lockedTask->done_at ?? Carbon::now()) : null;
            }
            $lockedTask->save();
            $task = $lockedTask;
        }, attempts: 3);

        return new TaskResource($task);
    }

    private function moveToProject(Organization $organization, Task $task, string $destinationId): void
    {
        $source = $task->project;
        $destination = Project::query()->whereBelongsTo($organization)->lockForUpdate()->findOrFail($destinationId);
        if ($this->hasPermission($organization, 'tasks:create:all')) {
            $this->checkPermission($organization, 'tasks:create:all');
        } else {
            $this->checkScopedPermissionForProject($organization, $destination, 'tasks:create');
        }

        if ($destination->is_archived) {
            throw ValidationException::withMessages(['project_id' => 'Choose an active destination project.']);
        }
        $this->ensureUniqueName($destination, $task);
        $hasRelationships = TaskRelationship::query()
            ->where('task_id', $task->id)->orWhere('related_task_id', $task->id)->exists();
        if ($hasRelationships) {
            throw ValidationException::withMessages(['project_id' => 'This task is linked to another task. Remove the links before moving it.']);
        }
        $hasPendingTimer = TaskTimerSession::query()->where('task_id', $task->id)->get()
            ->contains(fn (TaskTimerSession $session): bool => $session->started_at !== null || $session->segments !== []);
        $entries = $task->timeEntries()->withTrashed()->lockForUpdate()->get();
        if ($hasPendingTimer || $entries->contains(fn ($entry): bool => ! $entry->trashed() && $entry->end === null)) {
            throw ValidationException::withMessages(['project_id' => 'Finish or save the task’s active timer before moving it.']);
        }
        if ($entries->isNotEmpty() && ! $this->hasPermission($organization, 'time-entries:update:all')) {
            $this->checkPermission($organization, 'time-entries:update:own');
            $member = $this->member($organization);
            if ($entries->contains(fn ($entry): bool => $entry->member_id !== $member->id)) {
                throw new AuthorizationException('You do not have permission to move another member’s time entries.');
            }
        }

        foreach ($entries as $entry) {
            $entry->project()->associate($destination);
            $entry->client()->associate($destination->client_id);
            $entry->setComputedAttributeValue('billable_rate');
            $entry->save();
        }
        TaskHistoryEntry::query()->where('task_id', $task->id)->increment('version', 1, ['project_id' => $destination->id]);
        $task->project()->associate($destination);

        if ($entries->isNotEmpty()) {
            RecalculateSpentTimeForProject::dispatch($source)->afterCommit();
            RecalculateSpentTimeForProject::dispatch($destination)->afterCommit();
        }
    }

    private function ensureUniqueName(Project $project, Task $task): void
    {
        $duplicates = $project->tasks()->where('name', $task->name);
        if ($task->exists) {
            $duplicates->whereKeyNot($task->id);
        }
        if ($duplicates->exists()) {
            throw ValidationException::withMessages(['name' => __('validation.task_name_already_exists')]);
        }
    }

    /**
     * Delete task
     *
     * @throws AuthorizationException|EntityStillInUseApiException
     *
     * @operationId deleteTask
     */
    public function destroy(Organization $organization, Task $task): JsonResponse
    {
        // Check task belongs to organization
        if ($task->organization_id !== $organization->id) {
            throw new AuthorizationException('Task does not belong to organization');
        }

        if ($this->hasPermission($organization, 'tasks:delete:all')) {
            $this->checkPermission($organization, 'tasks:delete:all');
        } else {
            $this->checkScopedPermissionForProject($organization, $task->project, 'tasks:delete');
        }

        if ($task->timeEntries()->exists()) {
            throw new EntityStillInUseApiException('task', 'time_entry');
        }

        $task->delete();

        return response()
            ->json(null, 204);
    }
}
