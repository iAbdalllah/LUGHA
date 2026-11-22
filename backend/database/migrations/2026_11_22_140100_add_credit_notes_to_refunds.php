<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('credit_note_number', 40)->nullable()->unique(); // ZATCA: a refund is documented by a credit note
            $table->unsignedInteger('vat_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['credit_note_number']);
            $table->dropColumn(['credit_note_number', 'vat_amount']);
        });
    }
};
