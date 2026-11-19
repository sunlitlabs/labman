<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `domain` stays the primary (usually local) name; a service may also have a public one with its own proxy host.
        Schema::table('services', function (Blueprint $table) {
            $table->string('public_domain', 253)->nullable()->after('domain');
            $table->unsignedBigInteger('npm_public_host_id')->nullable()->after('npm_host_id');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['public_domain', 'npm_public_host_id']);
        });
    }
};
