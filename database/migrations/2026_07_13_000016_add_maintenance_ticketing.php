<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->string('status', 30)->default('open');
            $table->string('priority', 20)->default('normal');
            $table->string('escalation_status', 20)->default('none');
            $table->string('room_status_before_maintenance', 30);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('escalation_reason')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['property_id', 'status', 'priority', 'escalation_status'],
                'maintenance_board_index',
            );
            $table->index(['room_id', 'status'], 'maintenance_room_status_index');
            $table->index(['assigned_to', 'status'], 'maintenance_assignee_index');
        });

        Schema::create('maintenance_ticket_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['maintenance_ticket_id', 'id'], 'maintenance_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_ticket_histories');
        Schema::dropIfExists('maintenance_tickets');
    }
};
