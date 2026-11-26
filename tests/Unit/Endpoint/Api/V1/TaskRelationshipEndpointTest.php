<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
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

    public function test_links_reject_self_cross_project_and_indirect_cycles(): void
    {
        $data = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        [$first, $second, $third] = Task::factory()->count(3)->forOrganization($data->organization)->for($project)->create()->all();
        $otherProject = Project::factory()->forOrganization($data->organization)->create();
        $elsewhere = Task::factory()->forOrganization($data->organization)->for($otherProject)->create();
        $url = $this->url($data->organization, $project);

        $this->postJson($url, ['task_id' => $first->id, 'related_task_id' => $first->id, 'kind' => 'fixes'])->assertUnprocessable();
        $this->postJson($url, ['task_id' => $first->id, 'related_task_id' => $elsewhere->id, 'kind' => 'fixes'])->assertUnprocessable();
        $this->postJson($url, ['task_id' => $second->id, 'related_task_id' => $first->id, 'kind' => 'fixes'])->assertCreated();
        $this->postJson($url, ['task_id' => $third->id, 'related_task_id' => $second->id, 'kind' => 'verifies'])->assertCreated();
        $this->postJson($url, ['task_id' => $first->id, 'related_task_id' => $third->id, 'kind' => 'follows_up_on'])->assertUnprocessable()->assertJsonValidationErrors('related_task_id');
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 2);
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
