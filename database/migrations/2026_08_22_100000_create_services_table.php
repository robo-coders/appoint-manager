<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            /*
             * No `->index()` after `->constrained()`.
             *
             * `constrained()` returns a ForeignKeyDefinition, and `->index()` on
             * *that* sets the constraint's own name rather than adding an index —
             * to `true`, which MySQL renders as a constraint literally called
             * `1`. The second table to do it collides, so every migration after
             * this one failed on MySQL. SQLite ignores foreign key constraint
             * names, which is why the whole suite was green.
             *
             * `foreignId()` already indexes the column, so nothing is lost.
             */
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->unsignedInteger('price');
            $table->unsignedInteger('deposit_amount')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
