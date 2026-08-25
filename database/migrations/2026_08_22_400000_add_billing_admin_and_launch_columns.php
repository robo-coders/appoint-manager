<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('subscription_status')->default('trial');
            $table->string('plan')->nullable();
            $table->timestamp('dunning_started_at')->nullable();
            $table->unsignedTinyInteger('dunning_emails_sent')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->boolean('is_comped')->default(false);
            $table->boolean('booking_page_live')->default(true);
            $table->string('preview_token')->nullable()->unique();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('feature_flags')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false);
        });

        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('target_tenant_id')->nullable()->index();
            $table->unsignedBigInteger('target_user_id')->nullable()->index();
            $table->string('action');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_failures', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('event_id')->nullable()->index();
            $table->string('type')->nullable();
            $table->text('message');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_failures');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('billing_events');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'subscription_status',
                'plan',
                'dunning_started_at',
                'dunning_emails_sent',
                'paused_at',
                'cancelled_at',
                'cancellation_reason',
                'is_comped',
                'booking_page_live',
                'preview_token',
                'last_activity_at',
                'feature_flags',
            ]);
        });
    }
};
