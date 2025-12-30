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
            $table->unsignedBigInteger('family_id');
            $table->string('its');

            $table->unsignedBigInteger('establishment_no');
            $table->unsignedBigInteger('updated_by');
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
