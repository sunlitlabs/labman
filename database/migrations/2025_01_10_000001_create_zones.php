<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            // Higher means more trusted (management > internal > lan > external). Advisory: LabMan warns, never blocks.
            $table->unsignedSmallInteger('level')->default(50);
            $table->string('color', 7)->nullable();
            $table->string('icon', 80)->nullable();
            $table->boolean('is_default')->default(false);
            // CIDR blocks that suggest this zone for a new server, and the zone's own domains ({local: [], public: []}).
            // The default zone keeps its domains in the settings until the proxy step moves them (see Zone::domains()).
            $table->json('cidrs')->nullable();
            $table->json('domains')->nullable();
            // What other proxies forward to when they mirror this zone's hosts (an IP or a stable internal name).
            $table->string('proxy_address', 253)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('zone_id')->nullable()->index();
        });

        // A service takes its zone from its server; this column is only for the rare override.
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedBigInteger('zone_id')->nullable()->index();
        });

        // Everything that exists today becomes one zone, so a single-zone lab behaves exactly as before.
        $now = now();
        $id = DB::table('zones')->insertGetId(['name' => 'Default', 'level' => 50, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('servers')->update(['zone_id' => $id]);
    }

    public function down(): void
    {
        foreach (['servers', 'services'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropIndex(['zone_id']));
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('zone_id'));
        }
        Schema::dropIfExists('zones');
    }
};
