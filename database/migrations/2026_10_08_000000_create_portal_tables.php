<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('company');
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 12)->default('client')->after('password'); // admin | client
            $table->foreignId('client_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 30)->index();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TTD');
            $table->string('status', 12)->default('draft')->index(); // draft | active | completed | cancelled
            $table->text('scope')->nullable();
            $table->text('terms')->nullable();
            $table->string('file_path')->nullable(); // signed contract (private disk)
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('TTD');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('status', 12)->default('draft')->index();
            $table->text('intro')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('TTD');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('status', 12)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('line_items', function (Blueprint $table) {
            $table->id();
            $table->morphs('itemable'); // quotation or invoice
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TTD');
            $table->string('method', 20); // bank_transfer | cash | cheque
            $table->date('paid_on');
            $table->string('reference')->nullable(); // transfer ref, cheque number, who received cash
            $table->string('proof_path')->nullable(); // private disk
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pending')->index(); // pending | verified | rejected
            $table->text('rejection_reason')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('line_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('contracts');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('clients');
    }
};
