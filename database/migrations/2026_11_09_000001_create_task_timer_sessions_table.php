<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_timer_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->json('segments');
            $table->timestamp('started_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->unique(['member_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_timer_sessions');
    }
};
