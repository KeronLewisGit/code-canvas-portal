<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Local demo data only. Never run on production: use `php artisan portal:admin` there. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => config('company.email')], ['name' => 'Keron Lewis', 'password' => 'password', 'role' => 'admin']);

        $client = Client::updateOrCreate(['email' => 'owner@allthingstobago.com'], ['company' => 'All Things Tobago', 'contact_name' => 'Tobago Crew', 'phone' => '(868) 555-0100', 'address' => 'Crown Point, Tobago', 'website' => 'https://allthingstobago.com', 'notes' => 'Found via Instagram. Wants bookings live before the December season.', 'disclosure_consent_at' => now()->subDays(10)]);
        $portalUser = User::updateOrCreate(['email' => 'client@example.com'], ['name' => 'Tobago Crew', 'password' => 'password', 'role' => 'client', 'client_id' => $client->id]);

        if (Contract::count() > 0) {
            return;
        }

        $quote = Quotation::create(['client_id' => $client->id, 'title' => 'Booking website and admin dashboard', 'issue_date' => now()->subDays(40), 'valid_until' => now()->subDays(10), 'status' => 'accepted', 'sent_at' => now()->subDays(40), 'accepted_at' => now()->subDays(35), 'intro' => 'A booking site for your tours with a dashboard to manage bookings, prices and photos.', 'notes' => config('company.document_terms')]);
        $quote->syncItems([
            ['description' => 'Design and build of the public booking site (13 experiences, trip planner, enquiries)', 'quantity' => 1, 'unit_price' => 9000],
            ['description' => 'Admin dashboard: bookings, calendar, reviews, gallery, CSV export', 'quantity' => 1, 'unit_price' => 4500],
            ['description' => 'Hostinger deployment and launch support', 'quantity' => 1, 'unit_price' => 1500],
        ]);

        $contract = Contract::create(['client_id' => $client->id, 'title' => 'Booking website and admin dashboard', 'type' => 'booking-system', 'start_date' => now()->subDays(30), 'end_date' => now()->addDays(45), 'amount' => 15000, 'status' => 'active', 'scope' => "- Public booking site with 13 experiences and a trip planner\n- Admin dashboard for bookings, calendar, reviews and gallery\n- Deployment to Hostinger with SSL\n- Two rounds of revisions",  'signed_at' => now()->subDays(29), 'approved_at' => now()->subDays(31), 'approved_by' => $admin->id, 'activated_at' => now()->subDays(29)]);
        $contract->freezeTerms();
        $quote->update(['contract_id' => $contract->id]);

        $deposit = Invoice::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'quotation_id' => $quote->id, 'title' => 'Deposit: booking website and admin dashboard', 'issue_date' => now()->subDays(29), 'due_date' => now()->subDays(15), 'status' => 'sent', 'sent_at' => now()->subDays(29), 'notes' => config('company.document_terms')]);
        $deposit->syncItems([['description' => '50% deposit on contract '.$contract->reference, 'quantity' => 1, 'unit_price' => 7500]]);

        $final = Invoice::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'title' => 'Final payment: booking website and admin dashboard', 'issue_date' => now()->subDays(2), 'due_date' => now()->addDays(12), 'status' => 'sent', 'sent_at' => now()->subDays(2), 'notes' => config('company.document_terms')]);
        $final->syncItems([['description' => 'Balance on contract '.$contract->reference, 'quantity' => 1, 'unit_price' => 7500]]);

        $paid = Payment::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'invoice_id' => $deposit->id, 'amount' => 7500, 'method' => 'bank_transfer', 'paid_on' => now()->subDays(20), 'reference' => 'RBL 4471 deposit', 'status' => 'pending', 'submitted_by' => $portalUser->id]);
        $paid->markVerified($admin);

        Payment::create(['client_id' => $client->id, 'contract_id' => $contract->id, 'invoice_id' => $final->id, 'amount' => 3000, 'method' => 'cash', 'paid_on' => now()->subDay(), 'reference' => 'Handed to Keron at Crown Point', 'note' => 'Part payment, rest by transfer next week.', 'status' => 'pending', 'submitted_by' => $portalUser->id]);

        Contract::create(['client_id' => $client->id, 'title' => 'Monthly maintenance and hosting', 'type' => 'retainer', 'start_date' => now()->addDays(45), 'amount' => 600, 'billing_cycle' => 'monthly', 'status' => 'draft', 'scope' => 'Updates, backups, uptime monitoring and small content changes each month.']);

        $second = Client::updateOrCreate(['email' => 'info@island-eats.tt'], ['company' => 'Island Eats Catering', 'contact_name' => 'Marcia Thomas', 'phone' => '(868) 555-0199']);
        $q2 = Quotation::create(['client_id' => $second->id, 'title' => 'Menu website with online ordering form', 'issue_date' => now()->subDays(3), 'valid_until' => now()->addDays(27), 'status' => 'sent', 'sent_at' => now()->subDays(3), 'notes' => config('company.document_terms')]);
        $q2->syncItems([['description' => 'Five-page website with menu, gallery and order enquiry form', 'quantity' => 1, 'unit_price' => 6500], ['description' => 'Logo refresh', 'quantity' => 1, 'unit_price' => 1200]]);
        User::updateOrCreate(['email' => 'marcia@example.com'], ['name' => 'Marcia Thomas', 'password' => 'password', 'role' => 'client', 'client_id' => $second->id]);
    }
}
