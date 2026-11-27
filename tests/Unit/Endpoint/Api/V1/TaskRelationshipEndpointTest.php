<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskRelationship;
use Laravel\Passport\Passport;

class TaskRelationshipEndpointTest extends ApiEndpointTestAbstract
{
    public function test_links_are_persisted_without_duplicates_and_can_be_removed(): void
    {
        $data = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        [$issue, $fix, $verification] = Task::factory()->count(3)->forOrganization($data->organization)->for($project)->create()->all();
        $url = $this->url($data->organization, $project);

        $body = ['task_id' => $fix->id, 'related_task_id' => $issue->id, 'kind' => 'fixes'];
        $id = $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.kind', 'fixes')->json('data.id');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($url, array_replace($body, ['kind' => 'verifies']))->assertUnprocessable()->assertJsonValidationErrors('related_task_id');
        $this->postJson($url, ['task_id' => $verification->id, 'related_task_id' => $fix->id, 'kind' => 'verifies'])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonFragment($body);

        $this->deleteJson($url.'/'.$id)->assertNoContent();
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissing($body);
        $fix->delete();
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_links_allow_same_organization_projects_and_reject_cross_project_cycles(): void
    {
        $data = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        [$first, $second, $third] = Task::factory()->count(3)->forOrganization($data->organization)->for($project)->create()->all();
        $otherProject = Project::factory()->forOrganization($data->organization)->create();
        $elsewhere = Task::factory()->forOrganization($data->organization)->for($otherProject)->create();
        $url = $this->url($data->organization, $project);

        $this->postJson($url, ['task_id' => $first->id, 'related_task_id' => $first->id, 'kind' => 'fixes'])->assertUnprocessable();
        $crossProject = ['task_id' => $first->id, 'related_task_id' => $elsewhere->id, 'kind' => 'fixes'];
        $crossLink = $this->postJson($url, $crossProject)->assertCreated()->json('data.id');
        $this->getJson($this->url($data->organization, $otherProject))
            ->assertOk()->assertJsonFragment($crossProject);
        $this->postJson($this->url($data->organization, $otherProject), [
            'task_id' => $elsewhere->id, 'related_task_id' => $third->id, 'kind' => 'verifies',
        ])->assertCreated();
        $this->postJson($url, ['task_id' => $second->id, 'related_task_id' => $first->id, 'kind' => 'fixes'])->assertCreated();
        $this->postJson($url, ['task_id' => $third->id, 'related_task_id' => $second->id, 'kind' => 'verifies'])
            ->assertUnprocessable()->assertJsonValidationErrors('related_task_id');

        $this->deleteJson($url.'/'.$crossLink)->assertNoContent();
        $this->getJson($this->url($data->organization, $otherProject))
            ->assertOk()->assertJsonMissing($crossProject);
        $this->postJson($url, ['task_id' => $third->id, 'related_task_id' => $second->id, 'kind' => 'verifies'])->assertCreated();
        $this->postJson($url, ['task_id' => $first->id, 'related_task_id' => $third->id, 'kind' => 'follows_up_on'])->assertUnprocessable()->assertJsonValidationErrors('related_task_id');
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_creating_a_task_with_a_link_is_atomic(): void
    {
        $data = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        $otherProject = Project::factory()->forOrganization($data->organization)->create();
        $related = Task::factory()->forOrganization($data->organization)->for($otherProject)->create();
        $url = '/api/v1/organizations/'.$data->organization->id.'/tasks';
        $body = [
            'name' => 'Verify a fix in another project',
            'project_id' => $project->id,
            'relationship' => ['related_task_id' => $related->id, 'kind' => 'verifies'],
        ];
        $taskId = $this->postJson($url, $body)->assertCreated()->json('data.id');
        $this->getJson($this->url($data->organization, $otherProject))->assertOk()
            ->assertJsonFragment(['task_id' => $taskId, 'related_task_id' => $related->id, 'kind' => 'verifies']);

        $foreign = Task::factory()->create();
        $this->postJson($url, array_replace($body, [
            'name' => 'Rejected cross-organization link',
            'relationship' => ['related_task_id' => $foreign->id, 'kind' => 'verifies'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('relationship.related_task_id');
        $this->assertDatabaseMissing('tasks', ['name' => 'Rejected cross-organization link']);
        $this->assertDatabaseCount('task_relationships', 1);
    }

    public function test_relationships_do_not_expose_inaccessible_projects(): void
    {
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:update', 'tasks:create']);
        Passport::actingAs($data->user);
        $private = Project::factory()->forOrganization($data->organization)->create(['is_public' => false]);
        $public = Project::factory()->forOrganization($data->organization)->create(['is_public' => true]);
        $source = Task::factory()->forOrganization($data->organization)->for($public)->create();
        $target = Task::factory()->forOrganization($data->organization)->for($private)->create();
        $link = TaskRelationship::query()->create([
            'project_id' => $public->id, 'task_id' => $source->id, 'related_task_id' => $target->id, 'kind' => 'verifies',
        ]);
        $url = $this->url($data->organization, $public);
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($url, ['task_id' => $source->id, 'related_task_id' => $target->id, 'kind' => 'verifies'])->assertForbidden();
        $this->deleteJson($url.'/'.$link->id)->assertForbidden();
        $this->postJson('/api/v1/organizations/'.$data->organization->id.'/tasks', [
            'name' => 'Cannot link to private task',
            'project_id' => $public->id,
            'relationship' => ['related_task_id' => $target->id, 'kind' => 'verifies'],
        ])->assertUnprocessable()->assertJsonValidationErrors('relationship.related_task_id');
        $this->assertDatabaseMissing('tasks', ['name' => 'Cannot link to private task']);
        $this->assertDatabaseCount('task_relationships', 1);
    }

    public function test_link_permissions_follow_project_visibility_and_task_edit_permission(): void
    {
        $data = $this->createUserWithPermission(['tasks:view']);
        Passport::actingAs($data->user);
        $private = Project::factory()->forOrganization($data->organization)->create(['is_public' => false]);
        $public = Project::factory()->forOrganization($data->organization)->create(['is_public' => true]);
        $foreign = Project::factory()->create();

        $this->getJson($this->url($data->organization, $private))->assertForbidden();
        $this->getJson($this->url($data->organization, $foreign))->assertNotFound();
        $url = $this->url($data->organization, $public);
        $this->getJson($url)->assertOk();
        $this->postJson($url)->assertForbidden();
    }

    private function url(Organization $organization, Project $project): string
    {
        return '/api/v1/organizations/'.$organization->id.'/projects/'.$project->id.'/task-relationships';
    }
}
