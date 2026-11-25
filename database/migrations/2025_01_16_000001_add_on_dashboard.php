<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether an item is shown on the dashboard. Hiding it changes nothing else: it is still health-checked,
        // listed in the admin lists, synced to NPM and exported.
        foreach (['servers', 'services', 'links'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->boolean('on_dashboard')->default(true));
        }
    }

    public function down(): void
    {
        foreach (['servers', 'services', 'links'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('on_dashboard'));
        }
    }
};