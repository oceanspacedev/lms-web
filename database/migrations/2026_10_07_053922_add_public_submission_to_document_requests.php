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
        Schema::table('document_types', function (Blueprint $table): void {
            $table->boolean('accept_public_requests')->default(false);
            $table->foreignId('request_pic_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('document_requests', function (Blueprint $table): void {
            $table->foreignId('requester_id')->nullable()->change();
            $table->string('requester_name')->nullable();
            $table->string('requester_phone', 20)->nullable();
            $table->string('requester_division', 100)->nullable();
            $table->text('request_reason')->nullable();
            $table->string('public_token', 64)->nullable()->unique();
        });
        Schema::create('document_request_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->string('event_key')->unique();
            $table->string('recipient_kind');
            $table->string('recipient_phone', 20)->nullable();
            $table->string('status')->default('pending')->index();
            $table->json('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_request_notifications');
        Schema::table('document_requests', function (Blueprint $table): void {
            $table->dropColumn(['requester_name', 'requester_phone', 'requester_division', 'request_reason', 'public_token']);
        });
        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('request_pic_id');
            $table->dropColumn('accept_public_requests');
        });
    }
};
