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
        Schema::create('t_mumineen_establishment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('family_id')->nullable();
            $table->string('its')->nullable();

            $table->unsignedBigInteger('establishment_id');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('its');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_mumineen_establishment');
    }
};
