<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-staff calendar subscription token.
 *
 * There is no staff login in this product, so a staff calendar feed cannot be
 * authenticated by session — and a calendar client (iOS, Google, Outlook) cannot
 * log in even where a session exists. A long-lived bearer token in the URL is
 * how every calendar subscription works, and this is that token.
 *
 * **Nullable, and filled on demand.** A tenant that never opens the calendar
 * settings screen has no tokens, so there is nothing to leak; `User::calendarToken()`
 * mints one the first time the owner asks for the link. That also means the
 * column needs no backfill for existing rows.
 *
 * **Unique, and unique across tenants.** The feed route has no tenant context —
 * it is fetched by a machine with no cookie — so the token is the only thing
 * that identifies the row, and a collision would serve one salon's diary to
 * another's staff. 32 hex characters from `random_bytes(16)`; the index makes a
 * duplicate a write error rather than a data leak.
 *
 * Regenerating is an update to this column, which is what makes a leaked link
 * revocable: the old URL stops resolving the moment a new token is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique()->after('colour');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token']);
            $table->dropColumn('calendar_token');
        });
    }
};
