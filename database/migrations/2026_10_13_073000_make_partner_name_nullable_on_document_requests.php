<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pihak lawan bersifat opsional pada form publik: tidak semua jenis dokumen punya mitra. */
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table): void {
            $table->string('partner_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('document_requests')->whereNull('partner_name')->update(['partner_name' => '']);

        Schema::table('document_requests', function (Blueprint $table): void {
            $table->string('partner_name')->nullable(false)->change();
        });
    }
};
