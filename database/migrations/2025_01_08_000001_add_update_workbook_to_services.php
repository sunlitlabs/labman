<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Semaphore template (workbook) that updates this service: pull the new code, install, migrate, restart.
        Schema::table('services', function (Blueprint $t) {
            $t->unsignedInteger('update_project')->nullable();
            $t->unsignedInteger('update_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $t) {
            $t->dropColumn(['update_project', 'update_template']);
        });
    }
};