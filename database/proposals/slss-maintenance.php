<?php

/*
| Success Laventille Secondary School (slss.edu.tt): the June 2026 "Website Maintenance Service Proposal", re-issued as a
| portal contract for signature, now invoiced at the END of each four-month term, plus the three earlier invoices that were paid in
| cash. Applied with:  php artisan portal:draft-proposal slss-maintenance
| The contract is saved as a draft: nothing is emailed until you press Send for signature.
|
| CHECK BEFORE SENDING (these were not in the source documents):
|  - 'amount': TT$2,600 a term, billed every 4 months, as the school pays it.
|  - 'start_date': 1 Feb 2027, four months after the term invoiced on INV-000063 (9 Oct 2026) is assumed to begin.
|  - 'paid_on' on each earlier invoice: set to the date the cash was really received. It is the invoice date for now.
*/

return [
    'client' => ['search' => 'Laventille', 'company' => 'Success Laventille Secondary School', 'contact_name' => 'The Principal', 'website' => 'https://slss.edu.tt'],

    'contract' => [
        'title' => 'Website maintenance, 4-month terms: slss.edu.tt',
        'type' => 'maintenance',
        'billing_cycle' => 'four_monthly',
        'bill_in_arrears' => true,
        'amount' => 2600,
        'currency' => 'TTD',
        'start_date' => '2027-02-01',
        'notice_period_days' => 30,
        'payment_terms_days' => 14,
        'deposit_percent' => 0,
        'scope' => <<<'SCOPE'
Services included in the maintenance package for slss.edu.tt (billed every four months):
- Content updates: add and edit school announcements, events, news posts and staff information; remove outdated notices and keep all pages current.
- Registration management: update and manage the Form 1 online registration form each term, and keep submission data accurate and accessible.
- Multimedia updates: upload photos, videos and documents such as graduation photos, newsletters and timetables.
- Plugin and theme maintenance: keep WordPress core, theme and plugins up to date for security and performance.
- Security monitoring: monitor for vulnerabilities, spam and unauthorised access, and maintain Cloudflare and SSL certificate health.
- Performance optimisation: monthly checks on page speed, broken links and mobile responsiveness, with fixes applied as needed.
- Uptime monitoring: monitor site availability and respond to any downtime.
- Email and domain support: assist with school email, and with domain renewal reminders and configuration.
- Monthly report: a brief end-of-month summary of changes made, site health and recommendations.

Website restoration (annual, invoiced separately): major restoration work, structural updates and any significant rebuilding needed to keep the website fully operational each school year.

Response times for requests submitted during an active term:
- Website restoration: scheduled and started within 3 business days of payment.
- Content update (text, announcements): within 48 business hours.
- Image or file upload: within 48 business hours.
- Site downtime or critical error: within 24 business hours.
- Security issue: within 12 business hours.
- Monthly report: within 5 days of month-end.
SCOPE,
        'terms' => <<<'TERMS'
1. Fees. The website maintenance package, with all the services listed in the scope, is TT$2,600.00 per four-month term, as in the original proposal. The annual website restoration and hosting renewal fee of TT$2,400.00 is due at the start of each school year and is invoiced separately. All prices are in Trinidad and Tobago Dollars (TTD).

2. Billing and payment. Each term's maintenance is invoiced at the end of that four-month term and is payable within 14 days of the invoice date. No deposit is payable. Payment is accepted by bank transfer, by cheque payable to Code Canvas Consultants Ltd, in cash, or online as details are provided. A receipt is issued for each payment. This replaces the deposit and advance-billing wording elsewhere in this agreement.

3. Earlier invoices. Invoices INV-000049, INV-000051 and INV-000063 were paid in cash and are recorded as paid, with receipts, in the client portal.

4. Term and notice. There is no fixed end date. Either party may end the maintenance agreement with 30 days' written notice, and a pro-rated refund is issued for any paid time that is unused.

5. Credentials and assets. All login credentials and site assets remain the property of Success Laventille Secondary School at all times. This replaces the ownership and termination terms below where they differ.

6. Scope. The maintenance package covers slss.edu.tt only. Work on other subdomains or external platforms, and any request outside the scope above, such as new page design or custom feature development, is quoted separately as additional work.

7. Content. The school provides content to be published in good time. Delays in providing content may affect turnaround times.
TERMS,
    ],

    // Invoices already issued and paid in cash. They go on the profile under their original numbers, with receipts, and nobody is emailed.
    'history' => [
        [
            'reference' => 'INV-000049', 'issue_date' => '2026-06-01', 'due_date' => '2026-06-01', 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => '2026-06-01',
            'title' => 'Hosting renewal and advanced website management',
            'items' => [
                ['description' => 'Hosting Renewal', 'quantity' => 1, 'unit_price' => 2400],
                ['description' => "Advanced Website Management\nMonthly service includes content updates, security monitoring, software and plugin updates, regular backups, and performance monitoring.\nAlso includes advanced SEO optimization, uptime monitoring, malware scans and removal, performance optimization, staging environment support, and priority support.", 'quantity' => 1, 'unit_price' => 2600],
            ],
        ],
        [
            'reference' => 'INV-000051', 'issue_date' => '2026-06-12', 'due_date' => '2026-07-27', 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => '2026-06-12',
            'title' => 'Website restoration',
            'items' => [['description' => 'Website Restoration', 'quantity' => 1, 'unit_price' => 750]],
        ],
        [
            'reference' => 'INV-000063', 'issue_date' => '2026-10-09', 'due_date' => '2026-10-09', 'currency' => 'TTD', 'method' => 'cash', 'paid_on' => '2026-10-09',
            'title' => 'Advanced website management',
            'items' => [['description' => "Advanced Website Management\nMonthly service includes content updates, security monitoring, software and plugin updates, regular backups, and performance monitoring.\nAlso includes advanced SEO optimization, uptime monitoring, malware scans and removal, performance optimization, staging environment support, and priority support.", 'quantity' => 1, 'unit_price' => 2600]],
        ],
    ],
];
