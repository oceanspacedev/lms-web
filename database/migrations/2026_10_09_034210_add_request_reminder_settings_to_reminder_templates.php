<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_templates', function (Blueprint $table): void {
            $table->boolean('request_reminder_enabled')->default(true);
            $table->unsignedInteger('request_reminder_after_days')->default(2);
            $table->unsignedInteger('request_reminder_interval_days')->default(2);
            $table->unsignedInteger('request_reminder_max')->default(3);
            $table->text('request_reminder_body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reminder_templates', fn (Blueprint $table) => $table->dropColumn([
            'request_reminder_enabled', 'request_reminder_after_days', 'request_reminder_interval_days', 'request_reminder_max', 'request_reminder_body',
        ]));
    }
};
