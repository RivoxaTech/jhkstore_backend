# How to create a new client project

The platform is **one deployment per client**: a separate copy of the code, its own database and its own `.env`. Nothing is shared between clients at runtime.

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
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

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
| `SHOP_MAX_ITEMS_PER_ORDER`, `SHOP_MAX_QTY_PER_LINE`, `SHOP_HONEYPOT_FIELD`, `CHECKOUT_PER_MINUTE`, … | optional: the limits of the public checkout (defaults in `.env.example` and `config/shop.php`) |
| `CART_PER_MINUTE`, `COUPON_PER_MINUTE`, `PAYMENT_PROOF_PER_MINUTE`, `PAYMENT_PROOF_PER_ORDER_HOUR`, `PAYMENT_PROOF_MAX_KB` | optional: limits of the cart, the coupon check and the bank transfer proof upload (defaults 60, 30, 10, 10, 4096; see `config/shop.php`) |
| `SESSION_LIFETIME` | minutes an admin panel session stays valid without activity (default 120) |
| `QUEUE_CONNECTION` | `database` (the default): order e-mails and push messages are queued, see step 3 |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | the client's mail server (`MAIL_MAILER=log` only writes mails to `storage/logs/laravel.log`: fine for development, never for production) |
| `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | admin push notifications: run `php artisan shop:vapid`, copy the three lines and set the subject to `mailto:you@client.example` (on Windows PHP may need `OPENSSL_CONF` pointing at its `openssl.cnf`). Empty = push is off |
| `PRODUCT_VIDEO_MAX_KB`, `PRODUCT_VIDEO_POSTER_MAX_KB` | optional: size limit of an uploaded product video and its poster (defaults 51200 = 50 MB and 2048) |
| `WHOLESALE_PER_HOUR`, `WHOLESALE_PER_PHONE_HOUR` | optional: wholesale requests an hour per IP and per phone number (default 5 each) |
| `FILESYSTEM_DISK` | the client's storage |
| `SESSION_SECURE_COOKIE=true` | in production (HTTPS) |

## 3. Create the database and install

```bash
# create the empty database (and the test database) in MySQL first
composer install --no-dev --optimize-autoloader
php artisan migrate --force --seed       # tables, default settings, the two admin accounts
php artisan storage:link
cd admin-frontend && npm install && npm run build && cd ..     # admin panel -> public/admin-assets
```

**Cron.** Add the entry `* * * * * php artisan schedule:run`. It runs the daily jobs: removal of expired admin API tokens and `shop:prune-carts` (deletes carts that were not touched for 30 days). Without it the carts table only grows.

**Queue worker.** E-mails and push notifications are queued (after the order is saved, so a slow or broken mail server never delays or fails an order). Something has to work the queue; pick one:

1. **A worker** (VPS, recommended): `php artisan queue:work --tries=3` kept alive by supervisor or systemd, restarted after each deployment (`php artisan queue:restart`).
2. **Cron** (shared hosting, no long-running process): `* * * * * php artisan queue:work --stop-when-empty --max-time=55` (a mail can be up to a minute late).

Failed jobs are listed with `php artisan queue:failed`, retried with `php artisan queue:retry all` and cleared with `php artisan queue:flush`; Settings → Notifications shows how many failed (a count only). The sidebar badges for pending orders and new wholesale requests refresh every 30 seconds and work without a worker. The e-mails stay off until Settings → Notifications → *Send e-mails* is on.

**Time zone.** `APP_TIMEZONE` decides what "today" is, the dates of orders, the dashboard ranges and the start and end of discounts, coupons and banners. Set it before the first order; the admin panel shows and accepts every date in that zone.

**Push.** Browser push works on HTTPS (or localhost). After `shop:vapid` and the three `VAPID_*` values, switch *Admin push notifications* on in Settings → Notifications; each admin then presses the bell in the panel header on their device (the browser asks for permission only then). The service worker is `public/admin-sw.js` (served at `/admin-sw.js`, scope `/admin/`): make sure the web server serves that file as a static file.

**Payment proofs are private.** Bank transfer screenshots are stored on the `local` disk (`storage/app/private`). That folder must be writable by the web server and **must not be served by the web server** (only `public/` and `storage/app/public` via `storage:link` are public). Set PHP `upload_max_filesize` and `post_max_size` to at least 4 MB (the proof limit is 4 MB, `PAYMENT_PROOF_MAX_KB`). Back the folder up with the database.

**Product videos** (uploaded mp4 / webm, at most 2 a product) are public files in `storage/app/public` (`php artisan storage:link`). Raise PHP `upload_max_filesize` and `post_max_size` to at least `PRODUCT_VIDEO_MAX_KB` (50 MB by default; also nginx `client_max_body_size` or Apache `LimitRequestBody`) and `max_execution_time` for slow uploads, otherwise the panel says the server refused the file. Nothing is converted: ask the client for small web-ready files, or use a YouTube / Vimeo link.

## 4. Configure the store (admin panel → Settings)

Log in at `/admin` as the **developer** and set, in this order (see [SETTINGS.md](SETTINGS.md) for every field):

1. **General** – name, tagline, contact email / phone / address, country, and the **default phone country code** (e.g. `+92`: customers who type `0300…` and `+92 300…` become one customer; set it **before the first order**). **Branding** – logo and favicon.
2. **Currency & Tax** – code, symbol and decimals (e.g. `EUR`, `€`, 2); default tax rate, whether prices include tax, and the label ("VAT", "Sales tax", …). A product can override the rate.
3. **Orders & Delivery** – order number prefix, minimum order, cash-on-delivery limit.
4. **Delivery** – the method (`flat`, `free`, `free_above` (default), `zones`, `owner_confirms`), delivery charge, free-delivery limit, delivery days, and for `zones` the **zone manager** (city / area / postcode zones with fee, free-above, minimum order, ETA; put the most specific zone first) and what happens to an address outside every zone.
5. **Payments** – cash on delivery on / off, **bank transfer** (add at least one active account first; instructions such as "use the order number as the reference"), and whether to show the "card, coming soon" label.
6. **Store** – open / closed (a closed store takes no orders), the closed message, a checkout notice and the delivery estimate text.
7. **Theme** (developer only) – colours, fonts, corner radius and shadow of the storefront; the storefront reads them from `GET /store/settings`.
8. **Footer & Social** (including the agency credit), **SEO**, and the **Banners** tab (home hero, secondary banners, promo strip).
9. **Notifications** – switch e-mails on, set the sender name, the owner address for alerts and the storefront's tracking page URL (`https://shop.example/track`), choose which events send an e-mail, press *Send test e-mail*; switch push on once the VAPID keys are in `.env`.
10. Optional: **Discounts** and **Coupons** (sidebar) once the catalogue exists.

Then open **Access Control** (developer only) and switch on what the client's `admin` account may see: by default the admin has the dashboard, orders (with the CSV export), the customers list, wholesale requests, discounts, coupons and the General, Footer & Social, Orders & Delivery, Store, Delivery, Payments and Notifications tabs; products, categories and the other tabs are off until you switch them on (the Theme tab stays with the developer). Set a real password for both accounts (`SEED_*_PASSWORD` before the first seed). The developer never sees orders. See [ACCESS_CONTROL.md](ACCESS_CONTROL.md).

## 5. Add the catalogue

Admin panel → **Categories** (two levels; the sub-category field only shows once a sub-category exists), **Tags**, **Products** (options, prices, bulk prices, stock, pictures, video, the sold-out and featured switches).
To try the shop first, load the neutral sample data: `php artisan db:seed --class=DemoSeeder` (never on a production site).

## 6. Connect the storefront

Give the storefront developer [STOREFRONT-API.md](STOREFRONT-API.md), `docs/openapi.json` and the Postman collection in `docs/postman/`. The first call a storefront should make is
`GET /api/v1/store/settings` – it returns the store name, logo, currency and tax rules, so one storefront build adapts to each client. There is no customer login: the storefront keeps the cart itself or uses the token-based server cart (`/cart`), calls `POST /checkout/quote` and `POST /orders`, and for bank transfer uploads the payment proof. It paints itself with the `theme` object of the settings. Optional storefront pieces: a wholesale request form (`POST /store/wholesale-requests`), a tracking page that reads `?order=` and asks for the phone number (its URL goes into the `storefront_track_url` setting so e-mails can link to it), and `GET /products?featured=1` for a featured section.

## 7. Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (it is forced off in production anyway), HTTPS, `SESSION_SECURE_COOKIE=true` (the default in production)
- [ ] `ADMIN_TOKEN_MINUTES` and the checkout rate limits (`CHECKOUT_PER_*`, `QUOTE_PER_MINUTE`, `ORDER_LOOKUP_PER_*`) reviewed
- [ ] strong `SEED_DEVELOPER_PASSWORD` and `SEED_ADMIN_PASSWORD` used (12+ characters)
- [ ] the storefront has the hidden honeypot field (`website`) in its checkout form
- [ ] `APP_URL`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS` match the real domains
- [ ] cron entry `schedule:run` added (tokens and carts are pruned by it)
- [ ] the queue is worked (supervisor `queue:work`, or the cron `queue:work --stop-when-empty`); `MAIL_*` set to the real mail server and a test e-mail received; `APP_TIMEZONE` correct
- [ ] push (optional): `VAPID_*` in `.env`, switched on, one admin device subscribed; PHP / web server upload limits allow `PRODUCT_VIDEO_MAX_KB`
- [ ] `php artisan migrate:fresh --seed` is never run on a live site (the baseline migration is edited in place: rebuild only development databases)
- [ ] `storage/app/private` writable and not web-served; PHP upload limit ≥ 4 MB (payment proofs)
- [ ] delivery method and zones, payment methods and bank accounts, store open, theme reviewed
- [ ] `php artisan config:cache route:cache` after the final `.env`
- [ ] backups of the database, `storage/app/public` and `storage/app/private`
- [ ] demo data removed

## Keeping clients up to date

Pull upstream changes into the client repository and run `php artisan migrate --force`. The baseline migration was edited in place for Phase A and B, so a database from before them must be rebuilt with `migrate:fresh --seed` (development only); from now on migrations only add or alter; client-specific values live in `settings`, so an
upgrade never overwrites them. Client-specific code (a custom module, a payment provider) should live in its own `app/Domain/<Module>` folder to keep merges simple.
