<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('address');
            $table->string('domain')->nullable();
            $table->string('group')->nullable();
            $table->json('ports')->nullable();
            $table->string('ssh_user')->nullable();
            $table->unsignedInteger('ssh_port')->default(22);
            $table->text('notes')->nullable();
            // Stored health: unknown | up | degraded | down
            $table->string('state', 16)->default('unknown');
            $table->json('checks')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('domain')->nullable();
            // A service points at a server OR carries its own address.
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('scheme', 5)->default('http');
            $table->string('path')->default('/');
            $table->string('group')->nullable();
            $table->string('expect')->default('200-399');
            $table->boolean('verify_tls')->default(true);
            $table->boolean('public_check')->default(true);
            $table->boolean('websockets')->default(false);
            $table->text('notes')->nullable();
            $table->string('state', 16)->default('unknown');
            $table->json('checks')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('settings');
    }
};
