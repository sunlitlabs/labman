<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Further names beyond `domain` / `public_domain`: {"local": [...], "public": [...]}.
        foreach (['services', 'servers'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->json('extra_domains')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['services', 'servers'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('extra_domains');
            });
        }
    }
};
