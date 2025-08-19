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
        Schema::create('mailbox_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('email_address');
            $table->bigInteger('current_size_bytes');
            $table->bigInteger('threshold_bytes');
            $table->enum('alert_type', ['size_warning', 'size_critical', 'purge_required']);
            $table->timestamp('alert_date');
            $table->enum('status', ['active', 'acknowledged', 'resolved', 'ignored']);
            $table->string('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->boolean('purge_approved')->default(false);
            $table->string('purge_approved_by')->nullable();
            $table->timestamp('purge_approved_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('email_address');
            $table->index('alert_date');
            $table->index('status');
            $table->index(['email_address', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_alerts');
    }
};
