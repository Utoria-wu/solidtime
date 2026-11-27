<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\Role;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRelationship;
use App\Models\TimeEntry;
use Laravel\Passport\Passport;

class TaskMoveEndpointTest extends ApiEndpointTestAbstract
{
    public function test_move_preserves_task_history_and_calendar_records(): void
    {
        $data = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($data->user);
        $source = Project::factory()->forOrganization($data->organization)->create();
        $destination = Project::factory()->forOrganization($data->organization)->create();
        $task = Task::factory()->forOrganization($data->organization)->for($source)->create(['markdown_notes' => 'Keep this note']);
        $timeEntry = TimeEntry::factory()->forMember($data->member)->forOrganization($data->organization)
            ->for($source)->for($task)->create(['markdown_notes' => 'Calendar note', 'end' => now()]);
        $base = '/api/v1/organizations/'.$data->organization->id;
        $legacy = $this->postJson($base.'/projects/'.$source->id.'/history', [
            'task_id' => $task->id, 'kind' => 'progress', 'title' => 'Review', 'occurred_at' => now()->toIso8601String(),
            'time_entry_id' => $timeEntry->id,
        ])->assertCreated()->json('data.id');

        $this->putJson($base.'/tasks/'.$task->id, ['project_id' => $destination->id, 'name' => $task->name])
            ->assertOk()->assertJsonPath('data.project_id', $destination->id)->assertJsonPath('data.markdown_notes', 'Keep this note');
        $this->assertDatabaseHas('time_entries', ['id' => $timeEntry->id, 'project_id' => $destination->id, 'task_id' => $task->id, 'markdown_notes' => 'Calendar note']);
        $this->assertDatabaseHas('task_history_entries', ['id' => $legacy, 'project_id' => $destination->id, 'time_entry_id' => $timeEntry->id]);
        $this->getJson($base.'/projects/'.$source->id.'/history')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/projects/'.$destination->id.'/history')->assertOk()->assertJsonPath('data.0.source.id', $timeEntry->id);
    }

    public function test_move_checks_destination_names_and_preserves_existing_relationships(): void
    {
        $data = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($data->user);
        $source = Project::factory()->forOrganization($data->organization)->create();
        $destination = Project::factory()->forOrganization($data->organization)->create();
        $task = Task::factory()->forOrganization($data->organization)->for($source)->create();
        $other = Task::factory()->forOrganization($data->organization)->for($source)->create();
        Task::factory()->forOrganization($data->organization)->for($destination)->create(['name' => $task->name]);
        $url = '/api/v1/organizations/'.$data->organization->id.'/tasks/'.$task->id;
        $this->putJson($url, ['project_id' => $destination->id, 'name' => $task->name])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $link = TaskRelationship::query()->create(['project_id' => $source->id, 'task_id' => $other->id, 'related_task_id' => $task->id, 'kind' => 'fixes']);
        $this->putJson($url, ['project_id' => $destination->id, 'name' => 'A different name'])->assertUnprocessable()->assertJsonValidationErrors(['project_id']);
        $this->assertDatabaseHas('task_relationships', ['id' => $link->id, 'project_id' => $source->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $source->id, 'name' => $task->name]);
    }

    public function test_move_cannot_bypass_destination_access_or_active_timers(): void
    {
        $data = $this->createUserWithRole(Role::Employee);
        $data->organization->update(['employees_can_manage_tasks' => true]);
        Passport::actingAs($data->user);
        $source = Project::factory()->forOrganization($data->organization)->create(['is_public' => true]);
        $private = Project::factory()->forOrganization($data->organization)->create(['is_public' => false]);
        $destination = Project::factory()->forOrganization($data->organization)->create(['is_public' => true]);
        $task = Task::factory()->forOrganization($data->organization)->for($source)->create();
        $url = '/api/v1/organizations/'.$data->organization->id.'/tasks/'.$task->id;
        $this->putJson($url, ['project_id' => $private->id, 'name' => $task->name])->assertForbidden();
        TimeEntry::factory()->forOrganization($data->organization)->forMember($data->member)->for($source)->for($task)->create(['end' => null]);
        $this->putJson($url, ['project_id' => $destination->id, 'name' => $task->name])->assertUnprocessable()->assertJsonValidationErrors(['project_id']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $source->id]);
    }
}
