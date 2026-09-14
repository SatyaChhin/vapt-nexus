<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->nullable();
            $table->string('fqdn')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('operating_system')->nullable();
            $table->string('asset_type', 30)->default('host');
            $table->string('environment', 20)->nullable();
            $table->string('owner')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['project_id', 'ip_address']);
        });

        Schema::create('scan_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('nessus_host_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('hostname')->nullable();
            $table->string('fqdn')->nullable();
            $table->string('operating_system')->nullable();
            $table->string('status', 20)->nullable();
            $table->unsignedInteger('total_findings')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            $table->unsignedInteger('info_count')->default(0);
            $table->timestamps();

            $table->unique(['scan_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_hosts');
        Schema::dropIfExists('assets');
    }
};
