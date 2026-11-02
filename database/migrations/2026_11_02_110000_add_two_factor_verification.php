<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verifikasi dua langkah berbasis kode sekali pakai.
 *
 * Kode dikirim ke email (kanal yang sudah ada), disimpan sebagai HMAC-SHA256 —
 * bukan teks biasa — dengan masa berlaku 10 menit dan maksimal 5 percobaan.
 * Token pada tabel ini hanya dipakai klien API untuk menukar kode menjadi sesi;
 * token disimpan sebagai hash juga supaya bocornya database tidak memberi akses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('two_factor_enabled_at')->nullable()->after('email_verified_at');
        });

        Schema::create('two_factor_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('channel', 20)->default('email');
            $table->string('code_hash', 64);
            $table->string('token_hash', 64)->nullable()->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_challenges');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_enabled_at');
        });
    }
};
