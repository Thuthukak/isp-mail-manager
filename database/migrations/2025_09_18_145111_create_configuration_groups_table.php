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
        Schema::create('configuration_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_group_id')->constrained()->onDelete('cascade');
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->enum('type', ['text', 'textarea', 'number', 'boolean', 'select', 'password'])->default('text');
            $table->json('options')->nullable(); // For select type
            $table->boolean('is_required')->default(false);
            $table->boolean('is_encrypted')->default(false);
            $table->string('validation_rules')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configurations');
        Schema::dropIfExists('configuration_groups');
    }
};
