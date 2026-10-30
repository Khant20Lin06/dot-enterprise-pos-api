<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Mechanics Master
        Schema::create('mechanics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 50);
            $table->string('specialization', 100); // Engine & Transmission, Auto Electrical, Suspension
            $table->decimal('commission_rate', 5, 2)->default(15.00); // 15% of labor fee
            $table->string('status', 30)->default('Active'); // Active, On Leave, Inactive
            $table->string('avatar', 255)->nullable();
            $table->timestamps();
        });

        // 2. Work Orders (Job Cards)
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('job_card_no', 50)->unique()->index(); // e.g. "JC-20261030-001"
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('vehicle_id')->constrained('vehicles')->onDelete('restrict');
            $table->foreignId('fleet_id')->nullable()->constrained('fleets')->onDelete('set null');
            $table->foreignId('assigned_mechanic_id')->nullable()->constrained('mechanics')->onDelete('set null');
            $table->string('bay', 50)->default('Bay 1');
            $table->string('priority', 20)->default('Normal'); // Normal, High, Urgent
            $table->string('status', 40)->default('Intake / Estimate')->index(); 
            // Intake / Estimate, In Progress, Waiting for Parts, QC & Ready, Completed / Invoiced, Cancelled
            $table->text('complaint'); // Customer reported issue
            $table->integer('mileage_in')->default(0);
            $table->decimal('labor_fee', 14, 2)->default(0.00);
            $table->decimal('parts_total', 14, 2)->default(0.00);
            $table->decimal('total_amount', 14, 2)->default(0.00);
            $table->unsignedBigInteger('sales_order_id')->nullable()->index(); // Linked when invoiced
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
        });

        // 3. Work Order Items (CRITICAL FK to Product for automated inventory deduction)
        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->string('name', 200);
            $table->integer('qty')->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('cost_price', 14, 2)->default(0.00);
            $table->decimal('subtotal', 14, 2);
            $table->boolean('is_outsourced')->default(false);
            $table->timestamps();
        });

        // 4. Outsourced Parts (CRITICAL FK to Suppliers)
        Schema::create('outsourced_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->onDelete('cascade');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->onDelete('set null');
            $table->string('part_name', 200);
            $table->decimal('cost_price', 14, 2)->default(0.00); // Vendor purchase cost
            $table->decimal('bill_price', 14, 2)->default(0.00); // Customer billed price
            $table->string('status', 40)->default('Ordered'); // Ordered, In Transit, Received & Fitted, Cancelled
            $table->string('payment_status', 30)->default('Unpaid'); // Unpaid, Paid
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 5. Mechanic Commissions Ledger
        Schema::create('mechanic_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mechanic_id')->constrained('mechanics')->onDelete('restrict');
            $table->foreignId('work_order_id')->constrained('work_orders')->onDelete('cascade');
            $table->decimal('labor_amount', 14, 2);
            $table->decimal('commission_rate', 5, 2); // e.g. 20.00%
            $table->decimal('earned_amount', 14, 2); // Calculated cut
            $table->string('status', 30)->default('Pending')->index(); // Pending, Paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_commissions');
        Schema::dropIfExists('outsourced_parts');
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('mechanics');
    }
};
