<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Sales Returns: Idempotency Key to prevent duplicate refund processing
        Schema::table('sales_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_returns', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->unique()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            if (Schema::hasColumn('sales_returns', 'idempotency_key')) {
                $table->dropColumn('idempotency_key');
            }
        });
    }
};
