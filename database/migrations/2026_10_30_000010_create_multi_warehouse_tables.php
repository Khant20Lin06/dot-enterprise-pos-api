<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Warehouses & Physical Branches Master
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->index(); // e.g. "WH-MAIN-01", "BR-HLEDAN-02"
            $table->string('name', 150);
            $table->string('type', 50)->default('Branch Counter'); // Central Warehouse, Branch Counter, Workshop Bay
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_central')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // 2. Warehouse Stock Balances (Composite Inventory per physical location)
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('product_option_id')->nullable()->constrained('product_options')->onDelete('cascade');
            $table->integer('stock_qty')->default(0)->index();
            $table->integer('min_alert_qty')->default(5);
            $table->string('bin_location', 100)->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id']);
        });

        // 3. Stock Transfer Orders Master
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 50)->unique()->index(); // e.g. "TRF-20261030-0001"
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->onDelete('restrict');
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->onDelete('restrict');
            $table->foreignId('requested_by')->constrained('staff_users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('staff_users')->onDelete('set null');
            $table->foreignId('received_by')->nullable()->constrained('staff_users')->onDelete('set null');
            $table->string('status', 40)->default('Draft')->index(); // Draft, In Transit, Received, Cancelled
            $table->text('notes')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['source_warehouse_id', 'status']);
            $table->index(['destination_warehouse_id', 'status']);
        });

        // 4. Stock Transfer Line Items
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->foreignId('product_option_id')->nullable()->constrained('product_options')->onDelete('set null');
            $table->integer('qty_requested');
            $table->integer('qty_dispatched')->default(0);
            $table->integer('qty_received')->default(0);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('warehouse_stocks');
        Schema::dropIfExists('warehouses');
    }
};
