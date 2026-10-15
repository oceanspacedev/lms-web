<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['departments', 'document_tags', 'reminder_templates'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->string('name')->unique();
                $table->boolean('is_active')->default(true);
                if ($tableName === 'reminder_templates') {
                    $table->text('body');
                }
                $table->timestamps();
            });
        }
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('documents', fn (Blueprint $table) => $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('document_types', fn (Blueprint $table) => $table->foreignId('reminder_template_id')->nullable()->constrained()->restrictOnDelete());
        Schema::create('document_document_tag', function (Blueprint $table): void {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_tag_id')->constrained()->restrictOnDelete();
            $table->primary(['document_id', 'document_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_document_tag');
        Schema::table('document_types', fn (Blueprint $table) => $table->dropConstrainedForeignId('reminder_template_id'));
        Schema::table('documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('department_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('department_id'));
        foreach (['reminder_templates', 'document_tags', 'departments'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
