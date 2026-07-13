<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('deduplication_key')->unique();
            $table->string('channel', 30)->default('email');
            $table->string('template_key', 80);
            $table->string('locale', 12);
            $table->string('recipient');
            $table->string('subject');
            $table->json('payload');
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'status', 'created_at'], 'notification_property_status_index');
            $table->index(['booking_id', 'template_key'], 'notification_booking_template_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
