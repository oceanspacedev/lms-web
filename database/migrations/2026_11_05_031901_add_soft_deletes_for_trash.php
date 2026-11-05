<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tabel yang baru mendapat soft delete. `documents` sudah punya `deleted_at`, jadi hanya mendapat `deleted_by`. */
    private const TABLES = ['companies', 'document_types', 'users', 'document_requests'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->softDeletes();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
        });

        foreach (array_reverse(self::TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('deleted_by');
                $table->dropSoftDeletes();
            });
        }
    }
};
