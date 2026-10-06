<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('document_document_tag');
        Schema::dropIfExists('document_tags');
        DB::table('permissions')->whereIn('name', ['ViewAny:DocumentTag', 'View:DocumentTag', 'Create:DocumentTag', 'Update:DocumentTag'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::create('document_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('document_document_tag', function (Blueprint $table): void {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_tag_id')->constrained()->restrictOnDelete();
            $table->primary(['document_id', 'document_tag_id']);
        });
    }
};
