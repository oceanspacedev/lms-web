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
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('department_id'));
        Schema::table('documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('department_id'));
        Schema::dropIfExists('departments');
        DB::table('permissions')->whereIn('name', ['ViewAny:Department', 'View:Department', 'Create:Department', 'Update:Department'])->delete();
        DB::table('reminder_templates')->orderBy('id')->each(function (object $template): void {
            if (str_contains($template->body, '{departemen}')) {
                DB::table('reminder_templates')->where('id', $template->id)->update(['body' => str_replace('{departemen}', '-', $template->body)]);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('documents', fn (Blueprint $table) => $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete());
    }
};
