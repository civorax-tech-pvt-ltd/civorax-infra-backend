<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')->constrained('users')->nullOnDelete();
            $table->text('client_note')->nullable()->after('notes');
            $table->dateTime('client_responded_at')->nullable()->after('client_note');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn(['client_note', 'client_responded_at']);
        });
    }
};
