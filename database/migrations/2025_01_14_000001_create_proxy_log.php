<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What was applied to each zone's proxy, when and by whom, so drift is explainable. Holds titles and counts, no secrets.
        Schema::create('proxy_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zone_id')->index();
            $table->string('source', 20);          // preview | import | guided | automatic
            $table->string('who', 100)->nullable(); // the admin's name, null for automatic updates
            $table->unsignedInteger('applied')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('results')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_log');
    }
};
