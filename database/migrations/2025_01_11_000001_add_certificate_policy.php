<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The zone's certificate policy (see App\Npm\CertificatePolicy) and a service's own choice that overrides it.
        Schema::table('zones', function (Blueprint $table) {
            $table->json('certificate_policy')->nullable();
        });
        Schema::table('services', function (Blueprint $table) {
            $table->string('cert_mode', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('zones', fn (Blueprint $table) => $table->dropColumn('certificate_policy'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('cert_mode'));
    }
};
