<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['reminder_logs', 'document_request_notifications'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('resent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('resent_by');
                $table->dropColumn('resent_at');
            });
        }
    }
};
