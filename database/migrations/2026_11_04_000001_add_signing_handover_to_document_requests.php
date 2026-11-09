<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('draft_path')->nullable();
            $table->string('draft_name')->nullable();
            $table->timestamp('draft_issued_at')->nullable();
            $table->string('signed_path')->nullable();
            $table->string('signed_name')->nullable();
            $table->timestamp('signed_uploaded_at')->nullable();
            $table->timestamp('physical_received_at')->nullable();
        });

        Schema::create('document_request_late_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_request_late_reasons');

        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropColumn(['draft_path', 'draft_name', 'draft_issued_at', 'signed_path', 'signed_name', 'signed_uploaded_at', 'physical_received_at']);
        });
    }
};
