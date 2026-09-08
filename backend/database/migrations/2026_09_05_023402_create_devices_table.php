<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('controller_id')
                ->constrained('controllers')
                ->cascadeOnDelete();

            $table->foreignId('room_id')
                ->nullable()
                ->constrained('rooms')
                ->nullOnDelete();

            $table->string('name');
            $table->string('identifier')->unique();
            $table->string('type');

            $table->unsignedInteger('gpio')->nullable();

            $table->string('state')->default('off');
            $table->boolean('enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
