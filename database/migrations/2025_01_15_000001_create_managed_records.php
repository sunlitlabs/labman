<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What LabMan itself created in other systems. A delete is only ever offered for something listed here:
        // a proxy host LabMan created in a zone's NPM, and a DNS rewrite LabMan wrote to AdGuard Home.
        Schema::create('npm_managed_hosts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zone_id')->index();
            $table->unsignedBigInteger('host_id');
            $table->string('domain', 253);
            $table->timestamps();
            $table->unique(['zone_id', 'host_id']);
        });

        Schema::create('adguard_managed', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 253);
            $table->string('answer', 253);
            $table->timestamps();
            $table->unique(['domain', 'answer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adguard_managed');
        Schema::dropIfExists('npm_managed_hosts');
    }
};
