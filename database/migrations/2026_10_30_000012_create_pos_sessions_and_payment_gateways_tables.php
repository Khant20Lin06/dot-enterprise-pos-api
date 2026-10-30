<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. POS Sessions (Cash Register Shifts)
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_no', 50)->unique()->index(); // e.g. "SHIFT-20261030-0001"
            $table->foreignId('cashier_id')->constrained('staff_users')->onDelete('restrict');
            $table->string('terminal_id', 50)->index(); // e.g. "COUNTER-01"
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->onDelete('set null');
            $table->decimal('opening_float', 14, 2)->default(0.00);
            $table->string('status', 30)->default('open')->index(); // 'open', 'closed'
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable(); // Cashier blind count on close
            $table->decimal('expected_cash', 14, 2)->nullable(); // opening_float + cash_sales - cash_refunds
            $table->decimal('cash_variance', 14, 2)->nullable(); // counted_cash - expected_cash (Short/Over)
            $table->decimal('total_sales', 14, 2)->default(0.00);
            $table->decimal('total_refunds', 14, 2)->default(0.00);
            $table->decimal('cash_sales', 14, 2)->default(0.00);
            $table->decimal('kpay_sales', 14, 2)->default(0.00);
            $table->decimal('wave_sales', 14, 2)->default(0.00);
            $table->decimal('card_sales', 14, 2)->default(0.00);
            $table->decimal('credit_sales', 14, 2)->default(0.00);
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['cashier_id', 'status']);
            $table->index(['terminal_id', 'status']);
        });

        // 2. Link Sales Orders to POS Session
        if (! Schema::hasColumn('sales_orders', 'pos_session_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->foreignId('pos_session_id')->nullable()->after('cashier_id')->constrained('pos_sessions')->onDelete('set null');
            });
        }

        // 3. Link Sales Returns to POS Session
        if (! Schema::hasColumn('sales_returns', 'pos_session_id')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->foreignId('pos_session_id')->nullable()->after('cashier_id')->constrained('pos_sessions')->onDelete('set null');
            });
        }

        // 4. Enhance Payment Splits for Gateway Lifecycle
        if (! Schema::hasColumn('payment_splits', 'status')) {
            Schema::table('payment_splits', function (Blueprint $table) {
                $table->string('status', 30)->default('completed')->after('amount')->index();
                $table->string('gateway_provider', 50)->nullable()->after('status');
                $table->json('gateway_payload')->nullable()->after('reference_no');
            });
        }

        // 5. Payment Transactions (Dynamic QR intents, Webhooks & Gateway Lifecycle)
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no', 60)->unique()->index(); // e.g. "TXN-20261030-0001"
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->onDelete('set null');
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->onDelete('set null');
            $table->string('provider', 50)->index(); // 'kbzpay', 'wavepay', 'cbpay'
            $table->string('payment_method', 50)->default('QR'); // 'QR', 'Deeplink', 'Card'
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('MMK');
            $table->text('qr_payload')->nullable(); // MMQR EMV standard or gateway deep link string
            $table->string('status', 30)->default('pending')->index(); // 'pending', 'successful', 'failed', 'expired'
            $table->string('gateway_ref', 100)->nullable()->index(); // External transaction id / prepay_id
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');

        if (Schema::hasColumn('payment_splits', 'status')) {
            Schema::table('payment_splits', function (Blueprint $table) {
                $table->dropColumn(['status', 'gateway_provider', 'gateway_payload']);
            });
        }

        if (Schema::hasColumn('sales_returns', 'pos_session_id')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->dropForeign(['pos_session_id']);
                $table->dropColumn('pos_session_id');
            });
        }

        if (Schema::hasColumn('sales_orders', 'pos_session_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropForeign(['pos_session_id']);
                $table->dropColumn('pos_session_id');
            });
        }

        Schema::dropIfExists('pos_sessions');
    }
};
