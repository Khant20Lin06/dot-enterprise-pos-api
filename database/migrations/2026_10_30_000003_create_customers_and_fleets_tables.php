<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Corporate & Family Fleets
        Schema::create('fleets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('type', 50)->default('Commercial Logistics'); // Family VIP, Commercial Logistics, Corporate / Executive, Taxi / Rental
            $table->decimal('credit_limit', 14, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 2. Customers Master
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('phone', 50)->index();
            $table->string('email', 191)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('fleet_id')->nullable()->constrained('fleets')->onDelete('set null');
            $table->string('tier', 30)->default('Standard'); // Standard, Silver, VIP, Fleet
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->onDelete('set null');
            $table->decimal('due_amount', 14, 2)->default(0.00)->index(); // Total outstanding debt
            $table->decimal('credit_limit', 14, 2)->default(0.00);
            $table->integer('loyalty_points')->default(0);
            $table->text('notes')->nullable();
            $table->date('last_service_date')->nullable();
            $table->date('next_service_due_date')->nullable();
            $table->timestamps();
        });

        // 3. Vehicles Registry (Dedicated Entity solving the loose string audit gap)
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_number', 50)->unique()->index(); // e.g. "3I/4411"
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('fleet_id')->nullable()->constrained('fleets')->onDelete('set null');
            $table->string('make', 100); // e.g. Toyota
            $table->string('model', 100); // e.g. Alphard VIP
            $table->integer('year')->nullable();
            $table->string('vin', 100)->nullable()->index(); // Chassis No
            $table->string('engine_no', 100)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('transmission', 50)->default('Automatic'); // Automatic, Manual, CVT
            $table->string('fuel_type', 50)->default('Petrol'); // Petrol, Diesel, Hybrid, EV
            $table->integer('current_mileage')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('fleets');
    }
};
