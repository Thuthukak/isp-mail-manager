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
        Schema::create('sync_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->string('type')->default('string'); // string, integer, boolean, json
            $table->text('description')->nullable();
            $table->string('category')->default('general'); // general, backup, purge, alerts
            $table->boolean('is_system')->default(false); // System configs cannot be deleted
            $table->timestamps();
            
            // Index
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_configurations');
    }
};
