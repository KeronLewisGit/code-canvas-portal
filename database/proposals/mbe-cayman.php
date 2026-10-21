<?php

/*
| Proposal for Mail Boxes Etc. Cayman Islands (mbe.ky): US$100 a month on a rolling plan, billed at the end of the
| month, with a one-time US$700 handover fee if they leave. Applied with:  php artisan portal:draft-proposal mbe-cayman
| The quotation and the contract are both saved as drafts, so nothing is emailed and the client sees neither yet.
*/

return [
    // Used to find the client by name, and to create it when --create-client is given.
    'client' => ['search' => 'Mail Boxes Etc', 'company' => 'Mail Boxes Etc. Cayman Islands', 'contact_name' => 'Julie', 'website' => 'https://mbe.ky'],

    'quotation' => [
        'title' => 'mbe.ky website rebuild and managed plan',
        'currency' => 'USD',
        'valid_days' => 30,
        'contract_type' => 'maintenance',
        'billing_cycle' => 'monthly',
        'intro' => 'A complete rebuild of mbe.ky in the global Mail Boxes Etc. look, with Cayman imagery and copy, on one fixed monthly fee. There is no build invoice and no fixed term.',
        'notes' => "Prices are in US dollars. Billing starts at the end of the month in which the site launches, prorated for the days it was live, then monthly.\nThe plan renews month to month on 30 days' notice. If you leave, a one-time handover fee of US$700 buys the website outright and moves it to managed hosting in your name. The fee is waived if Code Canvas stops trading.",
        'items' => [
            ['description' => "Redesign and rebuild of mbe.ky: every current page, E-box and Ocean Ship estimators, enquiry forms, staff admin area, two revision rounds, testing and launch.\nIncluded in the monthly plan; no build invoice.", 'quantity' => 1, 'unit_price' => 0],
            ['description' => 'Managed plan, per month: hosting with CDN and SSL, daily backups, security updates, 24/7 uptime monitoring, up to 3 hours of content changes, email, WhatsApp and phone support, and a quarterly review call.', 'quantity' => 1, 'unit_price' => 100],
        ],
    ],

    'contract' => [
        // Placeholder for the launch date (four weeks after approval); set the real date before sending.
        'start_in_days' => 28,
        'notice_period_days' => 30,
        'payment_terms_days' => 14,
        'deposit_percent' => 0,
        'scope' => "- Redesign and rebuild of mbe.ky in the global Mail Boxes Etc. look: every current page, E-box and Ocean Ship estimators, enquiry forms and a staff admin area.\n- Two revision rounds, testing on phones, tablets and desktops, and launch with existing web addresses preserved.\n- Managed hosting with CDN and SSL, daily backups, security updates and 24/7 uptime monitoring.\n- Up to 3 hours of content changes a month, email, WhatsApp and phone support, and a quarterly review call.\n- A 30-day warranty after launch.",
        'terms' => <<<'TERMS'
1. Fee and billing. The fee is US$100 per month. Billing starts at the end of the month in which the website launches on mbe.ky, and the first invoice is prorated for the days the site was live. After that, one invoice is issued at the end of each month and is payable within 14 days. No deposit is payable. This replaces the deposit and the advance-billing wording elsewhere in this agreement.

2. Term and notice. There is no fixed term. The agreement renews month to month, and either party may end it on 30 days' written notice. The fee is fixed for the first 24 months; after that any change needs 60 days' written notice, and the Client may give notice instead.

3. Handover fee. If the Client ends this agreement for any reason, a one-time handover fee of US$700 is payable. On payment, Code Canvas moves the website, files and database to managed hosting in the Client's name or to the Client's developer, confirms it works, and hands over all files and logins. The fee is the same whenever the Client leaves. After the move, the hosting provider bills the Client directly. No other termination fee applies.

4. Ownership. The Client's content, logos, photos, domain name and customer data belong to the Client at all times. The design and code are provided as part of the monthly plan and become the Client's property outright when the handover fee is paid. This replaces the ownership, deposit and termination terms below.

5. If Code Canvas stops trading. If Code Canvas ceases operations or cannot meet its support commitments, the handover fee is waived and the full website, files and hosting access transfer to the Client free of charge.

6. Included and extra work. Up to 3 hours of content changes a month are included. Other work is quoted in advance, or charged at US$50 per hour, and is not started without the Client's written approval. New functionality such as online payments, a customer portal or API integrations is quoted separately.
TERMS,
    ],
];
