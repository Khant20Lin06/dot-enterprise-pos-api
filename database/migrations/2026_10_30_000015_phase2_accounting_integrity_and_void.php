<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Credit Settlements: Track exact cash tendered and returned change
        Schema::table('credit_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_settlements', 'tendered_amount')) {
                $table->decimal('tendered_amount', 14, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('credit_settlements', 'change_amount')) {
                $table->decimal('change_amount', 14, 2)->default(0.00)->after('tendered_amount');
            }
        });

        // 2. Sales Orders: Enterprise Void Order tracking
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('sales_orders', 'void_reason')) {
                $table->string('void_reason', 255)->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('sales_orders', 'voided_by')) {
                $table->foreignId('voided_by')->nullable()->after('void_reason')->constrained('staff_users')->nullOnDelete();
            }
        });

        // 3. Held Orders: Scope parked carts to active POS cashier shift session
        Schema::table('held_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('held_orders', 'pos_session_id')) {
                $table->foreignId('pos_session_id')->nullable()->after('cashier_id')->constrained('pos_sessions')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('held_orders', function (Blueprint $table) {
            if (Schema::hasColumn('held_orders', 'pos_session_id')) {
                $table->dropForeign(['pos_session_id']);
                $table->dropColumn('pos_session_id');
            }
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'voided_by')) {
                $table->dropForeign(['voided_by']);
                $table->dropColumn(['voided_at', 'void_reason', 'voided_by']);
            }
        });

        Schema::table('credit_settlements', function (Blueprint $table) {
            if (Schema::hasColumn('credit_settlements', 'tendered_amount')) {
                $table->dropColumn(['tendered_amount', 'change_amount']);
            }
        });
    }
};
