<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one permission the staff step in onboarding asks about.
 *
 * Default true, because that is what every existing member of staff already
 * has: nothing in the product hides a customer's phone number today, so
 * defaulting to false would silently take something away from people who were
 * created before the column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_see_customer_contacts')->default(true)->after('is_bookable');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('can_see_customer_contacts');
        });
    }
};
