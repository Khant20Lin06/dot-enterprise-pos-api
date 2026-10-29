<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Roles Table (Granular RBAC)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->json('permissions')->nullable();
            $table->string('badge_color', 20)->default('#0284c7');
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // 2. Staff Users Table (Quick PIN login + Password)
        Schema::create('staff_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 191)->unique();
            $table->string('phone', 50)->nullable();
            $table->foreignId('role_id')->constrained('roles')->onDelete('restrict');
            $table->string('pin_hash', 255); // 4-digit or 6-digit hashed PIN
            $table->string('password', 255)->nullable();
            $table->string('status', 30)->default('Active'); // Active, Suspended, On Leave
            $table->string('workstation', 100)->nullable();
            $table->string('avatar', 255)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // 3. Enterprise Audit Logs (Immutable Security Trail)
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('staff_users')->onDelete('set null');
            $table->string('module', 50)->index(); // POS, INVENTORY, WORKSHOP, etc.
            $table->string('action', 50)->index(); // CREATE, UPDATE, DELETE, VOID, OVERRIDE
            $table->string('severity', 20)->default('INFO'); // INFO, WARNING, CRITICAL, SECURITY
            $table->string('resource_type', 100)->nullable();
            $table->string('resource_id', 100)->nullable();
            $table->text('description');
            $table->json('diffs')->nullable(); // Before and after state values
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('staff_users');
        Schema::dropIfExists('roles');
    }
};
