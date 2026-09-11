<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sessions_required');
            $table->string('reward');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('loyalty_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_package_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('stamps_used')->default(0);
            $table->unsignedSmallInteger('cycles_completed')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_loyalty_reward')->default(false)->after('deposit_at_booking');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('is_loyalty_reward');
        });

        Schema::dropIfExists('loyalty_enrolments');
        Schema::dropIfExists('loyalty_packages');
    }
};
