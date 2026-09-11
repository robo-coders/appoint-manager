<?php

use App\Enums\LoyaltyCardStatus;
use App\Enums\LoyaltyStampMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $minimum = (int) config('loyalty.min_visits_required');

        Schema::table('loyalty_packages', function (Blueprint $table) {
            $table->foreignId('eligible_service_id')->nullable()->after('sessions_required')
                ->constrained('services')->nullOnDelete();
            $table->boolean('auto_stamp')->default(true)->after('is_active');
            $table->boolean('auto_enrol')->default(true)->after('auto_stamp');
            $table->boolean('auto_apply_reward')->default(true)->after('auto_enrol');
            $table->boolean('show_visit_date')->default(true)->after('auto_apply_reward');
        });

        DB::statement(
            'ALTER TABLE loyalty_packages ADD CONSTRAINT loyalty_packages_sessions_required_min '
            ."CHECK (sessions_required >= {$minimum})"
        );

        Schema::table('loyalty_enrolments', function (Blueprint $table) {
            $table->string('status', 20)->default(LoyaltyCardStatus::Active->value)->after('loyalty_package_id');
            $table->timestamp('completed_at')->nullable()->after('cycles_completed');
            $table->timestamp('redeemed_at')->nullable()->after('completed_at');
        });

        DB::statement(
            'UPDATE loyalty_enrolments e '
            .'JOIN loyalty_packages p ON p.id = e.loyalty_package_id '
            .'SET e.status = ?, e.completed_at = e.updated_at '
            .'WHERE e.stamps_used >= p.sessions_required',
            [LoyaltyCardStatus::StampedOut->value]
        );

        Schema::create('loyalty_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20)->default(LoyaltyStampMethod::Automatic->value);
            $table->foreignId('stamped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('visit_date');
            $table->string('note', (int) config('loyalty.max_manual_note_length'))->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'booking_id']);
            $table->index(['tenant_id', 'loyalty_enrolment_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_stamps');

        Schema::table('loyalty_enrolments', function (Blueprint $table) {
            $table->dropColumn(['status', 'completed_at', 'redeemed_at']);
        });

        DB::statement('ALTER TABLE loyalty_packages DROP CONSTRAINT loyalty_packages_sessions_required_min');

        Schema::table('loyalty_packages', function (Blueprint $table) {
            $table->dropForeign(['eligible_service_id']);
            $table->dropColumn([
                'eligible_service_id',
                'auto_stamp',
                'auto_enrol',
                'auto_apply_reward',
                'show_visit_date',
            ]);
        });
    }
};
