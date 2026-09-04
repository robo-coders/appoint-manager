<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('booking_mode', ['automated', 'request'])->default('automated');
            $table->boolean('request_requires_deposit')->default(true);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('request_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['booking_mode', 'request_requires_deposit']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('request_expires_at');
        });
    }
};
