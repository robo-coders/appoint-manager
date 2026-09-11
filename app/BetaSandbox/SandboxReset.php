<?php

namespace App\BetaSandbox;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class SandboxReset
{
    /** @return array<string, int> Rows removed, per table. The screen reports it. */
    public function run(Tenant $tenant): array
    {
        BetaSandbox::guard($tenant);

        return DB::transaction(function () use ($tenant): array {
            $removed = [];

            foreach (SandboxTables::transactional() as $table) {
                $removed[$table] = DB::table($table)->where('tenant_id', $tenant->id)->delete();
            }

            return $removed;
        });
    }
}
