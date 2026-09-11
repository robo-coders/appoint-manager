<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->whereNull('trial_ends_at')
            ->where('subscription_status', 'trial')
            ->update(['trial_ends_at' => now()->addDays((int) config('billing.trial_days'))]);
    }

    public function down(): void {}
};
