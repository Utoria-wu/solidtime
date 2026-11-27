<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRelationship;
use App\Service\TaskRelationshipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskRelationshipController extends Controller
{
    private function authorizeProject(Organization $organization, Project $project, string $permission): void
    {
        abort_unless($project->organization_id === $organization->id, 404);
        $allPermission = $permission.':all';
        $this->checkAnyPermission($organization, [$permission, $allPermission]);
        abort_unless($this->hasPermission($organization, $allPermission)
            || Project::query()->whereKey($project->id)->visibleByEmployee($this->user())->exists(), 403);
    }

    public function index(Organization $organization, Project $project, Request $request, TaskRelationshipService $links): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:view');
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $visible = $links->visibleTasks($organization, $this->user(), $this->hasPermission($organization, 'tasks:view:all'))->select('id');
        $projectTasks = Task::query()->whereBelongsTo($project)->select('id');
        $page = TaskRelationship::query()
            ->where(fn ($query) => $query->where('project_id', $project->id)->orWhereIn('related_task_id', $projectTasks))
            ->whereIn('task_id', $visible)->whereIn('related_task_id', $visible)
            ->orderBy('id')->paginate(100);

        return response()->json([
            'data' => $page->getCollection()->map($this->serialize(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Organization $organization, Project $project, Request $request, TaskRelationshipService $links): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:update');
        $input = $request->validate([
            'task_id' => ['required', 'uuid'],
            'related_task_id' => ['required', 'uuid', 'different:task_id'],
            'kind' => ['required', Rule::in(TaskRelationship::KINDS)],
        ]);

        $relationship = DB::transaction(function () use ($organization, $project, $input, $links): TaskRelationship {
            $links->lockGraph($organization);
            $tasks = Task::query()->where('organization_id', $organization->id)
                ->whereKey([$input['task_id'], $input['related_task_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $task = $tasks->get(strtolower($input['task_id']));
            $relatedTask = $tasks->get(strtolower($input['related_task_id']));
            if ($tasks->count() !== 2 || $task?->project_id !== $project->id || $relatedTask === null) {
                throw ValidationException::withMessages(['related_task_id' => 'Choose a task from a project you can view.']);
            }
            if ($relatedTask->project_id !== $project->id) {
                $targetProject = Project::query()->findOrFail($relatedTask->project_id);
                $this->authorizeProject($organization, $targetProject, 'tasks:view');
            }

            return $links->link($task, $relatedTask, $input['kind']);
        }, attempts: 3);

        return response()->json(['data' => $this->serialize($relationship)], $relationship->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Organization $organization, Project $project, TaskRelationship $relationship, TaskRelationshipService $links): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:update');
        abort_unless($relationship->project_id === $project->id, 404);
        DB::transaction(function () use ($organization, $project, $relationship, $links): void {
            $links->lockGraph($organization);
            $target = Task::query()->where('organization_id', $organization->id)->findOrFail($relationship->related_task_id);
            if ($target->project_id !== $project->id) {
                $targetProject = Project::query()->findOrFail($target->project_id);
                $this->authorizeProject($organization, $targetProject, 'tasks:view');
            }
            $relationship->delete();
        }, attempts: 3);

        return response()->json(null, 204);
    }

    /** @return array{id: string, task_id: string, related_task_id: string, kind: string} */
    private function serialize(TaskRelationship $relationship): array
    {
        return [
            'id' => $relationship->id,
            'task_id' => $relationship->task_id,
            'related_task_id' => $relationship->related_task_id,
            'kind' => $relationship->kind,
        ];
    }
}
