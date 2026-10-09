<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_templates', function (Blueprint $table): void {
            $table->json('overdue_days')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('reminder_templates', fn (Blueprint $table) => $table->dropColumn('overdue_days'));
    }
};
