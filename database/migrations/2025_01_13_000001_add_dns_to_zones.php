<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a zone's names are answered: {split_horizon: bool, public_address: ?string}. See Zone::dnsSettings().
        Schema::table('zones', function (Blueprint $table) {
            $table->json('dns')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('dns'));
    }
};
