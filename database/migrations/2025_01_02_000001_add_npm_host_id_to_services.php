<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // The Nginx Proxy Manager proxy host this service is linked to (set by import/sync).
            $table->unsignedInteger('npm_host_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('npm_host_id');
        });
    }
};
