<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Link credit settlements to cashier register shift sessions
        Schema::table('credit_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_settlements', 'pos_session_id')) {
                $table->foreignId('pos_session_id')->nullable()->after('cashier_id')->constrained('pos_sessions')->nullOnDelete();
            }
        });

        // 2. Link sales return items to specific product variant (Size/Color EAV matrix)
        Schema::table('sales_return_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_return_items', 'product_variant_id')) {
                $table->foreignId('product_variant_id')->nullable()->after('product_option_id')->constrained('product_variants')->nullOnDelete();
            }
        });

        // 3. Track overpayment change amount on sales orders
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'change_amount')) {
                $table->decimal('change_amount', 14, 2)->default(0.00)->after('paid_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_settlements', function (Blueprint $table) {
            if (Schema::hasColumn('credit_settlements', 'pos_session_id')) {
                $table->dropForeign(['pos_session_id']);
                $table->dropColumn('pos_session_id');
            }
        });

        Schema::table('sales_return_items', function (Blueprint $table) {
            if (Schema::hasColumn('sales_return_items', 'product_variant_id')) {
                $table->dropForeign(['product_variant_id']);
                $table->dropColumn('product_variant_id');
            }
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'change_amount')) {
                $table->dropColumn('change_amount');
            }
        });
    }
};
