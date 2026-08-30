<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A walk-in is often a name and a phone number. The unique index on
 * (tenant_id, email) still holds for addresses that exist; MySQL allows more
 * than one NULL, so two clients without an email are two rows, not a 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        DB::table('customers')->where('email', '')->update(['email' => null]);
    }

    public function down(): void
    {
        DB::table('customers')->whereNull('email')->update(['email' => '']);

        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
