<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->collapseActiveDuplicates();

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('active_marker')
                ->nullable()
                ->storedAs('case when is_active = 1 then 1 end')
                ->after('is_active');

            $table->unique(
                ['tenant_id', 'customer_id', 'service_id', 'active_marker'],
                'waitlist_entries_active_join_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->index('tenant_id', 'waitlist_entries_tenant_id_foreign');
            $table->dropUnique('waitlist_entries_active_join_unique');
            $table->dropColumn('active_marker');
        });
    }

    private function collapseActiveDuplicates(): void
    {
        $keep = DB::table('waitlist_entries')
            ->where('is_active', true)
            ->groupBy('tenant_id', 'customer_id', 'service_id')
            ->selectRaw('min(id) as id')
            ->pluck('id');

        DB::table('waitlist_entries')
            ->where('is_active', true)
            ->whereNotIn('id', $keep)
            ->update(['is_active' => false]);
    }
};
