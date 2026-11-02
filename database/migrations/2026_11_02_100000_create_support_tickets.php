<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dukungan pelanggan: satu tiket = satu percakapan.
 *
 * Tiket boleh menempel ke objek tertentu (booking, rental, order) lewat morphs
 * supaya staf melihat konteks tanpa menebak, tetapi tetap bisa dibuat tanpa
 * kaitan apa pun (mis. pertanyaan akun).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('category', 30)->index();
            $table->string('subject', 160);
            $table->string('status', 20)->default('open')->index();
            $table->string('priority', 20)->default('normal');
            $table->nullableMorphs('related');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
