<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskHistoryEntry extends Model
{
    use HasUuids;

    public const KINDS = ['progress', 'problem', 'investigation', 'attempt', 'finding', 'action', 'verification'];

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime', 'version' => 'integer'];

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
