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
        Schema::create('t_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('family_id')->nullable();
            $table->unsignedBigInteger('establishment_id')->nullable();

            $table->string('receipt_no');
            $table->date('date');

            $table->string('name');
            $table->string('its')->nullable();

            $table->enum('mode', ['cash', 'cheque', 'neft']);

            $table->string('transaction_no')->nullable();
            $table->date('transaction_date')->nullable();

            $table->string('bank')->nullable();
            $table->string('cheque_no')->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('ifsc')->nullable();

            $table->decimal('amount', 12, 2)->default(0);
            $table->integer('year');

            $table->text('comment')->nullable();

            $table->enum('status', ['active', 'cancelled']);
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();

            $table->unique('receipt_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_receipts');
    }
};
