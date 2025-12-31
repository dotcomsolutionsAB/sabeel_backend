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
            $table->string('establishment_id');
            $table->string('name');
            $table->text('address');

            $table->enum('status', ['active', 'closed']);
            $table->enum('type', ['business', 'manufacturer']);

            $table->longText('remarks')->nullable();
            $table->timestamps();

            $table->unique('establishment_id');
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
