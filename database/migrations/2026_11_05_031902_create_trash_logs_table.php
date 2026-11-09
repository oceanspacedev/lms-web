<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Riwayat hapus, pulihkan, dan hapus permanen. `subject_id` tanpa foreign key karena datanya bisa sudah tidak ada. */
    public function up(): void
    {
        Schema::create('trash_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label');
            $table->string('action');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trash_logs');
    }
};
