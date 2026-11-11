<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
        });

        Schema::table('time_entries', function (Blueprint $table): void {
            $table->text('markdown_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropColumn('markdown_notes');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['start_date', 'due_date']);
        });
    }
};
