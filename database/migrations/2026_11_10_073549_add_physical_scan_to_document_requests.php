<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scan dokumen fisik dari tim legal (tahap Scanning). Berbeda dari soft file bertanda tangan (`signed_*`) yang diunggah pengaju:
     * scan ini bukti dokumen asli sudah kembali ke legal, menjadi file arsip, dan bisa diunduh pengaju setelah selesai.
     */
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('scan_path')->nullable();
            $table->string('scan_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropColumn(['scan_path', 'scan_name']);
        });
    }
};
