<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Check this path instead of `path` (apps often have a /health or /ping endpoint).
            $table->string('health_path')->nullable();
            // false = "not monitored": no checks, no status shown.
            $table->boolean('monitor')->default(true);
            // An open backend port counts as alive when the web check does not pass.
            $table->boolean('check_tcp')->default(true);
            // An icon name from the dashboard-icons library, "none", or empty (matched from the name).
            $table->string('icon', 80)->nullable();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('monitor')->default(true);
            // false for machines without SSH (routers, NAS appliances): only the extra ports are checked.
            $table->boolean('check_ssh')->default(true);
            $table->string('icon', 80)->nullable();
        });

        // The old default (200-399) marked login-protected and API-only apps as down. The new default is
        // 200-499; services still on the old default move to it.
        DB::table('services')->where('expect', '200-399')->update(['expect' => '200-499']);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['health_path', 'monitor', 'check_tcp', 'icon']);
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['monitor', 'check_ssh', 'icon']);
        });
    }
};
