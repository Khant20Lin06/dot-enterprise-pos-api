<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Sales Orders Master (Invoices)
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 50)->unique()->index(); // e.g. "INV-20261030-0001"
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('set null');
            $table->foreignId('cashier_id')->constrained('staff_users')->onDelete('restrict');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('labor_total', 14, 2)->default(0.00);
            $table->decimal('discount_amount', 14, 2)->default(0.00);
            $table->string('discount_note', 255)->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0.00);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('paid_amount', 14, 2);
            $table->decimal('due_amount', 14, 2)->default(0.00)->index(); // On credit
            $table->integer('points_earned')->default(0);
            $table->integer('points_redeemed')->default(0);
            $table->string('status', 40)->default('Completed')->index(); // Completed, Pending, Voided, Returned
            $table->string('idempotency_key', 100)->nullable()->unique()->index(); // Offline sync safeguard
            $table->timestamps();

            // Composite index for customer purchase history query
            $table->index(['customer_id', 'status', 'created_at']);
        });

        // 2. Sales Order Items
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->foreignId('product_option_id')->nullable()->constrained('product_options')->onDelete('set null');
            $table->string('name', 200);
            $table->integer('qty');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('cost_price', 14, 2)->default(0.00);
            $table->decimal('discount_amount', 14, 2)->default(0.00);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });

        // 3. Payment Splits (Multi-Tender Payments: Cash, KPay, Card, Due)
        Schema::create('payment_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('cascade');
            $table->string('method', 50); // Cash, KPay, WavePay, Card, Credit, Bank Transfer
            $table->decimal('amount', 14, 2);
            $table->string('reference_no', 100)->nullable(); // e.g. KPay Transaction ID
            $table->timestamps();
        });

        // 4. Customer Credit Settlements Ledger (Debt Repayment)
        Schema::create('credit_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 50)->unique()->index(); // e.g. "REC-20261030-0001"
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 50)->default('Cash');
            $table->text('note')->nullable();
            $table->foreignId('cashier_id')->constrained('staff_users')->onDelete('restrict');
            $table->timestamps();
        });

        // 5. Parked / Held Orders (Server-synchronized held carts)
        Schema::create('held_orders', function (Blueprint $table) {
            $table->id();
            $table->string('hold_id', 50)->unique()->index();
            $table->string('title', 150);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->foreignId('cashier_id')->constrained('staff_users')->onDelete('cascade');
            $table->json('cart_data'); // Complete serialized cart items and discounts
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_orders');
        Schema::dropIfExists('credit_settlements');
        Schema::dropIfExists('payment_splits');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
