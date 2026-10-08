# Code Canvas Portal

Client portal for Code Canvas Consultants Ltd. Laravel 13, Tailwind 4, Alpine. No JavaScript
framework, no build step on the server.

- **Admin** (`/admin`): clients and their portal logins, contracts (type, dates, amount, status,
  signed copy, paid state), quotations and invoices with line items and print/PDF view, payment
  verification queue.
- **Client portal** (`/portal`): contracts with balance, quotations to accept or decline,
  invoices with bank details, payment submission (bank transfer requires a screenshot; cash
  and cheque need a reference) and payment status.
- Email notifications to you and the client when quotations, invoices and contracts are sent, answered,
  signed, paid or verified, plus one-click reminders and a weekly automatic overdue-invoice reminder.
- Online card payments are a later phase; the payment model already has a `method` column for it.

## Local

```sh
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed     # demo data: admin and client logins below
npm run build && php artisan serve
```

| Login | Email | Password |
|---|---|---|
| Admin | hello@codecanvastt.com | password |
| Client (All Things Tobago) | client@example.com | password |
| Client (Island Eats) | marcia@example.com | password |

Quotations, invoices and contracts are laid out into Letter-size pages by Paged.js in the browser,
so the on-screen preview, page count and running footers match the printed PDF exactly. Terms are
edited under Admin → Terms. Invoice numbers continue from the previous tool at INV-000063.

Company and bank details live in `config/company.php` and can be overridden with the
`COMPANY_*` and `BANK_*` keys in `.env`.

See `DEPLOY.md` for Hostinger.
