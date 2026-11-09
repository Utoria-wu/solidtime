<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $task_id
 * @property string $member_id
 * @property list<array{start: int, end: int}> $segments
 * @property Carbon|null $started_at
 * @property int $version
 */
class TaskTimerSession extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $attributes = ['version' => 0];

    protected $casts = [
        'segments' => 'array',
        'started_at' => 'datetime',
        'version' => 'integer',
    ];
}
