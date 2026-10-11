<?php

/*
|--------------------------------------------------------------------------
| Code Canvas Consultants Ltd — company details and portal options
|--------------------------------------------------------------------------
| Shown on quotations, invoices and the payment instructions page.
| CONFIRM every value marked CONFIRM before going live.
*/

return [
    'name' => 'Code Canvas Consultants Ltd',
    'short' => 'Code Canvas',
    'tagline' => 'Transforming ideas into digital reality.',
    'email' => env('COMPANY_EMAIL', 'mxadmin@codecanvastt.com'),     // as printed on existing invoices
    'trading_name' => env('COMPANY_TRADING_NAME', 'Code Canvas TT'),  // short name used in the document header
    'tax_id' => env('COMPANY_TAX_ID', ''),                           // BIR / VAT number if registered, printed when set
    'phone' => env('COMPANY_PHONE', '+1 (868) 275-3268'),
    'address' => env('COMPANY_ADDRESS', 'Trinidad and Tobago'),       // CONFIRM
    'website' => env('COMPANY_WEBSITE', 'https://codecanvastt.com'),
    'instagram' => 'https://www.instagram.com/code.canvas.tt',
    'facebook' => 'https://www.facebook.com/CodeCanvasConsultants',
    'signatory' => env('COMPANY_SIGNATORY', 'Keron Lewis'),           // name printed as the company signature on contracts
    'registration' => env('COMPANY_REGISTRATION', ''),                // company number, optional

    'currency' => ['code' => 'TTD', 'symbol' => 'TT$'],
    'currencies' => ['TTD' => 'TT$', 'USD' => 'US$'],
    'tax_rate' => 0, // default % applied to new quotations and invoices (VAT if registered)

    // Payment instructions shown to clients. CONFIRM.
    'bank' => [
        'bank_name' => env('BANK_NAME', 'First Citizens Bank'),
        'account_name' => env('BANK_ACCOUNT_NAME', 'Code Canvas Consultants LTD'),
        'account_type' => env('BANK_ACCOUNT_TYPE', 'Chequing'),
        'account_number' => env('BANK_ACCOUNT_NUMBER', ''), // set in .env only
        'branch' => env('BANK_BRANCH', ''),
        'cheque_payable_to' => 'Code Canvas Consultants Ltd',
    ],

    // Document numbering. {year} = four-digit year, {seq:N} = running number padded to N digits.
    // Invoices continue the sequence from the previous invoicing tool (last issued INV-000062).
    'numbering' => [
        'contract' => ['pattern' => 'CT-{year}-{seq:3}', 'start' => 1],
        'quotation' => ['pattern' => 'Q-{year}-{seq:3}', 'start' => 1],
        'invoice' => ['pattern' => 'INV-{seq:6}', 'start' => (int) env('INVOICE_START_NUMBER', 63)],
    ],

    'document_footer' => 'Thank you for your business. Questions: mxadmin@codecanvastt.com · +1 (868) 275-3268',

    // Printed on the second page of every invoice. Edit freely; each "Heading" line followed by text becomes a section.
    'invoice_terms' => [
        'Payment terms' => 'Payment for invoices is due within the number of days shown on the invoice from the invoice date, unless otherwise agreed in writing.',
        'Late payments' => 'A late payment fee of 1.5% per month (equivalent to an 18% APR) will be applied to any unpaid balance after the due date. This fee will accrue monthly until the balance is paid in full.',
        'Payment methods' => 'Payments can be made by bank transfer to the account shown on the invoice, by cheque payable to Code Canvas Consultants Ltd, or in cash in person. Card payments will be available through the client portal. Any bank or transaction fees are the responsibility of the Client.',
        'Disputes' => 'Any disputes regarding an invoice must be submitted in writing within 7 days of the invoice date. After this period, the invoice will be deemed accepted by the Client.',
        'Refund policy' => 'No refunds will be issued for services or products delivered as per the agreed scope. Deposits and milestone payments made for ongoing projects are non-refundable, except in cases of proven negligence or breach of contract by the Developer/Service Provider.',
        'Ownership of deliverables' => 'Full ownership of deliverables will only transfer to the Client upon receipt of full payment for the corresponding invoice.',
        'Termination of services' => 'Failure to pay invoices on time may result in the suspension or termination of services without prior notice. The Developer/Service Provider reserves the right to withhold access to the project or any associated deliverables until outstanding balances are paid.',
        'Project scope and additional charges' => 'The invoice reflects charges based on the agreed project scope. Any additional services requested beyond this scope will incur additional charges and will be invoiced separately.',
        'Taxes' => 'All invoices are exclusive of taxes unless otherwise stated. The Client is responsible for any applicable taxes, duties, or levies associated with the purchase.',
        'Confidentiality' => 'Both parties agree to keep invoice details confidential and not disclose them to any third party without prior consent, except where required by law.',
        'Liability' => 'The Developer/Service Provider is not liable for any indirect, incidental, or consequential damages arising from delays or issues related to unpaid invoices.',
        'Governing law' => 'These terms and conditions are governed by and construed in accordance with the laws of the Republic of Trinidad and Tobago.',
        'Client acknowledgment' => 'By making payment for the invoice, the Client acknowledges that they have read, understood, and agree to these terms and conditions.',
    ],
    // Standard contract terms, printed on every agreement after any special terms. Placeholders are filled per contract:
    // {deposit} {payment_days} {late_fee} {notice} {company} {short} {cycle}.
    'deposit_percent' => 50,
    'late_fee_monthly_percent' => 1.5,
    'contract_terms' => [
        'Deposit and start of work' => 'A deposit of {deposit}% of the contract value is payable before any work begins. The start date is confirmed once the deposit has been received and verified by {short}. Deposits are non-refundable once work has started.',
        'Invoicing and payment' => 'All invoices are issued through the {short} client portal and are payable within {payment_days} days of the invoice date. {cycle} The balance of the contract value is payable before launch, handover or delivery of the final work, whichever comes first.',
        'How payments are made and confirmed' => 'Payments are accepted by bank transfer to the account shown on each invoice, by cheque payable to {company}, or in cash in person. The Client submits each payment in the client portal, attaching a screenshot or PDF of the transfer confirmation for bank transfers, or the cheque number or the name of the person who received cash. A payment counts only once {short} has verified it against its bank, cash or cheque records. Bank charges and transaction fees are the Client\'s responsibility.',
        'Late payment' => 'A late payment fee of {late_fee}% per month accrues on any balance unpaid after its due date until it is paid in full. {short} may pause work, withhold deliverables, suspend hosting or access, and decline further work while any invoice is overdue, without liability for the consequences.',
        'Scope, changes and revisions' => 'The price covers only the scope of work set out in this agreement. Requests outside that scope, additional rounds of revision beyond those listed, and changes to approved work are quoted and invoiced separately before they are carried out. Work paused for more than 30 days awaiting Client content, feedback or approval may be rescheduled and may incur a restart fee.',
        'Client responsibilities' => 'The Client provides content, images, logins, approvals and feedback within a reasonable time and warrants that it holds the rights to any material it supplies. The Client is responsible for the accuracy and legality of its content and for its own compliance obligations.',
        'Third-party costs' => 'Domain names, hosting, software licences, stock assets, payment gateway and email service fees are billed separately or paid directly by the Client, and are not included in the contract value unless listed in the scope.',
        'Ownership and credit' => 'Ownership of the deliverables passes to the Client only on receipt of full payment of all invoices under this agreement. Until then all work remains the property of {company}. {company} retains ownership of its pre-existing tools, code libraries and know-how, and may display the completed work in its portfolio and marketing unless the Client asks in writing that it does not.',
        'Warranty and support' => 'Delivered work is checked to be free of defects at handover. Defects reported in writing within 30 days of handover are corrected without charge. Ongoing updates, security monitoring, backups and support are covered only under a separate maintenance or retainer contract.',
        'Recurring services' => 'Where this agreement is billed on a recurring basis, each cycle is invoiced in advance at the start of the cycle and continues until the end date, or until either party gives {notice} written notice. Fees already invoiced for a cycle are not refundable if the Client stops the service during that cycle.',
        'Termination' => 'Either party may end this agreement in writing. On termination the Client pays for all work completed and costs incurred up to the termination date, and any deposit is retained. {short} may terminate immediately if an invoice remains unpaid 30 days after its due date or if the Client breaches these terms.',
        'Confidentiality' => 'Both parties keep each other\'s business information, pricing and credentials confidential and use them only for this agreement, except where disclosure is required by law or the other party consents in writing.',
        'Limitation of liability' => '{company}\'s total liability under this agreement is limited to the amount actually paid by the Client under it. {company} is not liable for loss of profit, revenue, data or goodwill, or for any indirect or consequential loss, including from downtime, third-party services or delays caused by the Client.',
        'Electronic signature and records' => 'Signing this agreement electronically in the {short} client portal has the same effect as a handwritten signature. The portal\'s records of the agreement, its versions, invoices, payment submissions and verifications are the agreed record of the parties\' dealings.',
        'Governing law' => 'This agreement is governed by the laws of the Republic of Trinidad and Tobago, and the parties submit to the jurisdiction of its courts.',
    ],
    'quotation_terms' => [
        'Validity' => 'This quotation is valid until the date shown. Prices may change after that date.',
        'Acceptance' => 'Accepting the quotation in the client portal, or in writing, confirms the scope and price. A contract and deposit invoice follow.',
        'Scope' => 'Work outside the listed items is quoted separately.',
        'Taxes' => 'Prices are exclusive of taxes unless otherwise stated.',
    ],

    'contract_types' => [
        'website' => 'Website build',
        'booking-system' => 'Booking system',
        'dashboard' => 'Dashboard or admin portal',
        'maintenance' => 'Maintenance and support',
        'hosting' => 'Hosting and domain',
        'design' => 'Design and branding',
        'consulting' => 'Consulting',
        'retainer' => 'Retainer (ongoing work)',
        'other' => 'Other',
    ],

    // How the contract amount is charged. 'per' is the short label after the amount; 'months' is the
    // cycle length used to work out what is due to date (weekly uses days).
    'billing_cycles' => [
        'one_time' => ['label' => 'One-off (fixed price)', 'per' => null, 'months' => null, 'days' => null],
        'weekly' => ['label' => 'Weekly', 'per' => 'week', 'months' => null, 'days' => 7],
        'monthly' => ['label' => 'Monthly', 'per' => 'month', 'months' => 1, 'days' => null],
        'quarterly' => ['label' => 'Quarterly (every 3 months)', 'per' => 'quarter', 'months' => 3, 'days' => null],
        'half_yearly' => ['label' => 'Half-yearly (every 6 months)', 'per' => '6 months', 'months' => 6, 'days' => null],
        'yearly' => ['label' => 'Yearly', 'per' => 'year', 'months' => 12, 'days' => null],
    ],

    // Lifecycle: draft -> approved -> (signature) -> active -> completed | expired | terminated. cancelled = never started.
    'contract_statuses' => ['draft' => 'Draft', 'approved' => 'Approved', 'active' => 'Active', 'completed' => 'Completed', 'expired' => 'Expired', 'terminated' => 'Terminated', 'cancelled' => 'Cancelled'],
    'contract_live_statuses' => ['active'],                       // count toward recurring revenue and forecasts
    'contract_closed_statuses' => ['completed', 'expired', 'terminated', 'cancelled'],
    'renewal_alert_days' => 60,                                   // flag contracts ending within this window
    'usd_rate' => env('USD_RATE', 6.80),                          // TTD per 1 USD, indicative only, printed on reports
    'quotation_statuses' => ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired'],
    'invoice_statuses' => ['draft' => 'Draft', 'sent' => 'Sent', 'partial' => 'Partly paid', 'paid' => 'Paid', 'overdue' => 'Overdue', 'cancelled' => 'Cancelled'],

    // Online card payments are a later phase; these are the methods accepted today.
    'payment_methods' => [
        'bank_transfer' => ['label' => 'Bank transfer', 'proof' => true, 'hint' => 'Upload a screenshot or PDF of the transfer confirmation.'],
        'cash' => ['label' => 'Cash in hand', 'proof' => false, 'hint' => 'Tell us who you handed it to and when.'],
        'cheque' => ['label' => 'Cheque', 'proof' => false, 'hint' => 'Enter the cheque number as the reference.'],
        // Recorded by an admin when an invoice is marked paid by hand; never offered to clients.
        'other' => ['label' => 'Settled outside the portal', 'proof' => false, 'hint' => 'Recorded by Code Canvas.', 'admin_only' => true],
        // Adjustments: a credit note reduces what is owed (not money received); a refund is money paid back (negative).
        'credit_note' => ['label' => 'Credit note', 'proof' => false, 'hint' => 'Reduces the amount owed.', 'admin_only' => true, 'adjustment' => true],
        'refund' => ['label' => 'Refund', 'proof' => false, 'hint' => 'Money paid back to the client.', 'admin_only' => true],
    ],
    'payment_statuses' => ['pending' => 'Awaiting verification', 'verified' => 'Verified', 'rejected' => 'Rejected'],

    'document_terms' => 'Payment due within 14 days of the invoice date unless otherwise agreed. Work begins once the deposit clears.',
];
