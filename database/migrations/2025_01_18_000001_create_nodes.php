<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Other LabMan installs running in node mode that this one pushes a dashboard to (one per zone, say).
        Schema::create('nodes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('url');
            $t->text('token'); // encrypted
            $t->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('enabled')->default(true);
            $t->timestamp('pushed_at')->nullable();
            $t->boolean('push_ok')->nullable();
            $t->text('push_message')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
