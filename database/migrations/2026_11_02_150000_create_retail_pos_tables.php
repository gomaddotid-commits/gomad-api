<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kasir warung (POS) — penjualan langsung ke pembeli yang datang.
 *
 * Ini **bukan** `orders`: `orders` adalah pesanan warung ke grosir (B2B, lewat
 * platform, lewat Midtrans). Penjualan kasir terjadi di meja warung, uang tunai
 * berpindah tangan di sana, dan platform tidak memegang uangnya.
 *
 * Karena itu tabel ini berdiri sendiri dan **tidak** memposting ke ledger:
 * `BUSINESS.AVAILABLE:<id>` berarti "uang yang dipegang platform dan bisa
 * dicairkan". Menambahkan uang tunai kasir ke sana akan membuat pemilik bisa
 * mencairkan uang yang tidak pernah diterima platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | Barang yang bisa dijual kasir.
        |
        | Sumbernya pesanan yang sudah **diterima** warung: saat barang datang,
        | produk itu otomatis terdaftar dengan harga beli terakhir. Penjaga tidak
        | pernah mengarang harga; pemilik yang menyesuaikan harga jual.
        */
        Schema::create('retail_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 18, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'product_id']);
        });

        Schema::create('retail_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('sold_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->string('payment_method', 20)->default('cash');
            $table->decimal('total_amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->nullable();
            $table->decimal('change_amount', 18, 2)->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'sold_by', 'created_at']);
        });

        /*
        | Baris penjualan menyimpan **salinan** nama, satuan, dan harga.
        |
        | Harga di `retail_listings` bisa berubah besok; nota yang sudah tercetak
        | tidak boleh ikut berubah. `product_id` tetap disimpan supaya laporan bisa
        | mengelompokkan per produk.
        */
        Schema::create('retail_sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retail_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 160);
            $table->string('unit', 30)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 18, 2);
            $table->decimal('subtotal', 18, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_sale_items');
        Schema::dropIfExists('retail_sales');
        Schema::dropIfExists('retail_listings');
    }
};
