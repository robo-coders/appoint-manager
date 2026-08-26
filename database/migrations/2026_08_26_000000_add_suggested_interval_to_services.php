<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a salon expects to wait before doing this service again.
 *
 * `AppointmentSuggester` proposes a returning customer's next appointment at or
 * after their *own* typical interval — the median gap across their last three
 * bookings. A customer with fewer than two previous bookings has no interval of
 * their own, and this is what it falls back to: a nail clip comes round every
 * three weeks, a double-coat groom every ten, and one global number is wrong
 * for both.
 *
 * Nullable rather than defaulted, so "the salon has not said" is distinct from
 * "the salon said six weeks". A null falls back to `config('booking.default_interval_days')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('suggested_interval_days')->nullable()->after('buffer_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('suggested_interval_days');
        });
    }
};
