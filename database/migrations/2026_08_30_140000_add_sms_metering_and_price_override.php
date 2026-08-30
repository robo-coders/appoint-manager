<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('sms_cycle_used')->default(0)->after('feature_flags');
            $table->unsignedInteger('sms_prepaid')->default(0)->after('sms_cycle_used');
            $table->unsignedInteger('sms_included_override')->nullable()->after('sms_prepaid');
            $table->unsignedInteger('sms_ceiling_override')->nullable()->after('sms_included_override');
            $table->timestamp('sms_killed_at')->nullable()->after('sms_ceiling_override');
            $table->timestamp('sms_cycle_started_at')->nullable()->after('sms_killed_at');
            $table->json('sms_warnings_sent')->nullable()->after('sms_cycle_started_at');
            $table->unsignedInteger('monthly_price_override_pence')->nullable()->after('sms_warnings_sent');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'sms_cycle_used',
                'sms_prepaid',
                'sms_included_override',
                'sms_ceiling_override',
                'sms_killed_at',
                'sms_cycle_started_at',
                'sms_warnings_sent',
                'monthly_price_override_pence',
            ]);
        });
    }
};
