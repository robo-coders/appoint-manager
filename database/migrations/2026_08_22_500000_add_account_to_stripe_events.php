<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->string('account_id')->nullable()->after('event_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->dropColumn('account_id');
        });
    }
};
