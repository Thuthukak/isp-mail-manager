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
        Schema::create('mail_backups', function (Blueprint $table) {
            $table->id();
            $table->string('email_address');
            $table->string('original_file_path');
            $table->string('onedrive_path');
            $table->timestamp('backup_date');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
            $table->bigInteger('file_size')->nullable();
            $table->string('file_hash')->nullable(); // For integrity checking
            $table->timestamp('last_verified_at')->nullable();
            $table->json('metadata')->nullable(); // Store additional mail metadata
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();
            
            // Indexes for better performance
            $table->index('email_address');
            $table->index('backup_date');
            $table->index('status');
            $table->index(['email_address', 'backup_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_backups');
    }
};
