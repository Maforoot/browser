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
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // ستون id
            $table->string('first_name', 100); // ستون first_name
            $table->string('last_name', 100); // ستون last_name
            $table->string('email', 50)->unique(); // ستون email (یونیک)
            $table->string('interest', 50)->nullable(); // ستون interest (nullable)
            $table->string('username', 191)->unique(); // ستون username (یونیک)
            $table->string('password', 191); // ستون password
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
