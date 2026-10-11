# Code Canvas Portal — deploying to Hostinger (`public_html`)

Same layout as the All Things Tobago site: the whole project goes into `public_html`,
the root `.htaccess` sends every request into `public/` and blocks direct access to
`.env`, `vendor/`, `storage/` and the rest. The lock file is pinned to PHP 8.3.

The live site is `https://portal.codecanvastt.com` (hPanel → Domains → Subdomains) with its
document root set to that subdomain's `public_html`. The MySQL database and user are both `u269010508_ccportal`.

## 1. hPanel

1. **PHP**: Websites → PHP configuration → **8.3** or **8.4**, with `pdo_mysql`, `mbstring`, `fileinfo`, `gd` enabled (default).
2. **MySQL**: Databases → create a database and user; note the three values.
3. **Email**: use the `mxadmin@codecanvastt.com` mailbox (or create one) and put its SMTP password in `.env`. The portal emails clients when quotations, invoices and contracts are sent, when payments are verified, and for reminders; you receive a copy of each and a note when a client submits a payment, signs, or answers a quotation.
4. **SSL**: Security → SSL → active for the subdomain.

## 2a. Upload the release zip (no Composer needed on the server)

`scripts/build-release.sh` builds `release/portal.codecanvastt.com.zip` on your machine: production-only
`vendor/`, built assets, and a `.env` that already has the domain, database name and user, and a
fresh `APP_KEY`. Only two values are left blank: `DB_PASSWORD` and `MAIL_PASSWORD`.

1. hPanel → File Manager → the subdomain's `public_html` (empty it first if it has a placeholder
   `default.php`). Upload the zip, right-click → Extract, then delete the zip.
2. Edit `.env` in File Manager and fill in `DB_PASSWORD` and `MAIL_PASSWORD`.
3. hPanel → Advanced → SSH access, then:

```sh
cd ~/public_html            # or ~/domains/portal.codecanvastt.com/public_html
php artisan migrate --force
php artisan portal:admin    # your login (prompts for a password)
chmod -R ug+rwx storage bootstrap/cache
php artisan optimize
```

## 2b. Or `git clone` into `public_html` and build on the server (SSH)

```sh
cd ~/public_html
php /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
cp .env.hostinger.example .env
php artisan key:generate --force
nano .env            # APP_URL, DB_*, MAIL_*, COMPANY_* and BANK_* values
php artisan migrate --force
php artisan portal:admin                         # your login (prompts for a password)
chmod -R ug+rwx storage bootstrap/cache
php artisan optimize
```

Then add one cron job in hPanel → Advanced → Cron Jobs (every minute):

```
* * * * * cd ~/public_html && php artisan schedule:run >> /dev/null 2>&1
```

It expires finished contracts nightly, emails you renewals due on Mondays and reminds clients about overdue invoices on Tuesdays, at most once a week per invoice.

If `composer` is not on the path, use `php /usr/local/bin/composer`, or upload
`composer.phar` and run `php composer.phar install --no-dev`.

`public/build/` is committed, so no Node is needed on the server. Payment screenshots and
signed contracts are stored in `storage/app/private/` and served through the app with an
access check, so no `storage:link` is needed and they are never publicly reachable.

Never run `php artisan db:seed` on production: it creates demo clients with the password "password".

## 3. Check

- `/login` loads with styles. `/.env` returns 403. `/up` returns OK.
- Add a test client with "Create a portal login and email the welcome" ticked, using an address you can read.
  The welcome email arrives with the sign-in details.
- In an incognito window, sign in as that client: the welcome screen appears first, and the dashboard says
  "Your portal is ready" with no payment options.
- As admin, create a quotation and use **Save and send to client**. As the client, accept it: you land on the
  generated contract. Sign it: you land on the deposit invoice, and **Submit a payment** appears.
- Submit a bank transfer with a screenshot, then verify it as admin.
- Forgot-password sends an email (needs the MAIL_* values).
- Deactivate the test client afterwards (inactive clients are never emailed).

## Updating later

```sh
cd ~/public_html && git pull && php /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction && php artisan migrate --force && php artisan optimize:clear && php artisan optimize
```
