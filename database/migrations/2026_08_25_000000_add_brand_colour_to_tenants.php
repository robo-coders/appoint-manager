<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's accent on its public booking page.
 *
 * Nullable, and null is the expected state rather than a gap to be backfilled:
 * `--brand` defaults to ink in tokens.css, so a tenant that has never opened the
 * branding screen renders in the product's own primary and looks deliberate.
 * There is no default value on the column for the same reason — a default would
 * mean picking one of the six on every salon's behalf.
 *
 * The column stores the preset NAME ('forest'), never a hex. What forest looks
 * like is a question only resources/css/tokens.css answers. See
 * App\Support\BrandPalette.
 *
 * Rollback on a populated table: down() drops one nullable column that nothing
 * references — no foreign key points at it, no index covers it, no other column
 * is derived from it — so it cannot fail on populated data or take anything
 * else with it. What it does discard is each salon's choice, and there is no
 * way to drop a column without doing that. The consequence is bounded and
 * visible: every booking page reverts to ink, which is exactly what those pages
 * looked like before this migration ran. Re-running up() gives the column back
 * empty, and salons re-pick. Nothing else in the system reads this value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // 20 chars is roomy for the longest preset name and small enough
            // that it can never be mistaken for a field that takes a hex.
            $table->string('brand_colour', 20)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('brand_colour');
        });
    }
};
