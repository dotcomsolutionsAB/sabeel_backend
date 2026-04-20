<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_establishment_slab_group_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('t_establishment_slab_group')->cascadeOnDelete();
            $table->string('establishment_id');
            $table->timestamps();

            $table->unique('establishment_id');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_establishment_slab_group_member');
    }
};
