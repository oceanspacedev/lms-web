<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table): void {
            $table->integer('offset_days')->change();
        });
    }

    public function down(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table): void {
            $table->unsignedInteger('offset_days')->change();
        });
    }
};
