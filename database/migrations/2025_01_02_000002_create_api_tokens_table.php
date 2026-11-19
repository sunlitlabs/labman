<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // read | admin
            $table->string('scope', 16);
            // The admin who created it. An admin-scope token only works while this user is an active admin.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Only the SHA-256 of the token is stored; the token itself is shown once at creation.
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
