<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Multi-UOM / Packaging Units Conversion Table
        Schema::create('product_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('uom_id')->constrained('units_of_measure')->onDelete('restrict');
            $table->string('unit_name', 100); // e.g. "Box of 12", "Drum 200L", "Carton 24"
            $table->decimal('conversion_factor', 10, 4); // Base units per packaging unit (e.g. 12.0000, 200.0000)
            $table->string('barcode', 100)->nullable()->unique()->index();
            $table->string('sku', 100)->nullable()->index();
            $table->decimal('cost_price', 14, 2)->nullable();
            $table->decimal('sell_price', 14, 2);
            $table->boolean('is_purchase_default')->default(false);
            $table->boolean('is_sale_default')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'uom_id']);
        });

        // 2. Automotive Vehicle Fitment & Compatibility Matrix Table
        Schema::create('product_vehicle_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('make', 100)->index(); // Toyota, Honda, Mazda, etc.
            $table->string('model', 100)->index(); // Corolla, Vios, Hilux, Fit, etc.
            $table->integer('year_from')->nullable()->index();
            $table->integer('year_to')->nullable()->index();
            $table->string('engine_code', 100)->nullable()->index(); // 1NZ-FE, 2ZR-FE, etc.
            $table->string('chassis_code', 100)->nullable();
            $table->string('position', 100)->nullable(); // Front, Rear, Left, Right, All
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['make', 'model', 'year_from', 'year_to']);
        });

        // 3. Enhance sales_order_items with UOM tracking
        Schema::table('sales_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_order_items', 'uom_conversion_id')) {
                $table->foreignId('uom_conversion_id')->nullable()->after('product_variant_id')->constrained('product_uom_conversions')->nullOnDelete();
                $table->foreignId('uom_id')->nullable()->after('uom_conversion_id')->constrained('units_of_measure')->nullOnDelete();
                $table->decimal('conversion_factor', 10, 4)->default(1.0000)->after('uom_id');
                $table->decimal('packaging_qty', 10, 2)->nullable()->after('conversion_factor');
            }
        });

        // 4. Enhance work_order_items with UOM tracking
        Schema::table('work_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('work_order_items', 'uom_conversion_id')) {
                $table->foreignId('uom_conversion_id')->nullable()->after('product_id')->constrained('product_uom_conversions')->nullOnDelete();
                $table->foreignId('uom_id')->nullable()->after('uom_conversion_id')->constrained('units_of_measure')->nullOnDelete();
                $table->decimal('conversion_factor', 10, 4)->default(1.0000)->after('uom_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('work_order_items', 'uom_conversion_id')) {
                $table->dropForeign(['uom_conversion_id']);
                $table->dropForeign(['uom_id']);
                $table->dropColumn(['uom_conversion_id', 'uom_id', 'conversion_factor']);
            }
        });

        Schema::table('sales_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('sales_order_items', 'uom_conversion_id')) {
                $table->dropForeign(['uom_conversion_id']);
                $table->dropForeign(['uom_id']);
                $table->dropColumn(['uom_conversion_id', 'uom_id', 'conversion_factor', 'packaging_qty']);
            }
        });

        Schema::dropIfExists('product_vehicle_fitments');
        Schema::dropIfExists('product_uom_conversions');
    }
};
