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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('document_number')->nullable()->index();
            $table->string('title');
            $table->string('counterparty')->nullable();
            $table->foreignId('pic_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
