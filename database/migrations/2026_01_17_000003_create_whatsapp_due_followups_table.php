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
        Schema::create('whatsapp_due_followups', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['family', 'establishment']);
            $table->unsignedBigInteger('family_id')->nullable();
            $table->unsignedBigInteger('establishment_id')->nullable();
            $table->string('phone'); // Recipient phone number
            $table->date('sent_date'); // Date when message was sent (to prevent duplicates in same day)
            $table->string('template_name'); // Template used (sabeel_due or sabeel_overdue)
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('message_variables')->nullable(); // Template variables used
            $table->timestamps();

            // Indexes for fast lookups
            $table->index(['type', 'family_id', 'sent_date']);
            $table->index(['type', 'establishment_id', 'sent_date']);
            $table->index(['phone', 'sent_date']);
            $table->index('sent_date');
            $table->index('status');
            
            // Unique constraint: same type + id + phone + date = can only be sent once per day
            $table->unique(['type', 'family_id', 'establishment_id', 'phone', 'sent_date'], 'unique_daily_followup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_due_followups');
    }
};
