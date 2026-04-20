<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_establishment_slab_group', function (Blueprint $table) {
            $table->id();
            $table->string('primary_establishment_id');
            $table->string('label')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'primary_establishment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_establishment_slab_group');
    }
};
