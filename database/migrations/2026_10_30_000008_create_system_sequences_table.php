<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 50); // e.g. 'INV', 'REC', 'JC'
            $table->string('date_key', 20); // e.g. '20261030'
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'date_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_sequences');
    }
};
