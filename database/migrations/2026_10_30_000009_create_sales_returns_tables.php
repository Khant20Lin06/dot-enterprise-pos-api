<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Sales Returns Master
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 50)->unique()->index(); // e.g. "RET-20261030-0001"
            $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('restrict');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('cashier_id')->constrained('staff_users')->onDelete('restrict');
            $table->decimal('total_refund_amount', 14, 2);
            $table->string('refund_method', 50)->default('Cash'); // Cash, Credit Note, KBZPay, Card Reversal, Credit Adjusted
            $table->string('refund_reference', 100)->nullable();
            $table->string('reason', 255);
            $table->string('status', 40)->default('Restocked')->index(); // Pending Inspection, Restocked, Refunded, Credit Adjusted, Rejected
            $table->text('notes')->nullable();
            $table->integer('points_deducted')->default(0);
            $table->timestamps();

            $table->index(['sales_order_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });

        // 2. Sales Return Line Items
        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->onDelete('cascade');
            $table->foreignId('sales_order_item_id')->constrained('sales_order_items')->onDelete('restrict');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->foreignId('product_option_id')->nullable()->constrained('product_options')->onDelete('set null');
            $table->integer('qty');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('refund_subtotal', 14, 2);
            $table->string('item_condition', 40)->default('Sellable'); // Sellable, Damaged, Defective, Scrap
            $table->boolean('restocked')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'item_condition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }
};
