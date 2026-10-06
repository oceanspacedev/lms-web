<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_templates', function (Blueprint $table): void {
            $table->string('schedule_mode')->default('inherit');
            $table->unsignedInteger('start_before_days')->default(30);
            $table->unsignedInteger('interval_days')->default(7);
            $table->json('scheduled_days')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('reminder_templates', fn (Blueprint $table) => $table->dropColumn(['schedule_mode', 'start_before_days', 'interval_days', 'scheduled_days']));
    }
};
