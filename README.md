# Lean store (Laravel API + Vue admin)

A neutral, reusable store backend: **products, categories and orders**, plus the admin settings that control the website. **One deployment per client**: the code is the same for every client, and everything that is specific to a
client (store name, logo, currency, tax, delivery, payment methods, theme, banners, footer) lives in `.env` and the `settings` table.

* **Backend** – Laravel 13 / PHP 8.3 / MySQL 8, JSON API (`/api/v1/store/*` for the storefront, `/api/v1/admin/*` for the admin).
* **No customer accounts.** Customers never register or log in: the storefront keeps the cart, asks the API to price it, and places a guest order with name, phone (required), optional e-mail and a delivery address. Every order finds or creates a *customer* record by phone (a contact record, no password).
* **Checkout features** – cash on delivery or **bank transfer** (the customer uploads a screenshot, kept on the private disk, the admin verifies it), **delivery** by flat fee / free / free above an amount / **delivery zones** / fee confirmed by the owner, automatic **discounts** and **coupons**, a **persistent server cart** bound to a random token (`X-Cart-Token`, expires after 30 days), orders can be **rejected** with a reason. All prices are calculated in one place (`CartPricing`).
* **Notifications** – queued **order e-mails** to the customer and the owner (plain, no images, no tracking pixels), admin **browser push** (Web Push), a sidebar badge for pending orders and new wholesale requests (polled every 30 s). A mail or push failure never fails an order.
* **Wholesale requests** – a public form endpoint, an admin screen with status tabs and CSV; **product video** (upload or YouTube / Vimeo link), sold-out and featured switches, order and abandoned-cart CSV exports, WhatsApp buttons, a dashboard with date ranges, KPI cards, a chart, top sellers and low stock.
* **Admin panel** – Vue 3 single-page app in `admin-frontend/`, served by Laravel at `/admin` after a build. Exactly **two accounts**: the *developer* (everything except orders) and the *admin* (the client; the developer decides what they may see, see [docs/ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md)).
  Sidebar: Dashboard, Orders, Products, Categories, Customers (with the abandoned carts tab), Wholesale, Discounts, Coupons, Settings (General, Branding, Banners, Footer & Social, Currency & Tax, Orders & Delivery, Delivery with the zone manager, Payments, Store, Notifications, Theme (developer only), SEO) and, for the developer, Access Control.
* **Storefront** – not part of this repo. The API is storefront-agnostic: build it in any framework (see [docs/STOREFRONT-API.md](docs/STOREFRONT-API.md)).

## Quick start

```bash
composer install
cp .env.example .env              # set DB_USERNAME / DB_PASSWORD if needed
php artisan key:generate
# create two empty MySQL databases: jhkstore_db (the app) and jhkstore_test (the tests)
php artisan migrate --seed        # tables + default settings + the two admin accounts
php artisan db:seed --class=DemoSeeder   # optional: neutral sample categories and products
php artisan storage:link
php artisan serve                 # http://localhost:8000 (use --port=8002 when another project holds 8000)
```

Admin accounts (from `.env`; defaults for local development only): developer `SEED_DEVELOPER_USERNAME` / `SEED_DEVELOPER_PASSWORD` (`developer` / `developer`), admin `SEED_ADMIN_USERNAME` / `SEED_ADMIN_PASSWORD` (`admin` / `admin`). In production the seeder refuses weak passwords.

Admin panel:

```bash
cd admin-frontend
npm install
npm run dev                       # http://localhost:5190
npm run build                     # production build into public/admin-assets, served at /admin
npm run e2e                       # browser smoke test (needs the API running on a seeded database and Chrome)
npm run flow-ui                   # the same flows, screen by screen, in headless Chrome
```

Full flow check over real HTTP (needs the API on a seeded database; a second server process for the "last unit / last coupon use" races; see [docs/QA_REPORT.md](docs/QA_REPORT.md)):

```bash
php artisan migrate:fresh --seed
php artisan serve --port=8002     # use another port when 8000 is taken by another project
php artisan serve --port=8003
FLOW_BASE=http://127.0.0.1:8002 FLOW_BASE2=http://127.0.0.1:8003 node scripts/flow-check.mjs
E2E_BASE=http://127.0.0.1:8002 npm run e2e --prefix admin-frontend   # also npm run flow-ui
```

The server's `APP_URL` must match the port it serves and the port must be in `SANCTUM_STATEFUL_DOMAINS` (panel login).

Tests (need an empty MySQL database `jhkstore_test`): `php artisan test` (or `vendor/bin/phpunit`; one file: `vendor/bin/phpunit tests/Feature/WholesaleTest.php`). Rebuild the development database with `php artisan migrate:fresh --seed` (the base seeder only; `DemoSeeder` is optional sample data).

Artisan commands of the shop:

| Command | What it does |
|---|---|
| `php artisan shop:vapid` | Prints a new VAPID key pair for admin push (put it in `.env`; on Windows PHP may need `OPENSSL_CONF` pointing at its `openssl.cnf`) |
| `php artisan queue:work --tries=3` | The queue worker for e-mails and push (or the cron variant `queue:work --stop-when-empty --max-time=55` on shared hosting, see [docs/NEW_CLIENT.md](docs/NEW_CLIENT.md)); `queue:failed`, `queue:retry all`, `queue:flush` for failed jobs |
| `php artisan shop:prune-carts` | Deletes carts not touched for 30 days (scheduled daily) |

Scheduled jobs (add `* * * * * php artisan schedule:run` to the server cron, otherwise they never run): `sanctum:prune-expired` (expired admin API tokens, daily) and `shop:prune-carts` (daily).
The shop time zone is `APP_TIMEZONE` in `.env`: stored dates, "today", the dashboard ranges, discount / coupon / banner windows and the admin date inputs all follow it; the API sends dates with the shop's offset.

## Documentation

| Doc | What is in it |
|---|---|
| [docs/HANDOVER.md](docs/HANDOVER.md) | **One page: every feature, where it lives in the code, which permission controls it, how to add a client** |
| [docs/NEW_CLIENT.md](docs/NEW_CLIENT.md) | **How to create a new client project** (including mail, queue worker, push and upload limits) |
| [docs/ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md) | The two accounts, the permission list, the hard rules |
| [docs/SETTINGS.md](docs/SETTINGS.md) | Every setting, its default and what it does; how to add a tab |
| [docs/FEATURES.md](docs/FEATURES.md) | What is built, what was removed |
| [docs/QA_REPORT.md](docs/QA_REPORT.md) | The checks run on the whole product and their results |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | How the code is organised and the main design decisions |
| [docs/DATABASE.md](docs/DATABASE.md) | The ERD and every table with the reason it is kept |
| [docs/STOREFRONT-API.md](docs/STOREFRONT-API.md) | The public API for whoever builds the storefront: settings and theme, quote, coupons, persistent cart, guest checkout, bank transfer proof, order lookup |
| [docs/openapi.json](docs/openapi.json) | OpenAPI 3.1 reference; live at `/docs/api`. The title comes from `APP_NAME` and the server from `APP_URL`, so export it with neutral values: `APP_NAME="Store API" APP_URL=https://shop.example.com php artisan scramble:export --path=docs/openapi.json` |
| [docs/postman/](docs/postman/) | Postman collection of the storefront API |
| [CHANGELOG.md](CHANGELOG.md) | What changed |
