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
            $table->foreignId('email_account_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('message_id', 998)->nullable(); // RFC compliant length
            $table->string('mailbox_folder')->nullable();
            $table->string('email_address')->index(); // Move index here for clarity
            $table->text('original_file_path'); // Use text for long paths
            $table->text('onedrive_path'); // Use text for long paths
            $table->timestamp('backup_date')->index();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->index();
            $table->bigInteger('file_size')->nullable();
            $table->string('file_hash', 64)->nullable(); // Assuming SHA-256
            $table->timestamp('last_verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();
            
            // Composite indexes
            $table->index(['email_account_id', 'message_id']);
            $table->index(['email_address', 'backup_date']);
            
            // Unique constraint to prevent duplicate backups
            $table->unique(['email_account_id', 'message_id', 'mailbox_folder'], 'unique_mail_backup');
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
