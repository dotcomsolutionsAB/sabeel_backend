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
        Schema::create('t_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('deposit_id', 10)->unique(); // 10-character unique deposit_id
            $table->date('date'); // Deposit date
            $table->string('receipt_ids'); // Receipt IDs as string
            $table->float('amount'); // Amount deposited
            $table->integer('created_by'); // ID of the creator
            $table->longText('remarks')->nullable(); // Nullable remarks
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_deposits');
    }
};
