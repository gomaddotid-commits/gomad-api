<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('file_path', 255);
            $table->string('original_name', 160)->nullable();
            $table->string('status', 20)->default('uploaded')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });

        Schema::table('rentals', function (Blueprint $table): void {
            $table->timestamp('documents_released_at')->nullable()->after('started_at');
            $table->timestamp('documents_revoked_at')->nullable()->after('documents_released_at');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table): void {
            $table->dropColumn(['documents_released_at', 'documents_revoked_at']);
        });

        Schema::dropIfExists('rental_documents');
    }
};
