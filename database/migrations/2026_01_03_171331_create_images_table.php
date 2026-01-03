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
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained();
            $table->string('path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('variant'); // 256,512,1024,original
            $table->string('checksum');
            $table->timestamps();

            $table->unique(['upload_id', 'variant']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
