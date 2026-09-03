<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verticals used to live in `config/verticals.php`, which meant a new business
 * type was a code deploy. The table is the source of truth now; the groomer
 * row below is that file, copied rather than required, so `migrate:fresh`
 * still has it after the config is gone.
 *
 * `customer_singular`, `appointment_singular` and `subject_fields` are not on
 * the create form. Callers already read them off `Tenant::vertical()` — the
 * public booking island maps `subject_fields`, marketing copy uses
 * `customer_singular` — so they are stored here rather than dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verticals', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('subject_singular');
            $table->string('subject_plural');
            $table->string('customer_singular')->default('client');
            $table->string('appointment_singular')->default('appointment');
            $table->json('subject_fields')->nullable();
            $table->json('default_services')->nullable();
            $table->timestamps();
        });

        DB::table('verticals')->insert([
            'key' => 'groomer',
            'label' => 'Dog grooming',
            'subject_singular' => 'dog',
            'subject_plural' => 'dogs',
            'customer_singular' => 'client',
            'appointment_singular' => 'appointment',
            'subject_fields' => json_encode([
                ['key' => 'breed', 'label' => 'Breed', 'type' => 'text', 'required' => true],
                ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['small', 'medium', 'large', 'extra large'], 'required' => true],
                ['key' => 'coat', 'label' => 'Coat type', 'type' => 'text', 'required' => false],
                ['key' => 'notes', 'label' => 'Temperament / notes', 'type' => 'textarea', 'required' => false],
            ]),
            'default_services' => json_encode([
                ['name' => 'Full groom — small dog', 'duration_minutes' => 60, 'price' => 3500, 'deposit_amount' => 1000, 'rebook_interval' => ['value' => 6, 'unit' => 'weeks']],
                ['name' => 'Full groom — medium dog', 'duration_minutes' => 90, 'price' => 4500, 'deposit_amount' => 1000, 'rebook_interval' => ['value' => 6, 'unit' => 'weeks']],
                ['name' => 'Bath and blow dry', 'duration_minutes' => 45, 'price' => 2500, 'deposit_amount' => 1000, 'rebook_interval' => ['value' => 4, 'unit' => 'weeks']],
                ['name' => 'Nail clip', 'duration_minutes' => 15, 'price' => 1000, 'deposit_amount' => 0, 'rebook_interval' => ['value' => 3, 'unit' => 'weeks']],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('verticals');
    }
};
