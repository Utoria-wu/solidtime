<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

class TaskTimerEndpointTest extends ApiEndpointTestAbstract
{
    public function test_parallel_timers_keep_pause_gaps_and_complete_only_the_selected_task(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own', 'tasks:update:all', 'tasks:view:all', 'projects:view:all',
        ]);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        $first = Task::factory()->forOrganization($data->organization)->for($project)->create();
        $second = Task::factory()->forOrganization($data->organization)->for($project)->create();
        $firstUrl = route('api.v1.tasks.timers.update', [$data->organization, $first]);
        $secondUrl = route('api.v1.tasks.timers.update', [$data->organization, $second]);
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();

        $this->travelTo(Carbon::parse('2026-11-09 10:00:00', 'UTC'));
        $this->postJson($firstUrl, ['action' => 'start', 'session_id' => $firstId, 'version' => 0])->assertOk();
        $this->travel(10)->minutes();
        $this->postJson($secondUrl, ['action' => 'start', 'session_id' => $secondId, 'version' => 0])->assertOk();
        $this->travel(10)->minutes();
        $this->postJson($firstUrl, ['action' => 'pause', 'session_id' => $firstId, 'version' => 0])->assertOk();
        $this->travel(20)->minutes();
        $this->postJson($firstUrl, ['action' => 'resume', 'session_id' => $firstId, 'version' => 1])->assertOk();
        $this->travel(20)->minutes();

        $body = ['action' => 'complete', 'session_id' => $firstId, 'version' => 2];
        $this->postJson($firstUrl, $body)->assertOk()->assertJsonPath('saved_entries', 2);
        $this->postJson($firstUrl, $body)->assertOk()->assertJsonPath('saved_entries', 0);
        $this->getJson(route('api.v1.tasks.timers.index', [$data->organization]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $secondId);

        $entries = TimeEntry::query()->whereBelongsTo($first, 'task')->orderBy('start')->get();
        $this->assertSame([
            ['10:00', '10:20'],
            ['10:40', '11:00'],
        ], $entries->map(fn (TimeEntry $entry) => [$entry->start->format('H:i'), $entry->end?->format('H:i')])->all());
        $this->assertNotNull($first->fresh()->done_at);
        $this->assertNull($second->fresh()->done_at);
        $this->assertSame(0, TimeEntry::query()->whereBelongsTo($second, 'task')->count());
    }
}
