<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_calendar_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->date('date');
            $table->string('time', 5)->nullable();
            $table->timestampTz('remind_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'date']);
            $table->index(['user_id', 'dismissed_at', 'remind_at'], 'personal_calendar_reminders_due_index');
        });

        Schema::create('personal_calendar_holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('uid_hash', 64);
            $table->string('title', 160);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('workday');
            $table->unique(['user_id', 'uid_hash']);
            $table->index(['user_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_calendar_holidays');
        Schema::dropIfExists('personal_calendar_reminders');
    }
};
