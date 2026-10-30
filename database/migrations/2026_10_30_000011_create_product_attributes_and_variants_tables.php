<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add has_variants flag to products master
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('has_variants')->default(false)->after('is_outsourced');
        });

        // 2. Attributes Master (e.g. Size, Color, Viscosity, Material)
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->index(); // e.g. "SIZE", "COLOR"
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // 3. Attribute Values (e.g. "XL", "M", "Red", "Blue")
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->onDelete('cascade');
            $table->string('value', 100);
            $table->string('color_hex', 20)->nullable(); // e.g. "#EF4444"
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'value']);
        });

        // 4. Product Variants (Matrix combinations with independent SKU, Barcode, Price, and Stock)
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('sku', 100)->unique()->index();
            $table->string('barcode', 100)->nullable()->unique()->index();
            $table->decimal('price', 14, 2);
            $table->decimal('cost_price', 14, 2)->default(0.00);
            $table->integer('stock')->default(0);
            $table->string('image_url', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        // 5. Variant Attribute Values Pivot (Linking Variant to exact Size & Color)
        Schema::create('variant_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->onDelete('cascade');
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['product_variant_id', 'attribute_value_id']);
        });

        // 6. Support Product Variant in Sales Order Items
        if (! Schema::hasColumn('sales_order_items', 'product_variant_id')) {
            Schema::table('sales_order_items', function (Blueprint $table) {
                $table->foreignId('product_variant_id')->nullable()->after('product_option_id')->constrained('product_variants')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_order_items', 'product_variant_id')) {
            Schema::table('sales_order_items', function (Blueprint $table) {
                $table->dropForeign(['product_variant_id']);
                $table->dropColumn('product_variant_id');
            });
        }

        Schema::dropIfExists('variant_attribute_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('has_variants');
        });
    }
};
