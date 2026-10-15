<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);
            $table->timestamps();
            $table->index(['document_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_activities');
    }
};
