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
        Schema::create('t_advance_paid', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['family', 'establishment']);
            $table->unsignedBigInteger('family_id')->nullable();
            $table->unsignedBigInteger('establishment_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('mode', ['cash', 'cheque', 'neft']);
            $table->date('date');
            $table->text('remarks')->nullable();
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index('family_id');
            $table->index('establishment_id');
            $table->index('status');
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_advance_paid');
    }
};
