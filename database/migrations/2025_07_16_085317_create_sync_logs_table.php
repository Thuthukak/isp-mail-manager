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
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('operation_type', [
                'initial_backup', 
                'daily_sync', 
                'force_sync', 
                'restoration', 
                'purge', 
                'size_check'
            ]);
            $table->string('email_address')->nullable();
            $table->enum('status', ['started', 'processing', 'completed', 'failed', 'cancelled']);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('files_processed')->default(0);
            $table->integer('files_success')->default(0);
            $table->integer('files_failed')->default(0);
            $table->bigInteger('total_size_processed')->default(0);
            $table->json('details')->nullable(); // Additional operation details
            $table->text('error_message')->nullable();
            $table->string('job_id')->nullable(); // Queue job ID for tracking
            $table->float('progress_percentage')->default(0);
            $table->timestamps();
            
            // Indexes
            $table->index('operation_type');
            $table->index('email_address');
            $table->index('status');
            $table->index('started_at');
            $table->index(['operation_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
