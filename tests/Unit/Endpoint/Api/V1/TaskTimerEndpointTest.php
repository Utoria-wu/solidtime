<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Jobs\RecalculateSpentTimeForTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

class TaskTimerEndpointTest extends ApiEndpointTestAbstract
{
    public function test_parallel_timers_keep_pause_gaps_and_complete_only_the_selected_task(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own', 'tasks:update:all', 'tasks:view', 'tasks:view:all', 'projects:view:all',
        ]);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        $first = Task::factory()->forOrganization($data->organization)->for($project)->create();
        $second = Task::factory()->forOrganization($data->organization)->for($project)->create();
        $firstUrl = route('api.v1.tasks.timers.update', [$data->organization, $first]);
        $secondUrl = route('api.v1.tasks.timers.update', [$data->organization, $second]);
        $indexUrl = route('api.v1.tasks.timers.index', [$data->organization]);

        $this->travelTo(Carbon::parse('2026-11-09 10:00:00', 'UTC'));
        $this->postJson($firstUrl, ['action' => 'start', 'version' => 0])->assertOk();
        $firstId = $this->getJson($indexUrl)->assertOk()->json('data.0.id');
        $this->travel(10)->minutes();
        $this->postJson($secondUrl, ['action' => 'start', 'version' => 0])->assertOk();
        $this->travel(10)->minutes();
        $pauseNotes = '## Completed before pause';
        $this->postJson($firstUrl, ['action' => 'pause', 'session_id' => $firstId, 'version' => 0, 'markdown_notes' => $pauseNotes])->assertOk();
        $this->travel(20)->minutes();
        $this->postJson($firstUrl, ['action' => 'resume', 'session_id' => $firstId, 'version' => 1])->assertOk();
        $this->travel(20)->minutes();

        $body = [
            'action' => 'complete',
            'session_id' => $firstId,
            'version' => 2,
            'description' => 'Review calendar entries',
            'markdown_notes' => "    Timing details\n\n- [x] Verify both segments",
        ];
        Queue::fake([RecalculateSpentTimeForTask::class]);
        $this->postJson($firstUrl, $body)->assertOk()->assertJsonPath('saved_entries', 2);
        $this->getJson(route('api.v1.tasks.index', [$data->organization, 'done' => 'true']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.spent_time', 2400);
        $this->postJson($firstUrl, $body)->assertOk()->assertJsonPath('saved_entries', 0);
        $this->getJson($indexUrl)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.task_id', $second->id);

        $entries = TimeEntry::query()->whereBelongsTo($first, 'task')->orderBy('start')->get();
        $this->assertSame([$body['description'], $body['description']], $entries->pluck('description')->all());
        $this->assertSame([$pauseNotes."\n\n".$body['markdown_notes'], $body['markdown_notes']], $entries->pluck('markdown_notes')->all());
        $this->assertSame([
            ['10:00', '10:20'],
            ['10:40', '11:00'],
        ], $entries->map(fn (TimeEntry $entry) => [$entry->start->format('H:i'), $entry->end?->format('H:i')])->all());
        $this->assertNotNull($first->fresh()->done_at);
        $this->assertSame($first->name, $first->fresh()->name);
        $this->assertNull($second->fresh()->done_at);
        $this->assertSame(0, TimeEntry::query()->whereBelongsTo($second, 'task')->count());
    }
}
