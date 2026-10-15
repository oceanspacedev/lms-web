<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX document_versions_one_current_per_document ON document_versions (document_id) WHERE is_current = true');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('DROP INDEX document_versions_one_current_per_document');
        }
    }
};
