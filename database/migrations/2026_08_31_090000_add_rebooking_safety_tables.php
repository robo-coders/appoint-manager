<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three things that make the rebooking chase safe to point at real phone
 * numbers: a claim table whose unique index makes a duplicate chase
 * impossible, a per-tenant opt-out flag, and a segment count on every message
 * so the meter and the bill agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per message we have committed to sending. The unique index is
         * the rule, not the job's logic: a second `rebooking:send`, a manual
         * trigger and a retry after a crash all attempt the same insert and
         * exactly one of them can win.
         *
         * `due_on` is the cycle key — the date the subject fell due, which is
         * last visit plus interval. Booking moves the last visit, which moves
         * `due_on`, which is what makes the next cycle a different cycle. There
         * is no clock arithmetic in the uniqueness rule at all.
         */
        Schema::create('rebook_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->date('due_on');
            $table->unsignedTinyInteger('attempt');
            $table->unsignedSmallInteger('segments')->default(1);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'subject_id', 'due_on', 'attempt'], 'rebook_sends_cycle_unique');
            $table->index(['tenant_id', 'sent_at']);
        });

        /*
         * Opt-out is per tenant because `customers` is per tenant: a client of
         * two salons is two rows, so opting out of one cannot touch the other
         * and no code has to remember that.
         */
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('sms_opted_out_at')->nullable()->after('phone');
            $table->string('sms_opt_out_source', 40)->nullable()->after('sms_opted_out_at');
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedTinyInteger('rebook_failed_sends')->default(0)->after('rebook_contacted_at');
            $table->timestamp('rebook_send_blocked_at')->nullable()->after('rebook_failed_sends');
        });

        /*
         * What the carrier billed for. Stored rather than recomputed so the
         * send log, the meter and an invoice cannot drift apart when the
         * counting rules are improved.
         */
        Schema::table('messages', function (Blueprint $table) {
            /*
             * Which subject this was about.
             *
             * Two things need it. A client with two dogs receives two chases,
             * and a send log that names only the client cannot tell her which
             * one bounced. And `RebookAttempts` has to find a claim from a
             * message when the provider rejects it — going through
             * `rebook_sends.message_id` would mean the claim had to be linked
             * before the gateway is called, which it cannot be when the gateway
             * is called inline and throws.
             */
            $table->foreignId('subject_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('segments')->default(1)->after('body');
            /*
             * What the carrier said when it would not deliver. An invisible
             * failure is worse than a visible one: the salon believes she chased
             * somebody she did not, and "Unreachable destination handset" on the
             * send log is the difference between shrugging and correcting a
             * digit in a phone number.
             */
            $table->string('provider_error', 191)->nullable()->after('provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebook_sends');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['sms_opted_out_at', 'sms_opt_out_source']);
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['rebook_failed_sends', 'rebook_send_blocked_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
            $table->dropColumn(['segments', 'provider_error']);
        });
    }
};
