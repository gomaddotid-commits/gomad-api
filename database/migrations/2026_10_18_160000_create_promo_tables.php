<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promos')) {
            Schema::create('promos', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                // platform = dibuat admin GoMad, business = dibuat agency/warung
                $table->string('owner_type', 20)->default('platform');
                $table->unsignedBigInteger('business_id')->nullable();
                // PR1: siapa yang menanggung diskon
                $table->string('cost_bearer', 20);
                $table->string('discount_type', 20);
                $table->decimal('discount_value', 18, 2);
                $table->decimal('max_discount', 18, 2)->nullable();
                $table->decimal('min_spend', 18, 2)->default(0);
                $table->string('applies_to', 20)->default('all');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->unsignedInteger('usage_limit')->nullable();
                $table->unsignedInteger('used_count')->default(0);
                $table->unsignedInteger('per_user_limit')->default(1);
                $table->string('status', 20)->default('active');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['status', 'applies_to'], 'promos_status_applies_index');
                $table->foreign('business_id', 'promos_business_foreign')->references('id')->on('businesses')->nullOnDelete();
                $table->foreign('created_by', 'promos_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('promo_redemptions')) {
            Schema::create('promo_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('promo_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('business_id')->nullable();
                $table->string('reference_type', 30);
                $table->unsignedBigInteger('reference_id');
                $table->decimal('discount_amount', 18, 2);
                $table->string('cost_bearer', 20);
                $table->timestamps();

                $table->unique(['promo_id', 'reference_type', 'reference_id'], 'promo_redemptions_reference_unique');
                $table->index(['user_id', 'promo_id'], 'promo_redemptions_user_promo_index');
                $table->index(['business_id', 'created_at'], 'promo_redemptions_business_index');
                $table->foreign('promo_id', 'promo_redemptions_promo_foreign')->references('id')->on('promos')->cascadeOnDelete();
                $table->foreign('user_id', 'promo_redemptions_user_foreign')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('business_id', 'promo_redemptions_business_foreign')->references('id')->on('businesses')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('bookings', 'promo_id')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->unsignedBigInteger('promo_id')->nullable()->after('discount_amount');
                $table->string('promo_code', 40)->nullable()->after('promo_id');
                $table->string('discount_bearer', 20)->nullable()->after('promo_code');
                $table->foreign('promo_id', 'bookings_promo_foreign')->references('id')->on('promos')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('rentals', 'promo_id')) {
            Schema::table('rentals', function (Blueprint $table): void {
                $table->decimal('discount_amount', 18, 2)->default(0)->after('ppn_amount');
                $table->unsignedBigInteger('promo_id')->nullable()->after('discount_amount');
                $table->string('promo_code', 40)->nullable()->after('promo_id');
                $table->string('discount_bearer', 20)->nullable()->after('promo_code');
                $table->foreign('promo_id', 'rentals_promo_foreign')->references('id')->on('promos')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rentals', 'promo_id')) {
            Schema::table('rentals', function (Blueprint $table): void {
                $table->dropForeign('rentals_promo_foreign');
                $table->dropColumn(['discount_amount', 'promo_id', 'promo_code', 'discount_bearer']);
            });
        }

        if (Schema::hasColumn('bookings', 'promo_id')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->dropForeign('bookings_promo_foreign');
                $table->dropColumn(['promo_id', 'promo_code', 'discount_bearer']);
            });
        }

        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promos');
    }
};
