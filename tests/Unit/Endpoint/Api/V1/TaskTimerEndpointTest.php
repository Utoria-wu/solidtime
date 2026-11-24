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
    public function test_stop_keeps_pending_time_until_pause_or_completion_saves_it(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own', 'tasks:update:all', 'tasks:view:all', 'projects:view:all',
        ]);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->create();
        $task = Task::factory()->forOrganization($data->organization)->for($project)->create();
        $url = route('api.v1.tasks.timers.update', [$data->organization, $task]);
        $index = route('api.v1.tasks.timers.index', [$data->organization]);

        $this->travelTo(Carbon::parse('2026-11-24 10:00:00', 'UTC'));
        $this->postJson($url, ['action' => 'start', 'version' => 0])->assertOk();
        $id = $this->getJson($index)->json('data.0.id');
        $this->travel(5)->minutes();
        $stop = ['action' => 'stop', 'session_id' => $id, 'version' => 0];
        $this->postJson($url, $stop)->assertOk()->assertJsonPath('saved_entries', 0);
        $this->getJson($index)->assertJsonPath('data.0.started_at', null)
            ->assertJsonPath('data.0.elapsed_seconds', 300)->assertJsonPath('data.0.version', 1);
        $this->assertDatabaseCount('time_entries', 0);
        $this->assertNull($task->fresh()->done_at);
        $this->postJson($url, $stop)->assertUnprocessable();

        $this->travel(5)->minutes();
        $this->postJson($url, ['action' => 'resume', 'session_id' => $id, 'version' => 1])->assertOk();
        $this->travel(5)->minutes();
        $this->postJson($url, ['action' => 'stop', 'session_id' => $id, 'version' => 2])->assertOk();
        $this->travel(10)->minutes();
        $this->getJson($index)->assertJsonPath('data.0.elapsed_seconds', 600);
        $this->assertDatabaseCount('time_entries', 0);

        $pause = ['action' => 'pause', 'session_id' => $id, 'version' => 3,
            'description' => 'Investigate Calendar synchronization', 'markdown_notes' => '## Found the cause'];
        $this->postJson($url, $pause)->assertOk()->assertJsonPath('saved_entries', 2);
        $this->postJson($url, $pause)->assertUnprocessable();
        $entries = TimeEntry::query()->whereBelongsTo($task, 'task')->orderBy('start')->get();
        $this->assertSame([$pause['markdown_notes'], $pause['markdown_notes']], $entries->pluck('markdown_notes')->all());
        $this->assertSame([['10:00', '10:05'], ['10:10', '10:15']],
            $entries->map(fn (TimeEntry $entry) => [$entry->start->format('H:i'), $entry->end?->format('H:i')])->all());
        $this->assertSame(600, $task->fresh()->spent_time);
        $this->assertNull($task->fresh()->done_at);
        $this->getJson($index)->assertJsonPath('data.0.elapsed_seconds', 0)
            ->assertJsonPath('data.0.started_at', null);
        $this->postJson($url, ['action' => 'pause', 'session_id' => $id, 'version' => 4,
            'markdown_notes' => 'No work to save'])->assertUnprocessable();

        $this->postJson($url, ['action' => 'resume', 'session_id' => $id, 'version' => 4])->assertOk();
        $this->travel(5)->minutes();
        $this->postJson($url, ['action' => 'stop', 'session_id' => $id, 'version' => 5])->assertOk();
        $this->postJson($url, ['action' => 'complete', 'session_id' => $id, 'version' => 6,
            'markdown_notes' => 'Verified the fix'])->assertOk()->assertJsonPath('saved_entries', 1);
        $this->assertDatabaseCount('time_entries', 3);
        $this->assertSame(900, $task->fresh()->spent_time);
        $this->assertNotNull($task->fresh()->done_at);
    }

    public function test_parallel_timers_keep_pause_gaps_and_complete_only_the_selected_task(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own', 'time-entries:update:own', 'tasks:update:all', 'tasks:view', 'tasks:view:all', 'projects:view:all',
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
        $pause = ['action' => 'pause', 'session_id' => $firstId, 'version' => 0, 'markdown_notes' => $pauseNotes];
        $this->postJson($firstUrl, $pause)->assertOk()->assertJsonPath('saved_entries', 1);
        $this->postJson($firstUrl, $pause)->assertUnprocessable();
        $pausedEntry = TimeEntry::query()->whereBelongsTo($first, 'task')->sole();
        $this->assertSame($pauseNotes, $pausedEntry->markdown_notes);
        $this->assertNull($first->fresh()->done_at);
        $this->assertSame(1200, $first->fresh()->spent_time);
        $this->putJson(route('api.v1.time-entries.update', [$data->organization, $pausedEntry]), [
            'start' => '2026-11-09T10:00:00Z',
            'end' => '2026-11-09T10:25:00Z',
        ])->assertOk();
        $this->assertSame(1500, $first->fresh()->spent_time);
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
        $this->postJson($firstUrl, $body)->assertOk()->assertJsonPath('saved_entries', 1);
        $this->getJson(route('api.v1.tasks.index', [$data->organization, 'done' => 'true']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.spent_time', 2700);
        $this->postJson($firstUrl, $body)->assertOk()->assertJsonPath('saved_entries', 0);
        $this->getJson($indexUrl)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.task_id', $second->id);

        $entries = TimeEntry::query()->whereBelongsTo($first, 'task')->orderBy('start')->get();
        $this->assertSame([$first->name, $body['description']], $entries->pluck('description')->all());
        $this->assertSame([$pauseNotes, $body['markdown_notes']], $entries->pluck('markdown_notes')->all());
        $this->assertSame([
            ['10:00', '10:25'],
            ['10:40', '11:00'],
        ], $entries->map(fn (TimeEntry $entry) => [$entry->start->format('H:i'), $entry->end?->format('H:i')])->all());
        $this->assertNotNull($first->fresh()->done_at);
        $this->assertSame($first->name, $first->fresh()->name);
        $this->assertNull($second->fresh()->done_at);
        $this->assertSame(0, TimeEntry::query()->whereBelongsTo($second, 'task')->count());
    }
}
