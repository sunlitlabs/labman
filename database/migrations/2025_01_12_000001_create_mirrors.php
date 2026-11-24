<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A mirror is a proxy host in one zone's proxy that forwards to another zone's proxy for a name that other
        // zone owns. LabMan keeps a record of each one it made, so it can tell when one has drifted or been orphaned.
        Schema::create('mirrors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->nullable()->index(); // not a foreign key: the record outlives a deleted service
            $table->string('service_name', 100);
            $table->unsignedBigInteger('zone_id')->index();                 // the zone whose proxy holds the mirror
            $table->string('domain', 253);
            $table->unsignedBigInteger('npm_host_id')->nullable();
            $table->string('upstream', 20)->nullable();                     // http | https | https_insecure, as last applied
            $table->timestamps();
            $table->unique(['zone_id', 'domain']);
        });

        // Which zones' hosts a zone mirrors ([{zone_id, upstream}], off when empty), and a service's own
        // "also publish in these zones" list (same shape), which wins over the zone rule.
        Schema::table('zones', function (Blueprint $table) {
            $table->json('mirror_sources')->nullable();
        });
        Schema::table('services', function (Blueprint $table) {
            $table->json('publish_zones')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('mirror_sources'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('publish_zones'));
        Schema::dropIfExists('mirrors');
    }
};
