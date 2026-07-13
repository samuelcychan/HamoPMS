<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ancillary_charge_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'code']);
        });

        Schema::table('folio_line_items', function (Blueprint $table) {
            $table->foreignId('ancillary_charge_type_id')->nullable()->after('folio_id')->constrained()->nullOnDelete();
            $table->foreignId('related_line_item_id')->nullable()->after('ancillary_charge_type_id')->constrained('folio_line_items')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->after('related_line_item_id')->constrained('users')->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('folio_line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('related_line_item_id');
            $table->dropConstrainedForeignId('ancillary_charge_type_id');
            $table->dropColumn('tax_rate');
        });

        Schema::dropIfExists('ancillary_charge_types');
    }
};
