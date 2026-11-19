<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Things the admins should hear about (new hosts in Semaphore, a failed host-list update). Shared by all admins.
        Schema::create('notices', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('kind', 40);
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('url')->nullable();
            $t->timestamp('dismissed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
