# How to create a new client project

The platform is **one deployment per client**: a separate copy of the code, its own database and its own `backend/.env`. Nothing is shared between clients at runtime.

**Layout.** The project is three repositories, cloned side by side in folders named `backend/` (the Laravel API: the repository `jhkstore_backend`; run every `php artisan` and `composer` command inside this folder), `admin-frontend/` (the admin panel, repository `jhkstore_admin-frontend`, built into `backend/public/admin-assets`) and `frontend/` (the static storefront, repository `jhkstore_frontend`, see [STOREFRONT.md](STOREFRONT.md)). The guides are in `backend/docs/`. On **cPanel** the backend goes into a folder of its own with the domain's document root pointing at `backend/public`; the storefront goes to Cloudflare Pages (or a second cPanel folder, see STOREFRONT.md).

## 1. Create the project

1. Create a new repository from this one (fork, or "use as template") and name it after the client. Keep this repo as the upstream you pull fixes from.
2. Pick the names you will use for the client and write them down once:

   | Item | Example |
   |---|---|
   | Database | `acme_db` (tests: `acme_test`) |
   | App name | `Acme Store` |
   | Public API URL | `https://api.acme.example` |
   | Admin panel URL | same host, `/admin` |

## 2. Configure the environment

```bash
cd backend
cp .env.example .env
php artisan key:generate
```

Edit `backend/.env`:

| Key | Set it to |
|---|---|
| `APP_NAME` | the client's store name (also used as the default mail sender name) |
| `APP_URL` | the public API URL (image URLs are built from it) |
| `APP_TIMEZONE` | the client's time zone, e.g. `Europe/Berlin` – set it **before the first order** |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | the new database |
| `FRONTEND_URLS` | the storefront origin(s) allowed by CORS (comma separated) |
| `SANCTUM_STATEFUL_DOMAINS` | the admin panel host(s), e.g. `api.acme.example` |
| `SEED_DEVELOPER_USERNAME`, `SEED_DEVELOPER_PASSWORD` | the **developer** account: builds and runs the site, never sees orders (12+ characters in production) |
| `SEED_ADMIN_USERNAME`, `SEED_ADMIN_PASSWORD` | the **admin** account: the client (12+ characters in production; the seeder refuses weak passwords and identical usernames) |
| `ADMIN_TOKEN_MINUTES` | how long an admin API token (scripts) lives (default 720 = 12 hours; 0 = never) |
| `SHOP_MAX_ITEMS_PER_ORDER`, `SHOP_MAX_QTY_PER_LINE`, `SHOP_HONEYPOT_FIELD`, `CHECKOUT_PER_MINUTE`, … | optional: the limits of the public checkout (defaults in `backend/.env.example` and `backend/config/shop.php`) |
| `CART_PER_MINUTE`, `COUPON_PER_MINUTE`, `PAYMENT_PROOF_PER_MINUTE`, `PAYMENT_PROOF_PER_ORDER_HOUR`, `PAYMENT_PROOF_MAX_KB` | optional: limits of the cart, the coupon check and the bank transfer proof upload (defaults 60, 30, 10, 10, 4096; see `backend/config/shop.php`) |
| `SESSION_LIFETIME` | minutes an admin panel session stays valid without activity (default 120) |
| `QUEUE_CONNECTION` | `database` (the default): order e-mails and push messages are queued, see step 3 |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | the client's mail server (or leave them and set the account in the panel instead: **Settings → E-mail**, the developer's tab, see below) (`MAIL_MAILER=log` only writes mails to `backend/storage/logs/laravel.log`: fine for development, never for production) |
| `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | admin push notifications: run `php artisan shop:vapid`, copy the three lines and set the subject to `mailto:you@client.example` (on Windows PHP may need `OPENSSL_CONF` pointing at its `openssl.cnf`). Empty = push is off |
| `PRODUCT_VIDEO_MAX_KB`, `PRODUCT_VIDEO_POSTER_MAX_KB` | optional: size limit of an uploaded product video and its poster (defaults 51200 = 50 MB and 2048) |
| `WHOLESALE_PER_HOUR`, `WHOLESALE_PER_PHONE_HOUR` | optional: wholesale requests an hour per IP and per phone number (default 5 each) |
| `FILESYSTEM_DISK` | the client's storage |
| `SESSION_SECURE_COOKIE=true` | in production (HTTPS) |

## 3. Create the database and install

```bash
cd backend
# create the empty database (and the test database) in MySQL first
composer install --no-dev --optimize-autoloader
php artisan migrate --force --seed       # tables, default settings, the two admin accounts
php artisan storage:link
cd ../admin-frontend && npm install && npm run build && cd ../backend     # admin panel -> backend/public/admin-assets
```

**Cron.** Add the entry `* * * * * php artisan schedule:run`. It runs the daily jobs: removal of expired admin API tokens and `shop:prune-carts` (deletes carts that were not touched for 30 days). Without it the carts table only grows.

**The mail account in the panel.** Instead of `MAIL_*` in `.env` the developer can set the account that sends the e-mails in **Settings → E-mail**: pick a common service (Gmail, Outlook, Brevo, Mailgun, cPanel) to fill in the server, port and encryption, type the user name, the password (stored encrypted, never shown again) and the sender address, switch on *Send with this mail account*, save, and press *Send test* (sent at once with the saved account, so a wrong password shows as an error there). Off = the `.env` values are used. A queue worker follows a change without a restart (it applies the account before every job). The tab belongs to the developer; to let the client manage it, switch the *E-mail account* permission on in Access Control. **Send e-mails** (Notifications tab) must be on, and the sender address must be one the account may send as (Gmail and Outlook also need an app password).

**Queue worker.** E-mails and push notifications are queued (after the order is saved, so a slow or broken mail server never delays or fails an order). Something has to work the queue; pick one:

1. **A worker** (VPS, recommended): `php artisan queue:work --tries=3` kept alive by supervisor or systemd, restarted after each deployment (`php artisan queue:restart`).
2. **Cron** (shared hosting, no long-running process): `* * * * * php artisan queue:work --stop-when-empty --max-time=55` (a mail can be up to a minute late).
3. **Nothing** (the safety net): when no worker is seen (see the *Queue worker* line of Settings → Notifications), the mails and pushes of an order are sent by the web request itself, right after its response (`SHOP_JOBS_WITHOUT_WORKER=false` switches this off), so they are never stuck in the `jobs` table. A worker or the cron stays better: it retries and takes the load off the web server.

**On your own computer** `php artisan serve` starts the queue listener and the scheduler next to the server (so mails, push and the *Queue worker* card work without more terminals) and switches PHP's OPcache on (about six times faster answers). `--no-helpers` and `--no-opcache` leave them out. It does this only when `APP_ENV=local`.

The worker reads the same `backend/.env` as the site, and a long-running worker keeps the settings it started with: after changing `MAIL_*`, `VAPID_*` or `APP_TIMEZONE` run `php artisan queue:restart`. A mail already in the queue remembers the mailer it was queued for.

The panel's *Notification status* (Settings → Notifications) says whether a worker is running. It learns it from every job a worker finishes, and from `shop:queue-heartbeat`, a tiny job the scheduler (the `schedule:run` cron below) queues every minute; without the cron the card says "no activity seen yet" until the first job, and the *Send test notification* button settles it. **Push**: `php artisan shop:vapid` (on Windows set `OPENSSL_CONF` first), the three values in `backend/.env`, the *Admin push* switch on in Settings → Notifications, HTTPS in production; test it with [PUSH_TEST.md](PUSH_TEST.md).

Failed jobs are listed with `php artisan queue:failed`, retried with `php artisan queue:retry all` and cleared with `php artisan queue:flush`; Settings → Notifications shows how many failed (a count only). The sidebar badges for pending orders and new wholesale requests refresh every 30 seconds and work without a worker. The e-mails stay off until Settings → Notifications → *Send e-mails* is on.

**Time zone.** `APP_TIMEZONE` decides what "today" is, the dates of orders, the dashboard ranges and the start and end of discounts, coupons and banners. Set it before the first order; the admin panel shows and accepts every date in that zone.

**Push.** Browser push works on HTTPS (or localhost). After `shop:vapid` and the three `VAPID_*` values, switch *Admin push notifications* on in Settings → Notifications; each admin then presses the bell in the panel header on their device (the browser asks for permission only then). The service worker is `backend/public/admin-sw.js` (served at `/admin-sw.js`, scope `/admin/`): make sure the web server serves that file as a static file.

**Payment proofs are private.** Bank transfer screenshots are stored on the `local` disk (`backend/storage/app/private`). That folder must be writable by the web server and **must not be served by the web server** (only `public/` and `backend/storage/app/public` via `storage:link` are public). Set PHP `upload_max_filesize` and `post_max_size` to at least 4 MB (the proof limit is 4 MB, `PAYMENT_PROOF_MAX_KB`). Back the folder up with the database.

**Product videos** (uploaded mp4 / webm, at most 2 a product) are public files in `backend/storage/app/public` (`php artisan storage:link`). Raise PHP `upload_max_filesize` and `post_max_size` to at least `PRODUCT_VIDEO_MAX_KB` (50 MB by default; also nginx `client_max_body_size` or Apache `LimitRequestBody`) and `max_execution_time` for slow uploads, otherwise the panel says the server refused the file. Nothing is converted: ask the client for small web-ready files, or use a YouTube / Vimeo link.

## 3b. On cPanel (PKWebHost): where the folders go

The Laravel app must **never** be inside the web root: only `backend/public` is public.

1. **Upload** the `backend/` folder (everything: `app`, `bootstrap`, `config`, `database`, `public`, `resources`, `routes`, `storage`, `vendor`, `artisan`, `.env` ...) to a folder **next to** the web root, for example `/home/USER/jhkstore/backend`. If the host has no Composer, run `composer install --no-dev --optimize-autoloader` on your computer and upload `vendor/` too. Do not upload `tests/`, `node_modules/` or your local `.env` (create the real one on the server from `.env.example`).
2. **Document root.** In cPanel → *Domains* (or *Subdomains*) create `api.example.com` and set its **document root to `/home/USER/jhkstore/backend/public`**. Nothing else of the app is then reachable from the web. (If the host cannot change the document root, put the *contents* of `backend/public` into `public_html/api` and change the two paths in its `index.php` to the backend folder; the first way is better.)
3. **PHP.** Select PHP 8.3 for the domain (MultiPHP Manager) with the extensions `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `exif`, `curl`, `intl`; raise `upload_max_filesize` and `post_max_size` (MultiPHP INI Editor) as described above.
4. **Database and `.env`.** Create the MySQL database and user in cPanel, write `backend/.env` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://api.example.com`, the `DB_*`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS=api.example.com`, `SESSION_SECURE_COOKIE=true`, mail), then in the cPanel *Terminal* (or over SSH): `cd ~/jhkstore/backend && php artisan key:generate && php artisan migrate --force --seed && php artisan storage:link && php artisan config:cache && php artisan route:cache`.
5. **The admin panel** is already inside `backend/public/admin-assets` when you build it before the upload: `cd admin-frontend && npm install && npm run build` on your computer (it writes into `backend/public/admin-assets`), then upload `backend/public/admin-assets`. If the server has Node you can build there instead. It is served at `https://api.example.com/admin`; its push service worker is `backend/public/admin-sw.js` (served at `/admin-sw.js`).
6. **Cron** (cPanel → *Cron Jobs*): `* * * * * cd /home/USER/jhkstore/backend && php artisan schedule:run >> /dev/null 2>&1` and, when no worker can stay alive, `* * * * * cd /home/USER/jhkstore/backend && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1`.
7. **Updates**: upload the changed files, run `php artisan migrate --force`, `php artisan config:cache`, `php artisan queue:restart`. Rebuild and upload `backend/public/admin-assets` when the panel changed.
## 4. Configure the store (admin panel → Settings)

**Who sees what (Phase C).** The client admin starts with the contact details (General), Store, Delivery, Payments and Notifications (which e-mails and push messages go out). Everything technical (store name, tagline, country, order number prefix, cash limit, currency, tax, footer and agency credit, SEO, branding, banners, the mail sender and owner address, the tracking link, delivery time and unmatched-address rule) is `advanced`: fill it yourself as the developer, and switch `settings.advanced` on in Access Control only for a technical client. See [ACCESS_CONTROL.md](ACCESS_CONTROL.md).

Log in at `/admin` as the **developer** and set, in this order (see [SETTINGS.md](SETTINGS.md) for every field):

1. **General** – name, tagline, contact email / phone / address, country, and the **default phone country code** (e.g. `+92`: customers who type `0300…` and `+92 300…` become one customer; set it **before the first order**). **Branding** – logo and favicon.
2. **Currency & Tax** – code, symbol and decimals (e.g. `EUR`, `€`, 2); default tax rate, whether prices include tax, and the label ("VAT", "Sales tax", …). A product can override the rate.
3. **Orders & Delivery** – order number prefix, minimum order, cash-on-delivery limit.
4. **Delivery** – the method (`flat`, `free`, `free_above` (default), `zones`, `owner_confirms`), delivery charge, free-delivery limit, delivery days, and for `zones` the **zone manager** (city / area / postcode zones with fee, free-above, minimum order, ETA; put the most specific zone first) and what happens to an address outside every zone.
5. **Payments** – cash on delivery on / off, **bank transfer** (add at least one active account first; instructions such as "use the order number as the reference"), and whether to show the "card, coming soon" label.
6. **Store** – open / closed (a closed store takes no orders), the closed message, a checkout notice and the delivery estimate text.
7. **Theme** (developer only) – colours, fonts, corner radius and shadow of the storefront; the storefront reads them from `GET /store/settings`.
8. **Footer & Social** (including the agency credit: the name and link default to Rivoxa Tech / https://rivoxa.tech; upload the logo `design/rivoxa-logo.png` as the *Agency logo* so "Developed by [logo]" shows at the bottom of the website and of the admin panel), **SEO**, and the **Banners** tab (home hero, secondary banners, promo strip).
9. **Notifications** – switch e-mails on, set the sender name, the owner address for alerts and the storefront's tracking page URL (`https://shop.example/track`), choose which events send an e-mail, press *Send test e-mail*; switch push on once the VAPID keys are in `backend/.env`.
10. Optional: **Discounts** and **Coupons** (sidebar) once the catalogue exists.

Then open **Access Control** (developer only) and switch on what the client's `admin` account may see: by default the admin has the dashboard, orders (with the CSV export), the customers list, wholesale requests, discounts, coupons and the General, Footer & Social, Orders & Delivery, Store, Delivery, Payments and Notifications tabs; products, categories and the other tabs are off until you switch them on (the Theme tab stays with the developer). Set a real password for both accounts (`SEED_*_PASSWORD` before the first seed). The developer never sees orders. See [ACCESS_CONTROL.md](ACCESS_CONTROL.md).

## 5. Add the catalogue

Admin panel → **Categories** (two levels; the sub-category field only shows once a sub-category exists), **Tags**, **Products** (options, prices, bulk prices, stock, pictures, video, the sold-out and featured switches).
To try the shop first, load the neutral sample data: `php artisan db:seed --class=DemoSeeder` (never on a production site).

## 6. Connect the storefront

The storefront of this repository is the Nuxt site in `frontend/` ([STOREFRONT.md](STOREFRONT.md): environment, Cloudflare Pages step by step, the same build on cPanel, the launch checklist). Set its `NUXT_PUBLIC_API_BASE` to this API, put its address in `FRONTEND_URLS`, enter the theme values of STOREFRONT.md in Settings → Theme and create the two banners (`home_hero`, `home_secondary`). What follows is for anyone who builds a different storefront.

Give the storefront developer [STOREFRONT-API.md](STOREFRONT-API.md), `docs/openapi.json` and the Postman collection in `docs/postman/`. The first call a storefront should make is
`GET /api/v1/store/settings` – it returns the store name, logo, currency and tax rules, so one storefront build adapts to each client. There is no customer login: the storefront keeps the cart itself or uses the token-based server cart (`/cart`), calls `POST /checkout/quote` and `POST /orders`, and for bank transfer uploads the payment proof. It paints itself with the `theme` object of the settings. Optional storefront pieces: a wholesale request form (`POST /store/wholesale-requests`), a tracking page that reads `?order=` and asks for the phone number (its URL goes into the `storefront_track_url` setting so e-mails can link to it), and `GET /products?featured=1` for a featured section.

## 6b. The static storefront and its rebuild hook

The customer storefront is a **static site** (built once, served from a CDN: Cloudflare Pages is the plan, cPanel, Netlify and Vercel work too). What a visitor sees first (products, categories, pictures, texts, theme) is baked into the pages at build time, which is what search engines read. **Prices, stock, the cart, coupons, checkout, order tracking and search stay live**: the browser asks this API for them, so they never wait for a rebuild.

So when the shop owner adds a product or changes a banner, the website must be **built again**. This backend does that by itself, through a *deploy hook*:

1. **Create the hook.** Cloudflare Pages: your project → *Settings* → *Builds & deployments* → *Deploy hooks* → *Add deploy hook* (name it `admin-panel`, branch `main`) → copy the URL (`https://api.cloudflare.com/client/v4/pages/webhooks/deploy_hooks/…`). Netlify (*Build hooks*) and Vercel (*Deploy Hooks*) give the same kind of URL. Anyone with the URL can start a build, so treat it as a secret.
2. **Paste it in the admin panel.** Log in as the developer → *Settings* → *Website*: *Rebuild hook address* (https only), switch on *Rebuild the website automatically*, leave the wait time at 120 seconds. The optional *Rebuild hook secret* is sent as the header `X-Rebuild-Secret` and is only needed for a hook of your own (a relay); it is stored encrypted and never shown again. Press *Rebuild website now* once: the card must say *Last result: OK*.
3. **Run the queue.** The rebuild is a queued job (`TriggerRebuild`), like the e-mails: it needs `php artisan queue:work --tries=3` under a process manager, **or** on shared hosting the cron `* * * * * php artisan queue:work --stop-when-empty --max-time=55`, plus the usual cron `schedule:run`. Without a worker the automatic rebuild waits in the `jobs` table (the *Rebuild website now* button and `php artisan shop:rebuild` still work, they call the hook directly).
4. **What triggers it.** Anything customers can see changes the website: products, options and their prices or stock set in the panel, pictures, tags, categories, sub-categories, banners, discounts, public settings (name, logo, footer, SEO, currency, delivery, payments, store open/closed), the theme. Twenty quick edits make **one** call: the first change opens a wait window (*Wait after the last change*, default 120 s) and the job runs when it ends. Orders, customers, carts, wholesale requests, coupons, delivery zones, notification settings and the stock a sale takes **never** trigger a rebuild.
5. **What the owner sees.** The *Website* tab shows the changes waiting, when the hosting was last asked, the last result (OK, or Failed with a short message) and a warning when the last attempt failed. A failing hook never breaks the save that caused it.

A **new product shows on the website after the rebuild, 1 to 3 minutes** later. Until then its page address still opens (the static site falls back to loading it from the API in the browser) and every price, stock level and the cart are already live. Changing the price of an existing product is live at once on the product page and in the cart; the baked-in price in the page source and in search results updates with the next rebuild.

Tell the client: *the website updates itself about two minutes after you change something; if you are in a hurry, press Rebuild website now.*

## 7. Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (it is forced off in production anyway), HTTPS, `SESSION_SECURE_COOKIE=true` (the default in production)
- [ ] `ADMIN_TOKEN_MINUTES` and the checkout rate limits (`CHECKOUT_PER_*`, `QUOTE_PER_MINUTE`, `ORDER_LOOKUP_PER_*`) reviewed
- [ ] strong `SEED_DEVELOPER_PASSWORD` and `SEED_ADMIN_PASSWORD` used (12+ characters)
- [ ] the storefront has the hidden honeypot field (`website`) in its checkout form
- [ ] `APP_URL`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS` match the real domains
- [ ] cron entry `schedule:run` added (tokens and carts are pruned by it)
- [ ] the Notification status card is green: *Send test notification* works on the client's browser (see [PUSH_TEST.md](PUSH_TEST.md)); the logo and favicon are uploaded (the panel, login page and browser tab use them)
- [ ] the queue is worked (supervisor `queue:work`, or the cron `queue:work --stop-when-empty`); `MAIL_*` set to the real mail server and a test e-mail received; `APP_TIMEZONE` correct
- [ ] push (optional): `VAPID_*` in `backend/.env`, switched on, one admin device subscribed; PHP / web server upload limits allow `PRODUCT_VIDEO_MAX_KB`
- [ ] `php artisan migrate:fresh --seed` is never run on a live site (the baseline migration is edited in place: rebuild only development databases)
- [ ] `backend/storage/app/private` writable and not web-served; PHP upload limit ≥ 4 MB (payment proofs)
- [ ] delivery method and zones, payment methods and bank accounts, store open, theme reviewed
- [ ] `php artisan config:cache route:cache` after the final `backend/.env`
- [ ] backups of the database, `backend/storage/app/public` and `backend/storage/app/private`
- [ ] demo data removed
- [ ] the storefront's deploy hook is pasted in *Settings → Website*, *Rebuild website now* says OK, and `FRONTEND_URLS` holds the storefront's domain (`FRONTEND_URL_PATTERNS` for preview sites)

## Keeping clients up to date

Pull upstream changes into the client repository and run `php artisan migrate --force`. The baseline migration was edited in place for Phase A and B, so a database from before them must be rebuilt with `migrate:fresh --seed` (development only); from now on migrations only add or alter; client-specific values live in `settings`, so an
upgrade never overwrites them. Client-specific code (a custom module, a payment provider) should live in its own `backend/app/Domain/<Module>` folder to keep merges simple.
