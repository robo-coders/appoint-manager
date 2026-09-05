<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty packages. Opt-in, off by default, and off means invisible.
 *
 * Two tables and one column, and the shape of each is a decision:
 *
 * **`loyalty_packages` is plural even though v1 creates one.** A salon defines
 * one package — five sessions, next one free — and the settings screen only
 * offers one. A `tenants.loyalty_sessions_required` column would have been
 * fewer moving parts today and a migration with a data backfill the first time
 * anybody wants a second tier, so the row exists from the start. `is_active`
 * is what makes "the current package" a query rather than a convention.
 *
 * **`loyalty_enrolments` is one row per customer, enforced.** A customer may be
 * enrolled in one package at a time in v1, and the unique index on
 * `(tenant_id, customer_id)` is what says so — a rule in a service is a rule
 * two concurrent bookings can both pass. `stamps_used` is the current cycle's
 * progress and resets; `cycles_completed` never does, so the customer screen can
 * say "third free groom" rather than only "0 of 5".
 *
 * `loyalty_package_id` is `nullOnDelete` rather than `cascadeOnDelete`:
 * deleting a package a customer is halfway through must not silently delete the
 * record that they are three sessions in. The enrolment survives with a null
 * package and stops accruing, which is a state the reader can see.
 *
 * **`bookings.is_loyalty_reward` is the free one, marked.** Without it, the
 * booking that spent the stamps is indistinguishable from a £0 service, so
 * completing it would earn a stamp — the reward would pay for itself and the
 * cycle would never end. It is also what the customer screen reads to list the
 * free sessions as history.
 */
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

            // One active enrolment per customer, in the database rather than in
            // a service that two simultaneous bookings could both walk past.
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
