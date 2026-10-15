<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table): void {
            $table->timestamp('status_changed_at')->nullable()->index();
        });

        DB::table('document_requests')->orderBy('id')->each(function (object $request): void {
            $history = json_decode($request->history ?? '[]', true) ?: [];
            $at = collect($history)->last()['at'] ?? $request->updated_at;
            if ($at !== null) {
                DB::table('document_requests')->where('id', $request->id)
                    ->update(['status_changed_at' => CarbonImmutable::parse($at)->setTimezone(config('app.timezone'))->toDateTimeString()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table): void {
            $table->dropIndex(['status_changed_at']);
            $table->dropColumn('status_changed_at');
        });
    }
};
