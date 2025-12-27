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
        Schema::create('t_establishment', function (Blueprint $table) {
            $table->id();
            $table->string('establishment_no')->nullable();
            $table->string('name')->nullable();
            $table->text('address')->nullable();

            // was enum(8) + enum(14) in your SQL, values not provided
            $table->string('status')->nullable();
            $table->string('type')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_establishment');
    }
};
