<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('account_class', 20);
            $table->string('purpose', 50);
            $table->decimal('balance_cached', 18, 2)->default(0);
            $table->char('currency', 3)->default('IDR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 50);
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('posted');
            $table->text('description')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
            $table->index(['category', 'occurred_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('direction', 10);
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['transaction_id', 'account_id', 'direction']);
            $table->index(['account_id', 'id']);
        });

        Schema::create('wallet_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->string('reason', 50);
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'reason']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_holds');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
