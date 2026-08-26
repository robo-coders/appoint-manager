<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('waitlist_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->string('status');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'starts_at', 'status']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('waitlist_entry_id')->nullable()->after('subject_id')->constrained('waitlist_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waitlist_entry_id');
        });

        Schema::dropIfExists('slot_offers');
    }
};
