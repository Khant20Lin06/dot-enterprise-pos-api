<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Categories
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 2. Units of Measure (UOM)
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique(); // Piece, Can, Liter, Set, Box
            $table->string('description', 100)->nullable();
            $table->timestamps();
        });

        // 3. Suppliers Master
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('phone', 50);
            $table->string('contact_person', 100)->nullable();
            $table->text('address')->nullable();
            $table->decimal('outstanding_balance', 14, 2)->default(0.00);
            $table->timestamps();
        });

        // 4. Products Master
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->onDelete('restrict');
            $table->foreignId('uom_id')->constrained('units_of_measure')->onDelete('restrict');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');
            $table->string('name', 200)->index();
            $table->string('barcode', 100)->unique()->index();
            $table->string('oem_part_no', 100)->nullable()->index();
            $table->text('car_fitment')->nullable();
            $table->string('bin_location', 50)->nullable();
            $table->decimal('cost_price', 14, 2)->default(0.00);
            $table->decimal('sell_price', 14, 2)->default(0.00)->index();
            $table->decimal('wholesale_price', 14, 2)->nullable();
            $table->integer('stock')->default(0)->index(); // Master inventory count
            $table->integer('min_stock')->default(5);
            $table->string('image_url', 255)->nullable();
            $table->boolean('is_labor')->default(false); // For repair / labor fees
            $table->boolean('is_outsourced')->default(false);
            $table->timestamps();

            // Composite Index for ultra-fast POS search
            $table->index(['barcode', 'is_labor']);
        });

        // 5. Product Options (Variants: Sizes, Colors, Batches)
        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('name', 100); // e.g. "4 Liter Can", "1L Bottle"
            $table->string('barcode', 100)->nullable()->index();
            $table->string('sku', 100)->nullable();
            $table->decimal('price', 14, 2);
            $table->decimal('cost_price', 14, 2)->default(0.00);
            $table->integer('stock')->default(0);
            $table->string('batch_no', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
        });

        // 6. Price List Items (Volume Break Pricing Tiers)
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('min_qty')->default(1); // Volume break threshold (e.g. 5, 10, 20)
            $table->string('pricing_method', 30)->default('FIXED'); // FIXED, PERCENT_DISCOUNT, COST_PLUS_MARKUP
            $table->decimal('value', 14, 2);
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'min_qty']);
        });

        // 7. Stock Adjustments (Audit Trail for inventory discrepancies)
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->string('type', 50); // Cycle Count, Damage / Scrap, Found Stock, Physical Audit, Supplier Return
            $table->integer('prev_stock');
            $table->integer('adjusted_qty'); // +/- quantity
            $table->integer('new_stock');
            $table->text('reason')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('staff_users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('categories');
    }
};
