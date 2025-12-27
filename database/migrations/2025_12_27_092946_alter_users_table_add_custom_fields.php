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
        Schema::table('users', function (Blueprint $table) {
            //
            // Only add if not already existing in your users table
            $table->string('username')->nullable()->after('id');
            $table->string('mobile')->nullable()->after('email');

            // role was enum(5) but no values shared -> string for now
            $table->string('role')->nullable()->after('mobile');

            $table->string('access')->nullable()->after('role');

            // email should be unique in most apps
            $table->unique('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
