<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Dynamic Multi-Tier Price Lists
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('type', 50)->default('RETAIL'); // RETAIL, WHOLESALE, FLEET, PROMOTIONAL
            $table->string('currency', 10)->default('MMK');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // 2. Promotions & Voucher Engine
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->index();
            $table->string('title', 150);
            $table->string('type', 20)->default('percent'); // percent, fixed
            $table->decimal('value', 12, 2);
            $table->decimal('min_spend', 14, 2)->default(0.00);
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('usage_limit')->nullable();
            $table->integer('times_used')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('target_tier', 30)->default('All'); // All, Standard, Silver, VIP, Fleet
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('price_lists');
    }
};
