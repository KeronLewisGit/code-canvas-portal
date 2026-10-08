<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive only: new nullable/defaulted columns and two new tables. Existing rows keep working. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->default(14)->after('currency');
            $table->unsignedSmallInteger('notice_period_days')->nullable()->after('payment_terms_days');
            $table->boolean('auto_renew')->default(false)->after('notice_period_days');
            $table->foreignId('renewal_of_id')->nullable()->after('auto_renew')->constrained('contracts')->nullOnDelete();
            $table->string('client_signatory')->nullable()->after('renewal_of_id');   // authorised signatory on the client side
            $table->string('company_signatory')->nullable()->after('client_signatory'); // defaults to config('company.signatory')
            $table->boolean('confidential')->default(false)->after('company_signatory'); // never disclose externally
            $table->unsignedSmallInteger('version')->default(1)->after('confidential');
            $table->timestamp('approved_at')->nullable()->after('version');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable()->after('approved_by');
            $table->timestamp('terminated_at')->nullable()->after('activated_at');
            $table->text('termination_reason')->nullable()->after('terminated_at');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('disclosure_consent_at')->nullable()->after('active'); // consent to appear in bank/lender reports
        });

        Schema::create('contract_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('snapshot');       // immutable copy of the material fields at that version
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['contract_id', 'version']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 40);
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('contract_versions');
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('disclosure_consent_at'));
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renewal_of_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['payment_terms_days', 'notice_period_days', 'auto_renew', 'client_signatory', 'company_signatory', 'confidential', 'version', 'approved_at', 'activated_at', 'terminated_at', 'termination_reason']);
        });
    }
};
