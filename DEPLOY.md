# Code Canvas Portal — deploying to Hostinger (`public_html`)

Same layout as the All Things Tobago site: the whole project goes into `public_html`,
the root `.htaccess` sends every request into `public/` and blocks direct access to
`.env`, `vendor/`, `storage/` and the rest. The lock file is pinned to PHP 8.3.

The live site is `https://portal.codecanvastt.com` (hPanel → Domains → Subdomains) with its
document root set to that subdomain's `public_html`. The MySQL database and user are both `u269010508_ccportal`.

## 1. hPanel

1. **PHP**: Websites → PHP configuration → **8.3** or **8.4**, with `pdo_mysql`, `mbstring`, `fileinfo`, `gd` enabled (default).
2. **MySQL**: Databases → create a database and user; note the three values.
3. **Email**: mail goes out through **Zoho** (smtppro.zoho.com:465), which codecanvastt.com's SPF and DKIM records
   already authorise, so messages pass SPF, DKIM and DMARC and stay out of junk. It needs a paid Zoho plan (the free
   plan blocks SMTP) and a Zoho app-specific password in `MAIL_PASSWORD`. `MAIL_FROM_ADDRESS` must be that Zoho mailbox. The portal emails clients when quotations, invoices and contracts are sent, when payments are verified, and for reminders; you receive a copy of each and a note when a client submits a payment, signs, or answers a quotation.
4. **SSL**: Security → SSL → active for the subdomain.

## 2a. Upload the release zip (no Composer needed on the server)

`scripts/build-release.sh` builds `release/portal.codecanvastt.com.zip` on your machine: production-only
`vendor/`, built assets, and a `.env` that already has the domain, database name and user, and a
fresh `APP_KEY`. Only two values are left blank: `DB_PASSWORD` and `MAIL_PASSWORD`.

This zip is for the **first install only**: its `.env` has a new `APP_KEY`, and uploading it over a live site
signs everyone out and breaks anything already encrypted. Update a live site with `scripts/deploy.sh` (below).

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
* * * * * cd ~/public_html && php artisan schedule:run >> storage/logs/cron.log 2>&1
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

## Backups (set up once)

Payment proofs, signatures and signed contracts exist only on the server, so back them up with the database every night.
Add a second cron job (hPanel → Advanced → Cron Jobs):

```
30 2 * * * bash ~/public_html/scripts/backup.sh >> ~/backups/backup.log 2>&1
```

It writes `~/backups/db-<date>.sql.gz` and `~/backups/files-<date>.tgz` (uploads and `.env`), outside `public_html`, and keeps 30 days.
Download `~/backups` (or sync it to Google Drive) at least weekly, and do one test restore:

```sh
gunzip < ~/backups/db-YYYY-MM-DD-HHMM.sql.gz | mysql -u u269010508_ccportal -p u269010508_ccportal
tar -xzf ~/backups/files-YYYY-MM-DD-HHMM.tgz -C ~/public_html
```

## Updating later (Hostinger Git auto-deploy)

Hostinger's Git auto-deploy pulls each push into `public_html`, but runs nothing after the pull. `scripts/post-deploy.sh`
finishes every deploy: it installs PHP packages when `composer.lock` changed, and when migrations are pending it puts the
site in maintenance mode, backs up, and migrates. It then rebuilds Laravel's caches, so new pages and routes never meet
stale cached ones. Set it up once as a cron job (hPanel → Advanced → Cron Jobs), every minute:

```
* * * * * bash ~/public_html/scripts/post-deploy.sh >> ~/public_html/storage/logs/deploy.log 2>&1
```

It exits immediately when nothing new was pulled. After a push, the site is fully updated within about a minute;
`storage/logs/deploy.log` shows each deploy.

Every push to the deployed branch goes live, even if GitHub's checks (Actions tab) fail, so only push to it once the
checks are green. Keep work in progress on another branch, or point Hostinger's auto-deploy at a `production` branch
and merge into it when ready.

**Rolling back:** with auto-deploy, undo the change in git and push again (`git revert <commit> && git push`). The
deploy log shows the previous commit. If the bad deploy ran migrations, restore the backup it took (`~/backups/db-*.sql.gz`).

`scripts/deploy.sh` and `scripts/rollback.sh` are for servers that are **not** on auto-deploy: they pull and reset the
code themselves, which would fight with Hostinger's own pulls.

Uptime: point a free monitor (UptimeRobot or Better Stack) at `https://portal.codecanvastt.com/login`.
