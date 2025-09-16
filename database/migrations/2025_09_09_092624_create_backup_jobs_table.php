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
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained('email_accounts')->onDelete('cascade');
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->integer('emails_backed_up')->default(0);
            $table->integer('total_emails')->nullable(); // Total emails to backup
            $table->text('backup_path')->nullable(); // Use text for long paths
            $table->json('mailboxes_backed_up')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->string('job_type')->default('manual'); // manual, scheduled, retry
            $table->bigInteger('total_size_bytes')->nullable(); // Total size of backed up emails
            $table->float('progress_percentage', 5, 2)->default(0.00); // Progress as percentage
            $table->timestamps();
            
            // Indexes for better performance
            $table->index('email_account_id');
            $table->index('status');
            $table->index(['email_account_id', 'status']);
            $table->index('started_at');
            $table->index(['status', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};
