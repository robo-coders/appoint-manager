<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('rebook_interval_days')->nullable()->after('attributes');
            $table->timestamp('rebook_snoozed_until')->nullable()->after('rebook_interval_days');
            $table->timestamp('rebook_stopped_at')->nullable()->after('rebook_snoozed_until');
            $table->timestamp('rebook_contacted_at')->nullable()->after('rebook_stopped_at');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedSmallInteger('rebook_interval_days')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn([
                'rebook_interval_days',
                'rebook_snoozed_until',
                'rebook_stopped_at',
                'rebook_contacted_at',
            ]);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('rebook_interval_days');
        });
    }
};
