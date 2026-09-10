<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('requires_full_payment_override')->default(false)->after('notes');
            $table->timestamp('suggested_rule_dismissed_at')->nullable()->after('requires_full_payment_override');
            $table->timestamp('notes_updated_at')->nullable()->after('suggested_rule_dismissed_at');
            $table->foreignId('notes_updated_by')->nullable()->after('notes_updated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notes_updated_by');
            $table->dropColumn([
                'requires_full_payment_override',
                'suggested_rule_dismissed_at',
                'notes_updated_at',
            ]);
        });
    }
};
