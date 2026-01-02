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
        // Schema::create('v_sectors_view', function (Blueprint $table) {
        //     $table->id();
        //     $table->timestamps();
        // });

        DB::statement("
            CREATE OR REPLACE VIEW v_sectors AS
            SELECT DISTINCT
                sector AS id,
                sector AS name
            FROM t_mumineen
            WHERE sector IS NOT NULL AND sector <> ''
            ORDER BY sector
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::dropIfExists('v_sectors_view');
        DB::statement("DROP VIEW IF EXISTS v_sectors");
    }
};
