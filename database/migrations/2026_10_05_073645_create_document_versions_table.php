<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('file_path', 1024);
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->boolean('is_current')->default(true);
            $table->text('change_note')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'version_number']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('document_versions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('document_versions');
    }
};
