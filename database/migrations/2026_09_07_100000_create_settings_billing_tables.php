<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('card_brand')->nullable()->after('plan');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->unsignedTinyInteger('card_exp_month')->nullable()->after('card_last4');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');
            $table->timestamp('current_period_end')->nullable()->after('card_exp_year');
            $table->timestamp('subscription_ends_at')->nullable()->after('current_period_end');
        });

        Schema::create('payment_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id');
            $table->string('failure_reason');
            $table->timestamp('declined_at');
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'resolved_at']);
            $table->unique('stripe_invoice_id');
        });

        Schema::create('billing_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->string('invoice_number')->unique();
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('gbp');
            $table->string('status');
            $table->timestamp('issued_at');
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'issued_at']);
        });

        Schema::create('billing_counters', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('value')->default(0);
        });

        DB::table('billing_counters')->insert([
            'name' => 'invoice',
            'value' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_counters');
        Schema::dropIfExists('billing_receipts');
        Schema::dropIfExists('payment_failures');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'card_brand',
                'card_last4',
                'card_exp_month',
                'card_exp_year',
                'current_period_end',
                'subscription_ends_at',
            ]);
        });
    }
};
