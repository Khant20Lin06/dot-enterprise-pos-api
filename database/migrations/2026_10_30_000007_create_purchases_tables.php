<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Purchase Invoices (PO)
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('po_no', 50)->unique()->index(); // e.g. "PO-20261030-001"
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('restrict');
            $table->decimal('total_amount', 14, 2);
            $table->string('status', 40)->default('Received'); // Draft, Approved, Received, Cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 2. Purchase Items (CRITICAL FK to Product for automated stock replenishment)
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->integer('qty');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });

        // 3. Purchase Returns
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 50)->unique()->index();
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('restrict');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->integer('qty');
            $table->decimal('amount', 14, 2);
            $table->text('reason')->nullable();
            $table->string('status', 40)->default('Claimed'); // Claimed, Dispatched, Credited, Replaced
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
