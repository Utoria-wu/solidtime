<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TimeEntry;
use Laravel\Passport\Passport;

class TaskHistoryEndpointTest extends ApiEndpointTestAbstract
{
    public function test_all_task_scope_can_access_history_without_project_membership(): void
    {
        $data = $this->createUserWithPermission(['tasks:view:all', 'tasks:update:all']);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create(['is_public' => false]);
        $task = Task::factory()->forOrganization($data->organization)->for($project)->create();
        $base = '/api/v1/organizations/'.$data->organization->id;
        $this->getJson($base.'/task-categories')->assertOk();
        $this->getJson($base.'/projects/'.$project->id.'/history')->assertOk();
        $this->postJson($base.'/projects/'.$project->id.'/history', [
            'task_id' => $task->id, 'kind' => 'progress', 'title' => 'Review',
            'occurred_at' => '2026-11-24T10:00:00Z',
        ])->assertCreated();
    }

    public function test_categories_are_scoped_and_archiving_preserves_existing_assignments(): void
    {
        $data = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($data->user);
        $url = '/api/v1/organizations/'.$data->organization->id;
        $project = Project::factory()->forOrganization($data->organization)->create();
        $category = $this->postJson($url.'/task-categories', ['name' => 'Engineering', 'color' => '#5389b0', 'sort_order' => 2])
            ->assertCreated()->json('data');
        $this->postJson($url.'/task-categories', ['name' => 'Engineering', 'color' => '#5389b0'])->assertUnprocessable();
        $this->postJson($url.'/task-categories', ['name' => 'Invalid', 'color' => 'red'])->assertUnprocessable();
        $task = $this->postJson($url.'/tasks', ['name' => 'Investigate', 'project_id' => $project->id, 'category_id' => $category['id'], 'stage' => 'Discovery'])
            ->assertCreated()->assertJsonPath('data.stage', 'Discovery')->json('data');
        $this->putJson($url.'/task-categories/'.$category['id'], ['name' => 'Engineering', 'color' => '#5389b0', 'is_archived' => true])->assertOk();
        $this->putJson($url.'/tasks/'.$task['id'], ['name' => 'Investigate again', 'category_id' => $category['id']])->assertOk()->assertJsonPath('data.stage', 'Discovery');
        $this->postJson($url.'/tasks', ['name' => 'New task', 'project_id' => $project->id, 'category_id' => $category['id']])->assertUnprocessable();
        $other = Organization::factory()->create();
        $foreign = TaskCategory::query()->create(['organization_id' => $other->id, 'name' => 'Private', 'color' => '#5389b0']);
        $this->putJson($url.'/tasks/'.$task['id'], ['name' => 'Investigate', 'category_id' => $foreign->id])->assertUnprocessable();
        $this->putJson($url.'/task-categories/'.$foreign->id, ['name' => 'Changed', 'color' => '#5389b0'])->assertNotFound();
        $this->getJson($url.'/task-categories')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson($url.'/tasks/'.$task['id'], ['name' => 'Investigate', 'category_id' => null, 'stage' => null])->assertOk()->assertJsonPath('data.category_id', null)->assertJsonPath('data.stage', null);
    }

    public function test_history_links_versions_chronology_and_source_access_are_validated(): void
    {
        $data = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        $task = Task::factory()->forOrganization($data->organization)->for($project)->create();
        $otherTask = Task::factory()->forOrganization($data->organization)->for($project)->create();
        $url = '/api/v1/organizations/'.$data->organization->id.'/projects/'.$project->id.'/history';
        $body = ['task_id' => $task->id, 'kind' => 'problem', 'title' => 'Calendar total is stale', 'occurred_at' => '2026-11-24T09:00:00Z', 'markdown_notes' => '## Reproduction'];
        $problem = $this->postJson($url, $body)->assertCreated()->json('data');
        $attempt = $this->postJson($url, array_replace($body, ['kind' => 'attempt', 'title' => 'Check event update path', 'occurred_at' => '2026-11-24T10:00:00Z', 'issue_id' => $problem['id'], 'parent_id' => $problem['id'], 'outcome' => 'unsuccessful']))->assertCreated()->json('data');
        $verification = array_replace($body, ['kind' => 'verification', 'title' => 'Verify refreshed total', 'occurred_at' => '2026-11-24T11:00:00Z', 'issue_id' => $problem['id'], 'parent_id' => $attempt['id'], 'outcome' => 'verified']);
        $verified = $this->postJson($url, $verification)->assertCreated()->json('data');
        $this->putJson($url.'/'.$verified['id'], $verification + ['version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson($url.'/'.$verified['id'], $verification + ['version' => 0])->assertUnprocessable();
        $this->putJson($url.'/'.$attempt['id'], array_replace($attempt, ['parent_id' => $verified['id'], 'occurred_at' => '2026-11-24T12:00:00Z']))->assertUnprocessable();
        $this->postJson($url, array_replace($verification, ['task_id' => $otherTask->id]))->assertUnprocessable();
        $this->postJson($url, array_replace($verification, ['occurred_at' => '2026-11-24T08:00:00Z']))->assertUnprocessable();
        $this->postJson($url, array_replace($verification, ['parent_id' => null, 'occurred_at' => '2026-11-24T08:00:00Z']))->assertUnprocessable();
        $this->putJson($url.'/'.$problem['id'], array_replace($body, ['version' => 0, 'occurred_at' => '2026-11-24T12:00:00Z']))->assertUnprocessable();
        $source = TimeEntry::factory()->forOrganization($data->organization)->for($otherTask, 'task')->create();
        $this->postJson($url, array_replace($verification, ['time_entry_id' => $source->id]))->assertUnprocessable();
        $this->deleteJson($url.'/'.$problem['id'], ['version' => 0])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->deleteJson($url.'/'.$verified['id'], ['version' => 0])->assertUnprocessable();
        $this->deleteJson($url.'/'.$verified['id'], ['version' => 1])->assertNoContent();
        $this->assertDatabaseCount('task_history_entries', 2);
    }

    public function test_history_respects_private_projects_and_calendar_ownership(): void
    {
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:update']);
        Passport::actingAs($data->user);
        $private = Project::factory()->forOrganization($data->organization)->create(['is_public' => false]);
        $url = '/api/v1/organizations/'.$data->organization->id.'/projects/'.$private->id.'/history';
        $this->getJson($url)->assertForbidden();
        $public = Project::factory()->forOrganization($data->organization)->create(['is_public' => true]);
        $task = Task::factory()->forOrganization($data->organization)->for($public)->create();
        $url = '/api/v1/organizations/'.$data->organization->id.'/projects/'.$public->id.'/history';
        $this->getJson($url)->assertOk();
        $source = TimeEntry::factory()->forOrganization($data->organization)->for($task, 'task')->create(['user_id' => $data->owner->id, 'member_id' => $data->ownerMember->id, 'project_id' => $public->id]);
        $body = ['task_id' => $task->id, 'kind' => 'progress', 'title' => 'Work log', 'occurred_at' => '2026-11-24T10:00:00Z', 'time_entry_id' => $source->id];
        $this->postJson($url, $body)->assertUnprocessable();
        $source->user_id = $data->user->id;
        $source->member_id = $data->member->id;
        $source->save();
        $entry = $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.source.id', $source->id)->json('data');
        $source->delete();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.source', null);
        $this->putJson($url.'/'.$entry['id'], $body + ['version' => 0])->assertOk();
        $foreign = Project::factory()->create();
        $this->getJson('/api/v1/organizations/'.$data->organization->id.'/projects/'.$foreign->id.'/history')->assertNotFound();
    }
}
