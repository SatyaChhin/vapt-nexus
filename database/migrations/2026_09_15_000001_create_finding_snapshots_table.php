<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Open findings per severity, one row per project and day. Re-imports
     * overwrite findings in place, so these rows are the only history the
     * dashboard trend can be drawn from.
     */
    public function up(): void
    {
        Schema::create('finding_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('captured_on');
            $table->unsignedInteger('critical')->default(0);
            $table->unsignedInteger('high')->default(0);
            $table->unsignedInteger('medium')->default(0);
            $table->unsignedInteger('low')->default(0);
            $table->unsignedInteger('info')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_snapshots');
    }
};
