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
        Schema::create('whatsapp_campaign_logs', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_name')->index();
            $table->string('template_name');
            $table->integer('recipient_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->json('recipient_details')->nullable(); // Array of recipient objects with: phone, name, status, error_message, sent_at
            $table->json('message_variables')->nullable(); // Template variables used
            $table->string('pdf_path')->nullable(); // Path to combined PDF if multiple receipts
            $table->json('receipt_ids')->nullable(); // Array of receipt IDs included in this campaign
            $table->enum('type', ['family', 'establishment']);
            $table->unsignedBigInteger('family_id')->nullable();
            $table->unsignedBigInteger('establishment_id')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_log')->nullable(); // General error messages
            $table->timestamps();

            $table->index('family_id');
            $table->index('establishment_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_campaign_logs');
    }
};
