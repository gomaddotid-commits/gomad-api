<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku', 40)->unique();
            $table->string('name', 160);
            $table->string('category', 80)->nullable();
            $table->string('unit', 30);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'category']);
        });

        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 18, 2);
            $table->unsignedInteger('min_order_quantity')->default(1);
            $table->unsignedInteger('stock_available')->default(0);
            $table->unsignedInteger('stock_reserved')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'business_id']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('member_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('daily_amount_limit', 18, 2)->default(0);
            $table->decimal('per_order_limit', 18, 2)->default(0);
            $table->decimal('requires_approval_above', 18, 2)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'user_id']);
            $table->index(['user_id', 'business_id']);
        });

        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 20)->default('scheduled')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'user_id', 'status']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('wholesaler_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending_approval')->index();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('platform_fee', 18, 2);
            $table->decimal('ppn_amount', 18, 2);
            $table->decimal('total_amount', 18, 2);
            $table->string('delivery_method', 30);
            $table->text('delivery_address')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->char('request_hash', 64);
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->restrictOnDelete();
            $table->foreignId('received_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_by', 'created_at']);
            $table->index(['wholesaler_id', 'status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_price_id')->constrained()->restrictOnDelete();
            $table->string('sku', 40);
            $table->string('product_name', 160);
            $table->string('unit', 30);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 18, 2);
            $table->decimal('subtotal', 18, 2);
            $table->timestamps();
            $table->unique(['order_id', 'product_id']);
        });

        Schema::create('order_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20)->default('pending')->index();
            $table->string('reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'decision']);
        });

        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->after('business_id')
                ->constrained('orders')->restrictOnDelete();
            $table->index(['order_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'purpose']);
            $table->dropConstrainedForeignId('order_id');
        });
        Schema::dropIfExists('order_approvals');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('member_limits');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
    }
};
