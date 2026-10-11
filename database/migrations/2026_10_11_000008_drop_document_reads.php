<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Menu counts now show actions waiting (answer, sign, pay), not unread documents, so read tracking is no longer kept. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('document_reads');
    }

    public function down(): void
    {
        Schema::create('document_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('readable_type');
            $t->unsignedBigInteger('readable_id');
            $t->timestamp('read_at');
            $t->unique(['user_id', 'readable_type', 'readable_id']);
        });
    }
};
