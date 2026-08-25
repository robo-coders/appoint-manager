<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            // The Connect account the event came from. Without it we cannot tell
            // whether the account that reported a payment owns the booking it names.
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
