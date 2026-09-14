<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            // Restrict: a project with scan history must be archived, not deleted.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('nessus_server_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('nessus_scan_id')->nullable();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->text('targets');
            $table->string('status', 20)->default('created');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('total_hosts')->default(0);
            $table->unsignedInteger('scanned_hosts')->default(0);
            $table->unsignedInteger('total_findings')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('high_count')->default(0);
            $table->unsignedInteger('medium_count')->default(0);
            $table->unsignedInteger('low_count')->default(0);
            $table->unsignedInteger('info_count')->default(0);
            $table->string('error_message', 500)->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['nessus_server_id', 'nessus_scan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
