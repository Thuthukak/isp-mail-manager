<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purge_history', function (Blueprint $table) {
            $table->id();
            $table->string('email_address');
            $table->timestamp('purged_date');
            $table->integer('files_purged');
            $table->bigInteger('total_size_freed');
            $table->date('purge_cutoff_date'); // Files older than this date were purged
            $table->enum('purge_type', ['automatic', 'manual', 'forced']);
            $table->string('initiated_by')->nullable(); // Admin user
            $table->boolean('dry_run')->default(false);
            $table->json('purged_files')->nullable(); // List of purged files for rollback
            $table->enum('status', ['completed', 'failed', 'rolled_back']);
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('email_address');
            $table->index('purged_date');
            $table->index('purge_type');
            $table->index(['email_address', 'purged_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purge_history');
    }
};
