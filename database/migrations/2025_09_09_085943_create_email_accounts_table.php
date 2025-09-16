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
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('username'); // Often same as email
            $table->string('password')->encrypted();
            $table->string('imap_host');
            $table->integer('imap_port')->default(993);
            $table->boolean('imap_ssl')->default(true);
            $table->string('department')->nullable();
            $table->string('employee_name');
            $table->boolean('active')->default(true);
            $table->timestamp('last_backup')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
