<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versi yang sudah ada seluruhnya tersimpan di S3, jadi default kolom untuk data lama adalah s3.
     */
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->string('disk', 50)->default('s3')->after('version_number');
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
