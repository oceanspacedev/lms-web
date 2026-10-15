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
        Schema::create('whatsapp_test_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_event', 30);
            $table->string('recipient_phone', 30);
            $table->text('message');
            $table->string('status', 20)->default('pending')->index();
            $table->string('error_message')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_test_messages');
    }
};
