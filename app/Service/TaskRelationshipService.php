<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRelationship;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class TaskRelationshipService
{
    /** @return Builder<Task> */
    public function visibleTasks(Organization $organization, User $user, bool $canViewAll): Builder
    {
        $tasks = Task::query()->where('organization_id', $organization->id);
        if (! $canViewAll) {
            $tasks->whereIn('project_id', Project::query()->whereBelongsTo($organization)->visibleByEmployee($user)->select('id'));
        }

        return $tasks;
    }

    public function lockGraph(Organization $organization): void
    {
        // A cycle can span projects. All relationship writes lock the organization first.
        Organization::query()->lockForUpdate()->findOrFail($organization->id);
    }

    public function link(Task $task, Task $relatedTask, string $kind): TaskRelationship
    {
        $edges = TaskRelationship::query()
            ->whereIn('project_id', Project::query()->where('organization_id', $task->organization_id)->select('id'))
            ->get();
        $byTask = $edges->groupBy('task_id');
        $existing = collect($byTask->get($task->id, []))
            ->first(fn (TaskRelationship $edge) => $edge->related_task_id === $relatedTask->id);
        if ($existing) {
            if ($existing->kind !== $kind) {
                throw ValidationException::withMessages(['related_task_id' => 'These tasks are already linked. Remove the existing link to change its relationship.']);
            }

            return $existing;
        }

        $pending = [$relatedTask->id];
        $visited = [];
        while ($pending !== []) {
            $id = array_pop($pending);
            if ($id === $task->id) {
                throw ValidationException::withMessages(['related_task_id' => 'This link would create a circular task relationship.']);
            }
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            foreach ($byTask->get($id, []) as $edge) {
                $pending[] = $edge->related_task_id;
            }
        }

        return TaskRelationship::query()->create([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'related_task_id' => $relatedTask->id,
            'kind' => $kind,
        ]);
    }
}
