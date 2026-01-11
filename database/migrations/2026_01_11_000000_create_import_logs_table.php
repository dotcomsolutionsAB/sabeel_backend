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
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('operation_type'); // 'dry_run' or 'import'
            $table->string('file_name')->nullable();
            $table->integer('total_records')->default(0);
            $table->integer('hof_found')->default(0);
            $table->integer('hof_updated')->default(0);
            $table->integer('hof_created')->default(0);
            $table->integer('fm_synced')->default(0);
            $table->integer('fm_added')->default(0);
            $table->integer('fm_removed')->default(0);
            $table->integer('sabeel_created')->default(0);
            $table->integer('errors')->default(0);
            $table->longText('details')->nullable(); // JSON details of changes
            $table->longText('error_log')->nullable(); // JSON error details
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
