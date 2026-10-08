<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('signed_at');   // drawn signature PNG (private disk)
            $table->string('signed_name')->nullable()->after('signature_path');  // typed full name
            $table->string('signed_ip', 45)->nullable()->after('signed_name');
            $table->foreignId('signed_by')->nullable()->after('signed_ip')->constrained('users')->nullOnDelete();
            $table->timestamp('sent_for_signature_at')->nullable()->after('signed_by');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_by');
            $table->dropColumn(['signature_path', 'signed_name', 'signed_ip', 'sent_for_signature_at']);
        });
    }
};
