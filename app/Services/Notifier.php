<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\PortalMail;
use App\Support\Audit;
use Illuminate\Support\Facades\Notification;

/**
 * Every email the portal sends. Client messages go to the client's portal logins (or the client's contact
 * email if no login exists yet); admin messages go to every admin user. Reminders are logged and stamped.
 */
class Notifier
{
    public function quotationSent(Quotation $q): void
    {
        $this->client($q->client, new PortalMail('Quotation '.$q->reference.' is ready for you', ['We have prepared a quotation for "'.$q->title.'" totalling '.money($q->total, $q->currency).'.', $q->valid_until ? 'It is valid until '.$q->valid_until->format('j F Y').'.' : 'Let us know if you have any questions.', 'You can review it, download a PDF, and accept or decline it in your client portal.'], 'Review the quotation', route('portal.quotations.show', $q)));
        $this->admins(new PortalMail('Sent: quotation '.$q->reference, [$q->reference.' for '.$q->client->company.' ('.money($q->total, $q->currency).') was emailed to the client.'], 'Open quotation', route('admin.quotations.show', $q)));
    }

    public function quotationAnswered(Quotation $q, bool $accepted): void
    {
        $this->admins(new PortalMail(($accepted ? 'Accepted' : 'Declined').': quotation '.$q->reference, [$q->client->company.' has '.($accepted ? 'accepted' : 'declined').' '.$q->reference.' ("'.$q->title.'", '.money($q->total, $q->currency).').', $accepted ? 'Next: draft the contract and the deposit invoice from the quotation page.' : 'You may want to follow up with the client.'], 'Open quotation', route('admin.quotations.show', $q)));
        $this->client($q->client, new PortalMail('Thank you: quotation '.$q->reference.' '.($accepted ? 'accepted' : 'declined'), [$accepted ? 'We have received your acceptance of '.$q->reference.'. We will send the contract and deposit invoice shortly.' : 'We have noted that '.$q->reference.' was declined. If a revised quote would help, reply to this email.'], 'View in your portal', route('portal.quotations.show', $q)));
    }

    public function invoiceSent(Invoice $i): void
    {
        $this->client($i->client, new PortalMail('Invoice '.$i->reference.' for '.money($i->total, $i->currency), ['Invoice '.$i->reference.' ("'.$i->title.'") is now available.', 'Amount due: '.money($i->balance(), $i->currency).($i->due_date ? ', payable by '.$i->due_date->format('j F Y') : '').' ('.$i->termsLabel().').', 'Pay by bank transfer, cheque or cash, then submit the payment in your portal so we can confirm it.'], 'View and pay the invoice', route('portal.invoices.show', $i)));
        $this->admins(new PortalMail('Sent: invoice '.$i->reference, [$i->reference.' for '.$i->client->company.' ('.money($i->total, $i->currency).') was emailed to the client.'], 'Open invoice', route('admin.invoices.show', $i)));
    }

    public function paymentSubmitted(Payment $p): void
    {
        $this->admins(new PortalMail('Payment to verify: '.money($p->amount, $p->currency).' from '.$p->client->company, [$p->client->company.' submitted a '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').($p->invoice ? ' against '.$p->invoice->reference : '').'.', $p->proof_path ? 'A proof file is attached in the portal.' : 'Reference: '.($p->reference ?: 'none given').'.', 'Please check your records and confirm or reject it.'], 'Review the payment', route('admin.payments.show', $p)));
        $this->client($p->client, new PortalMail('We received your payment submission', ['Thank you. Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' has been submitted and is awaiting verification.', 'We usually confirm within one working day and will email you once it is verified.'], 'View payment', route('portal.payments.show', $p)));
    }

    public function paymentDecided(Payment $p): void
    {
        $ok = $p->status === 'verified';
        $this->client($p->client, new PortalMail($ok ? 'Payment confirmed: '.money($p->amount, $p->currency) : 'We could not verify your payment', $ok
            ? ['Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').' has been verified. Thank you.', $p->invoice ? 'Invoice '.$p->invoice->reference.' balance is now '.money($p->invoice->balance(), $p->invoice->currency).'.' : ($p->contract ? 'Contract '.$p->contract->reference.' balance is now '.money($p->contract->balance(), $p->contract->currency).'.' : 'Your balance has been updated.')]
            : ['Your '.strtolower($p->methodName()).' payment of '.money($p->amount, $p->currency).' dated '.$p->paid_on->format('j F Y').' could not be verified.', 'Reason: '.$p->rejection_reason, 'Please check the details and submit it again, or reply to this email if you think this is a mistake.'],
            $ok ? 'View payment' : 'Submit again', $ok ? route('portal.payments.show', $p) : route('portal.payments.create', array_filter(['invoice' => $p->invoice_id, 'contract' => $p->contract_id]))));
    }

    public function contractSentForSignature(Contract $c): void
    {
        $this->client($c->client, new PortalMail('Contract '.$c->reference.' is ready to sign', ['The agreement "'.$c->title.'" ('.$c->amountLabel().') is ready for your signature.', 'Read it in your portal, then sign electronically in under a minute. Work starts once it is signed and the deposit is received.'], 'Read and sign the contract', route('portal.contracts.show', $c)));
        $this->admins(new PortalMail('Sent for signature: '.$c->reference, [$c->reference.' for '.$c->client->company.' was sent to the client to sign.'], 'Open contract', route('admin.contracts.show', $c)));
    }

    public function contractSigned(Contract $c): void
    {
        $this->admins(new PortalMail('Signed: contract '.$c->reference, [$c->signed_name.' signed '.$c->reference.' for '.$c->client->company.' on '.$c->signed_at->format('j F Y \a\t g:ia').'.', 'The contract is now active. Next: send the deposit invoice if you have not already.'], 'Open contract', route('admin.contracts.show', $c)));
        $this->client($c->client, new PortalMail('Signed copy of contract '.$c->reference, ['Thank you for signing "'.$c->title.'". Your signed copy, with the standard terms as agreed, is available in your portal at any time.'], 'Download the signed contract', route('documents.contract', $c)));
    }

    public function contractStatus(Contract $c, string $action): void
    {
        $text = match ($action) {
            'activate' => 'Contract '.$c->reference.' ("'.$c->title.'") is now active.',
            'complete' => 'Contract '.$c->reference.' ("'.$c->title.'") has been marked completed. Thank you for working with us.',
            'terminate' => 'Contract '.$c->reference.' ("'.$c->title.'") was terminated effective '.$c->terminated_at?->format('j F Y').'.',
            'cancel' => 'Contract '.$c->reference.' ("'.$c->title.'") was cancelled before it started.',
            default => null,
        };
        if ($text) {
            $this->client($c->client, new PortalMail('Update on contract '.$c->reference, [$text], 'View contract', route('portal.contracts.show', $c)));
        }
    }

    /** Manual or scheduled nudge. Stamps reminded_at and writes to the audit log. */
    public function remind(Invoice|Quotation|Contract $doc, ?User $by = null): string
    {
        [$subject, $lines, $label, $url] = match (true) {
            $doc instanceof Invoice => ['Reminder: invoice '.$doc->reference.' has '.money($doc->balance(), $doc->currency).' outstanding', ['A friendly reminder that invoice '.$doc->reference.' ("'.$doc->title.'") has a balance of '.money($doc->balance(), $doc->currency).($doc->due_date ? (Invoice::dateHasPassed($doc->due_date) ? ', which was due on ' : ', due on ').$doc->due_date->format('j F Y') : '').'.', 'Pay by bank transfer, cheque or cash and submit the payment in your portal so we can confirm it. If you have already paid, please submit the details and ignore this reminder.'], 'View and pay the invoice', route('portal.invoices.show', $doc)],
            $doc instanceof Quotation => ['Reminder: quotation '.$doc->reference.' is waiting for your answer', ['Quotation '.$doc->reference.' ("'.$doc->title.'", '.money($doc->total, $doc->currency).') is still open'.($doc->valid_until ? ' and is valid until '.$doc->valid_until->format('j F Y') : '').'.', 'Accept or decline it in your portal, or reply if you would like any changes.'], 'Review the quotation', route('portal.quotations.show', $doc)],
            default => ['Reminder: contract '.$doc->reference.' is waiting for your signature', ['The agreement "'.$doc->title.'" is ready to sign in your portal. Work starts once it is signed and the deposit is received.'], 'Read and sign the contract', route('portal.contracts.show', $doc)],
        };
        $this->client($doc->client, new PortalMail($subject, $lines, $label, $url));
        $doc->forceFill(['reminded_at' => now()])->save();
        Audit::log($doc, 'reminder_sent', ['by' => $by?->name ?? 'scheduler']);

        return $subject;
    }

    private function client(Client $client, PortalMail $mail): void
    {
        $this->deliver(function () use ($client, $mail) {
            $users = $client->users;
            if ($users->isNotEmpty()) {
                Notification::send($users, $mail);
            } elseif ($client->email) {
                Notification::route('mail', [$client->email => $client->contact_name])->notify($mail);
            } elseif (request()->hasSession()) {
                session()->flash('warning', $client->company.' has no email address or portal login on file, so "'.$mail->subject.'" could not be sent. Add one on the client page.');
            }
        }, $mail);
    }

    private function admins(PortalMail $mail): void
    {
        $this->deliver(fn () => Notification::send(User::where('role', 'admin')->get(), $mail), $mail);
    }

    /**
     * With the sync queue an SMTP problem would surface as a 500 after the record was already saved.
     * Instead the error is reported, the action stands, and the admin sees a warning banner.
     */
    private function deliver(callable $send, PortalMail $mail): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
            if (request()->hasSession()) {
                session()->flash('warning', 'Saved, but the email "'.$mail->subject.'" could not be sent: '.$e->getMessage().' Check the MAIL settings.');
            }
        }
    }
}
