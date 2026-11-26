<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $task_id
 * @property string $related_task_id
 * @property string $kind
 */
class TaskRelationship extends Model
{
    use HasUuids;

    public const KINDS = ['fixes', 'verifies', 'follows_up_on'];

    protected $fillable = ['project_id', 'task_id', 'related_task_id', 'kind'];
}
