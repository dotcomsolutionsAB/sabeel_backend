<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('t_mumineen', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('family_id')->nullable();

            // was enum(3) / enum(1) / enum(8) etc in your SQL (values not provided)
            $table->string('hof_type')->nullable();

            $table->string('its')->nullable();
            $table->string('hof_its')->nullable();
            $table->string('family_its')->nullable();

            $table->string('name')->nullable();
            $table->string('sector')->nullable();
            $table->string('sub_sector')->nullable();

            $table->string('mobile')->nullable();
            $table->string('email')->nullable();

            $table->string('gender')->nullable();
            $table->integer('age')->nullable();

            $table->string('status')->nullable();
            $table->timestamps();

            // ITS normally unique
            $table->unique('its');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_mumineen');
    }
};
