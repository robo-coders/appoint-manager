<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('platform_fee_bps')->default(0);
            $table->char('country', 2)->default('GB');
            $table->json('stripe_requirements')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->timestamp('deposit_paid_at')->nullable();
            $table->timestamp('reminder_cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_payment_intent_id',
                'deposit_paid_at',
                'reminder_cancelled_at',
            ]);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['platform_fee_bps', 'country', 'stripe_requirements']);
        });
    }
};
