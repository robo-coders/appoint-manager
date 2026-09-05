<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The beta sandbox's one column. See BETA_SANDBOX.md.
 *
 * `is_beta` is the whole data model for the feature: everything else the
 * sandbox does — sample data, fast-forward, reset — operates on the existing
 * tables through the tenant's own `tenant_id`, so there is nothing else to add
 * and nothing else to drop when the feature is removed.
 *
 * It sits beside `is_comped` rather than inside `feature_flags` on purpose.
 * `feature_flags` is a JSON blob a super admin edits freely; this flag decides
 * whether a tenant can reach Stripe's live keys, and a guard that important
 * should be a column a query can index and a reader can see in a `describe`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_beta')->default(false)->after('is_comped');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_beta');
        });
    }
};
