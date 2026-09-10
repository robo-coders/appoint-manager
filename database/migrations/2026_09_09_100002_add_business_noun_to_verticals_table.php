<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verticals', function (Blueprint $table) {
            $table->string('business_noun')->default('salon')->after('label');
        });

        DB::table('verticals')->where('key', 'groomer')->update(['business_noun' => 'salon']);
    }

    public function down(): void
    {
        Schema::table('verticals', function (Blueprint $table) {
            $table->dropColumn('business_noun');
        });
    }
};
