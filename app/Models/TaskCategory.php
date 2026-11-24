<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaskCategory extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['archived_at' => 'datetime', 'sort_order' => 'integer'];
}
