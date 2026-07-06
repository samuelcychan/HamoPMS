<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->timestamp('posted_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_line_items');
    }
};
