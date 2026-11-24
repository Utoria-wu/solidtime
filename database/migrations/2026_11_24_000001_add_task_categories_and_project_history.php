<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color', 7);
            $table->integer('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignUuid('category_id')->nullable()->constrained('task_categories')->nullOnDelete();
            $table->string('stage', 100)->nullable();
        });
        Schema::create('task_history_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('title', 500);
            $table->text('markdown_notes')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->timestampTz('occurred_at');
            $table->uuid('issue_id')->nullable();
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('time_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('version')->default(0);
            $table->timestamps();
            $table->index(['project_id', 'occurred_at']);
            $table->index(['task_id', 'issue_id']);
        });
        // The PostgreSQL enhanced schema driver adds primary keys after the create statement.
        Schema::table('task_history_entries', function (Blueprint $table): void {
            $table->foreign('issue_id')->references('id')->on('task_history_entries')->nullOnDelete();
            $table->foreign('parent_id')->references('id')->on('task_history_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_history_entries');
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('stage');
        });
        Schema::dropIfExists('task_categories');
    }
};
