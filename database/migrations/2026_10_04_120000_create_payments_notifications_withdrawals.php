<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 40);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('IDR');
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->string('provider_order_id', 64)->unique();
            $table->string('provider_transaction_id', 100)->nullable()->unique();
            $table->string('status', 20)->default('creating')->index();
            $table->string('snap_token', 255)->nullable();
            $table->text('redirect_url')->nullable();
            $table->string('last_provider_status', 40)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->char('event_key', 64);
            $table->foreignId('payment_intent_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('processing_status', 20);
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['provider', 'event_key']);
        });

        Schema::create('withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('bank_code', 30);
            $table->text('bank_account_number');
            $table->text('bank_account_name');
            $table->decimal('amount', 18, 2);
            $table->decimal('fee_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2);
            $table->string('idempotency_key', 128)->unique();
            $table->char('request_hash', 64);
            $table->foreignId('ledger_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignId('payout_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->string('provider_reference', 100)->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('withdrawal_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->char('event_key', 64);
            $table->foreignId('withdrawal_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('processing_status', 20);
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['provider', 'event_key']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->text('push_token');
            $table->char('push_token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('withdrawal_webhook_events');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_intents');
    }
};
