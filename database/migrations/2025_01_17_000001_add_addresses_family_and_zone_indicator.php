<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Further addresses of the same machine or service (a Wi-Fi and a LAN address), each with an optional domain.
        Schema::table('servers', fn (Blueprint $t) => $t->json('alternates')->nullable());
        Schema::table('services', function (Blueprint $t) {
            $t->json('alternates')->nullable();
            $t->string('family')->nullable(); // services that are "the same thing" (one NPM per zone) share a tile
        });
        // Dashboards viewed from this zone show a zone badge on each item and offer a view by zone.
        Schema::table('zones', fn (Blueprint $t) => $t->boolean('dashboard_indicator')->default(false));
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('alternates'));
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn(['alternates', 'family']));
        Schema::table('zones', fn (Blueprint $t) => $t->dropColumn('dashboard_indicator'));
    }
};
