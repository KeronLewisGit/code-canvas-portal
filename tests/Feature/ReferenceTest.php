<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_continue_the_previous_tools_sequence_and_other_documents_number_per_year(): void
    {
        $client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $first = Invoice::create(['client_id' => $client->id, 'title' => 'A', 'issue_date' => now(), 'status' => 'draft']);
        $second = Invoice::create(['client_id' => $client->id, 'title' => 'B', 'issue_date' => now(), 'status' => 'draft']);
        $this->assertSame('INV-000063', $first->reference);
        $this->assertSame('INV-000064', $second->reference);

        Invoice::create(['client_id' => $client->id, 'title' => 'legacy', 'issue_date' => now(), 'status' => 'draft', 'reference' => 'INV-2026-001']);
        $this->assertSame('INV-000065', Invoice::create(['client_id' => $client->id, 'title' => 'C', 'issue_date' => now(), 'status' => 'draft'])->reference, 'legacy year-style numbers are ignored by the running sequence');

        $this->assertSame('Q-'.now()->year.'-001', Quotation::create(['client_id' => $client->id, 'title' => 'Q', 'issue_date' => now(), 'status' => 'draft'])->reference);
        $this->assertSame('CT-'.now()->year.'-001', Contract::create(['client_id' => $client->id, 'title' => 'C', 'type' => 'website', 'start_date' => now(), 'amount' => 1, 'status' => 'draft'])->reference);
    }

    public function test_invoice_terms_label_comes_from_the_due_date(): void
    {
        $client = Client::create(['company' => 'Acme', 'contact_name' => 'Ann', 'email' => 'ann@acme.test']);
        $net = Invoice::create(['client_id' => $client->id, 'title' => 'A', 'issue_date' => now(), 'due_date' => now()->addDays(30), 'status' => 'sent']);
        $receipt = Invoice::create(['client_id' => $client->id, 'title' => 'B', 'issue_date' => now(), 'status' => 'sent']);
        $this->assertSame('Net 30', $net->termsLabel());
        $this->assertSame('Due on receipt', $receipt->termsLabel());
    }
}
