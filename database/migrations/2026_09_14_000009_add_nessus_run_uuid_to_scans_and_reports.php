<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nessus gives every launch of a scan a new UUID. Storing it tells the
     * sync which run was imported, and which run a report covers.
     */
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('nessus_run_uuid', 100)->nullable()->after('nessus_scan_id');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->string('nessus_run_uuid', 100)->nullable()->after('scan_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('nessus_run_uuid');
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('nessus_run_uuid');
        });
    }
};
