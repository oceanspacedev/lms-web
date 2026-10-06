<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->json('request_fields')->default('[]');
            $table->json('request_attachments')->default('[]');
            $table->foreignId('request_reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('request_approver_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('document_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('pic_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('purpose')->default('new');
            $table->string('partner_name');
            $table->string('partner_pic')->nullable();
            $table->string('partner_contact')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('target_date')->nullable();
            $table->boolean('has_cost')->default(false);
            $table->decimal('amount', 18, 2)->nullable();
            $table->text('payment_terms')->nullable();
            $table->json('details')->default('{}');
            $table->json('attachments')->default('{}');
            $table->json('requirements')->default('{}');
            $table->json('history')->default('[]');
            $table->string('status')->default('draft')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('request_reviewer_id');
            $table->dropConstrainedForeignId('request_approver_id');
            $table->dropColumn(['request_fields', 'request_attachments']);
        });
    }
};
