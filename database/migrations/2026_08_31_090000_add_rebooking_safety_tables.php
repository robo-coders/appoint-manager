<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('sms_opted_out_at')->nullable()->after('phone');
            $table->string('sms_opt_out_source', 40)->nullable()->after('sms_opted_out_at');
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedTinyInteger('rebook_failed_sends')->default(0)->after('rebook_contacted_at');
            $table->timestamp('rebook_send_blocked_at')->nullable()->after('rebook_failed_sends');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('segments')->default(1)->after('body');
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
