<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRelationship;
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

    public function index(Organization $organization, Project $project, Request $request): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:view');
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = TaskRelationship::query()->where('project_id', $project->id)->orderBy('id')->paginate(100);

        return response()->json([
            'data' => $page->getCollection()->map($this->serialize(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Organization $organization, Project $project, Request $request): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:update');
        $input = $request->validate([
            'task_id' => ['required', 'uuid'],
            'related_task_id' => ['required', 'uuid', 'different:task_id'],
            'kind' => ['required', Rule::in(TaskRelationship::KINDS)],
        ]);

        $relationship = DB::transaction(function () use ($organization, $project, $input): TaskRelationship {
            // Serialize graph changes in this project so concurrent requests cannot introduce a cycle.
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $tasks = Task::query()->where('project_id', $project->id)->where('organization_id', $organization->id)
                ->whereIn('id', [$input['task_id'], $input['related_task_id']])->orderBy('id')->lockForUpdate()->get(['id']);
            if ($tasks->count() !== 2) {
                throw ValidationException::withMessages(['related_task_id' => 'Choose two tasks from this project.']);
            }

            // PostgreSQL returns UUIDs in lowercase.
            $taskId = strtolower($input['task_id']);
            $relatedTaskId = strtolower($input['related_task_id']);
            $relationships = TaskRelationship::query()->where('project_id', $project->id)->get();
            $existing = $relationships->first(fn (TaskRelationship $link) => $link->task_id === $taskId && $link->related_task_id === $relatedTaskId);
            if ($existing) {
                if ($existing->kind !== $input['kind']) {
                    throw ValidationException::withMessages(['related_task_id' => 'These tasks are already linked. Remove the existing link to change its relationship.']);
                }

                return $existing;
            }

            $byTask = $relationships->groupBy('task_id');
            $pending = [$relatedTaskId];
            $visited = [];
            while ($pending !== []) {
                $id = array_pop($pending);
                if ($id === $taskId) {
                    throw ValidationException::withMessages(['related_task_id' => 'This link would create a circular task relationship.']);
                }
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                foreach ($byTask->get($id, []) as $link) {
                    $pending[] = $link->related_task_id;
                }
            }

            return TaskRelationship::query()->create([
                'project_id' => $project->id,
                'task_id' => $taskId,
                'related_task_id' => $relatedTaskId,
                'kind' => $input['kind'],
            ]);
        });

        return response()->json(['data' => $this->serialize($relationship)], $relationship->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Organization $organization, Project $project, TaskRelationship $relationship): JsonResponse
    {
        $this->authorizeProject($organization, $project, 'tasks:update');
        abort_unless($relationship->project_id === $project->id, 404);
        DB::transaction(function () use ($project, $relationship): void {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $relationship->delete();
        });

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
