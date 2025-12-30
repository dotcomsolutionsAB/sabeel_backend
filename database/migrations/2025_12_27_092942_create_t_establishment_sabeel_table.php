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
        Schema::create('t_establishment_sabeel', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('establishment_no');
            $table->integer('year');
            $table->unsignedBigInteger('sabeel');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_establishment_sabeel');
    }
};
