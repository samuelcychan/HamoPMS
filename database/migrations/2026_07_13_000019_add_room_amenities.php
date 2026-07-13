<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'code']);
        });

        Schema::create('amenity_room', function (Blueprint $table) {
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['amenity_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_room');
        Schema::dropIfExists('amenities');
    }
};
