<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('related_task_id')->index()->constrained('tasks')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->timestamps();
            $table->unique(['task_id', 'related_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_relationships');
    }
};
