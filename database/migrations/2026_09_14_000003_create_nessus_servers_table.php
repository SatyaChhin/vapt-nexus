<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nessus_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('base_url', 255);
            // Encrypted with APP_KEY via the model's "encrypted" cast.
            $table->text('access_key');
            $table->text('secret_key');
            $table->boolean('verify_ssl')->default(true);
            $table->string('status', 20)->default('unknown');
            $table->string('server_version', 50)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_nessus_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nessus_server_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'nessus_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_nessus_servers');
        Schema::dropIfExists('nessus_servers');
    }
};
