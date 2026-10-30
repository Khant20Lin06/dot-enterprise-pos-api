<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Partial Goods Receipt Note (GRN) tracking on PO items
        if (! Schema::hasColumn('purchase_items', 'received_qty')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->integer('received_qty')->default(0)->after('qty');
            });
        }

        // 2. SoftDeletes for Master Data Entities (Enterprise Asset Recovery)
        if (! Schema::hasColumn('products', 'deleted_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('customers', 'deleted_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('suppliers', 'deleted_at')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('vehicles', 'deleted_at')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('received_qty');
        });
    }
};
