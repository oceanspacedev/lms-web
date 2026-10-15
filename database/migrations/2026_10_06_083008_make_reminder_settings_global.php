<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminder_templates', function (Blueprint $table): void {
            $table->string('global_key')->nullable()->unique();
        });
        $setting = DB::table('reminder_templates')->orderByDesc('is_active')->orderByDesc('updated_at')->orderBy('id')->first();
        if ($setting) {
            DB::table('reminder_templates')->where('id', $setting->id)->update([
                'global_key' => 'global',
                'name' => 'Pengingat Semua Dokumen',
                'schedule_mode' => $setting->schedule_mode === 'inherit' ? 'interval' : $setting->schedule_mode,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('reminder_templates', function (Blueprint $table): void {
            $table->dropColumn('global_key');
        });
    }
};
