<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plugin catalogue shared by all projects; holds no project data.
        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('plugin_id')->unique();
            $table->text('cve')->nullable();
            $table->string('name', 500);
            $table->text('description')->nullable();
            $table->text('synopsis')->nullable();
            $table->text('solution')->nullable();
            $table->text('see_also')->nullable();
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->string('cvss_vector')->nullable();
            $table->string('cvss_version', 5)->nullable();
            $table->unsignedTinyInteger('severity')->default(0);
            $table->string('family')->nullable();
            $table->timestamps();
        });

        Schema::create('vulnerability_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_host_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vulnerability_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('port')->default(0);
            $table->string('protocol', 10)->default('tcp');
            $table->string('service', 50)->nullable();
            $table->unsignedTinyInteger('severity')->default(0);
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->string('state', 20)->default('open');
            $table->longText('plugin_output')->nullable();
            $table->timestamp('first_found_at')->nullable();
            $table->timestamp('last_found_at')->nullable();
            $table->timestamps();

            // Makes re-importing the same scan idempotent.
            $table->unique(['scan_host_id', 'vulnerability_id', 'port', 'protocol'], 'vuln_instances_unique');
            $table->index(['project_id', 'state', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vulnerability_instances');
        Schema::dropIfExists('vulnerabilities');
    }
};
