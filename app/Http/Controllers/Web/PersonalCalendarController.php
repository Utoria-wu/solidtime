<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\User;
use App\Service\HolidayCalendarImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = $request->validate([
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after:start'],
        ]);
        if (CarbonImmutable::parse($range['end'])->gt(CarbonImmutable::parse($range['start'])->addYear()->addWeek())) {
            throw ValidationException::withMessages(['end' => 'Choose a range of up to one year.']);
        }

        return response()->json([
            'reminders' => $this->reminders($request)->where('date', '>=', $range['start'])
                ->where('date', '<', $range['end'])
                ->orderBy('date')->orderBy('time')->orderBy('id')->get(['id', 'title', 'date', 'time']),
            'holidays' => DB::table('personal_calendar_holidays')->where('user_id', $request->user()->getAuthIdentifier())
                ->where('start_date', '<', $range['end'])->where('end_date', '>', $range['start'])
                ->orderBy('start_date')->orderBy('id')->get(['id', 'title', 'start_date', 'end_date', 'workday']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->reminderData($request);
        $id = (string) Str::uuid();
        $this->reminders($request)->insert($data + [
            'id' => $id,
            'user_id' => $request->user()->getAuthIdentifier(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, string $reminder): Response
    {
        $data = $this->reminderData($request);
        DB::transaction(function () use ($request, $reminder, $data): void {
            $query = $this->reminders($request)->where('id', $reminder);
            $previous = (clone $query)->lockForUpdate()->first(['date', 'time', 'dismissed_at']);
            abort_if($previous === null, 404);
            if ($previous->date !== $data['date'] || $previous->time !== $data['time']) {
                $data['dismissed_at'] = null;
            } else {
                unset($data['remind_at']);
            }
            $query->update($data + ['updated_at' => now()]);
        });

        return response()->noContent();
    }

    public function destroy(Request $request, string $reminder): Response
    {
        abort_unless($this->reminders($request)->where('id', $reminder)->delete() === 1, 404);

        return response()->noContent();
    }

    public function alerts(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now('UTC');
        $pending = $this->reminders($request)->whereNull('dismissed_at')->whereNotNull('remind_at');
        $due = (clone $pending)->where('remind_at', '<=', $now)->orderBy('remind_at')->orderBy('id')
            ->limit(20)->get(['id', 'title', 'date', 'time']);
        $next = $pending->where('remind_at', '>', $now)->min('remind_at');

        return response()->json([
            'due' => $due,
            'next_in_ms' => $next === null ? null : (int) $now->diffInMilliseconds(CarbonImmutable::parse($next, 'UTC')),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function dismiss(Request $request, string $reminder): Response
    {
        $query = $this->reminders($request)->where('id', $reminder);
        abort_unless((clone $query)->exists(), 404);
        $query->whereNull('dismissed_at')->where('remind_at', '<=', now('UTC'))->update(['dismissed_at' => now('UTC')]);

        return response()->noContent();
    }

    public function import(Request $request, HolidayCalendarImport $importer): JsonResponse
    {
        $request->validate(['calendar' => ['required', 'file', 'max:512']]);
        $file = $request->file('calendar');
        $contents = $file->get();
        $calendar = $importer->parse(is_string($contents) ? $contents : '');
        if ($calendar['events'] === []) {
            throw ValidationException::withMessages(['calendar' => 'Choose an .ics file with at least one all-day holiday.']);
        }
        $userId = $request->user()->getAuthIdentifier();
        $rows = array_map(fn (array $event): array => $event + ['user_id' => $userId], $calendar['events']);
        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 250) as $batch) {
                DB::table('personal_calendar_holidays')->upsert($batch, ['user_id', 'uid_hash'], ['title', 'start_date', 'end_date', 'workday']);
            }
        });

        return response()->json(['imported' => count($rows), 'skipped' => $calendar['skipped']]);
    }

    private function reminders(Request $request): Builder
    {
        return DB::table('personal_calendar_reminders')->where('user_id', $request->user()->getAuthIdentifier());
    }

    /**
     * @return array{title: string, date: string, time: string|null, remind_at: CarbonImmutable|null}
     */
    private function reminderData(Request $request): array
    {
        $fields = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'],
            'time' => ['nullable', 'date_format:H:i'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $time = $fields['time'] ?? null;
        $remindAt = null;
        if ($time !== null) {
            $local = $fields['date'].' '.$time;
            $instant = CarbonImmutable::createFromFormat('!Y-m-d H:i', $local, $user->timezone);
            if (! $instant || $instant->format('Y-m-d H:i') !== $local) {
                throw ValidationException::withMessages(['time' => 'Choose a time that exists in your profile time zone.']);
            }
            $remindAt = $instant->utc();
        }

        return ['title' => $fields['title'], 'date' => $fields['date'], 'time' => $time, 'remind_at' => $remindAt];
    }
}
