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
        Schema::create('mail_restorations', function (Blueprint $table) {
            $table->id();
            $table->string('email_address');
            $table->year('restoration_year');
            $table->tinyInteger('restoration_month');
            $table->timestamp('restoration_date');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'partial']);
            $table->integer('files_to_restore');
            $table->integer('files_restored')->default(0);
            $table->integer('files_skipped')->default(0); // Already exist
            $table->bigInteger('total_size');
            $table->string('initiated_by')->nullable(); // Admin user who initiated
            $table->text('filter_criteria')->nullable(); // JSON of additional filters
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('email_address');
            $table->index('restoration_date');
            $table->index('status');
            $table->index(['email_address', 'restoration_year', 'restoration_month'], 'mail_restorations_email_year_month_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_restorations');
    }
};
