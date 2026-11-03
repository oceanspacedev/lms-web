<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode login WhatsApp lama digantikan tabel whatsapp_otps. Isinya hanya kode sekali pakai, jadi aman dibuang.
     */
    public function up(): void
    {
        Schema::dropIfExists('login_codes');
    }

    public function down(): void
    {
        Schema::create('login_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 30);
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('error_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['phone', 'created_at']);
        });
    }
};
