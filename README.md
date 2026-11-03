# Lean store (Laravel API + Vue admin)

A neutral, reusable store backend: **products, categories and orders**, plus the admin settings that control the website. **One deployment per client**: the code is the same for every client, and everything that is specific to a
client (store name, logo, currency, tax, delivery, payment methods, theme, banners, footer) lives in `.env` and the `settings` table.

* **Backend** – Laravel 13 / PHP 8.3 / MySQL 8, JSON API (`/api/v1/store/*` for the storefront, `/api/v1/admin/*` for the admin).
* **No customer accounts.** Customers never register or log in: the storefront keeps the cart, asks the API to price it, and places a guest order with name, phone (required), optional e-mail and a delivery address. Every order finds or creates a *customer* record by phone (a contact record, no password).
* **Checkout features** – cash on delivery or **bank transfer** (the customer uploads a screenshot, kept on the private disk, the admin verifies it), **delivery** by flat fee / free / free above an amount / **delivery zones** / fee confirmed by the owner, automatic **discounts** and **coupons**, a **persistent server cart** bound to a random token (`X-Cart-Token`, expires after 30 days), orders can be **rejected** with a reason. All prices are calculated in one place (`CartPricing`).
* **Admin panel** – Vue 3 single-page app in `admin-frontend/`, served by Laravel at `/admin` after a build. Exactly **two accounts**: the *developer* (everything except orders) and the *admin* (the client; the developer decides what they may see, see [docs/ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md)).
  Sidebar: Dashboard, Orders, Products, Categories, Discounts, Coupons, Customers, Settings (General, Branding, Banners, Footer & Social, Currency & Tax, Orders & Delivery, Delivery with the zone manager, Payments, Store, Theme (developer only), SEO) and, for the developer, Access Control.
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
php artisan serve                 # http://localhost:8000
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

Tests (need an empty MySQL database `jhkstore_test`): `php artisan test`

Scheduled jobs (add `* * * * * php artisan schedule:run` to the server cron, otherwise they never run): `sanctum:prune-expired` (expired admin API tokens, daily) and `shop:prune-carts` (carts that were not touched for 30 days, daily).
The shop time zone is `APP_TIMEZONE` in `.env`: "today", order dates and the admin date filters follow it.

## Documentation

| Doc | What is in it |
|---|---|
| [docs/NEW_CLIENT.md](docs/NEW_CLIENT.md) | **How to create a new client project** |
| [docs/ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md) | The two accounts, the permission list, the hard rules |
| [docs/SETTINGS.md](docs/SETTINGS.md) | Every setting, its default and what it does; how to add a tab |
| [docs/FEATURES.md](docs/FEATURES.md) | What is built, what was removed |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | How the code is organised and the main design decisions |
| [docs/DATABASE.md](docs/DATABASE.md) | The ERD and every table with the reason it is kept |
| [docs/STOREFRONT-API.md](docs/STOREFRONT-API.md) | The public API for whoever builds the storefront: settings and theme, quote, coupons, persistent cart, guest checkout, bank transfer proof, order lookup |
| [docs/openapi.json](docs/openapi.json) | OpenAPI 3.1 reference (regenerate with `php artisan scramble:export --path=docs/openapi.json`); live at `/docs/api` |
| [docs/postman/](docs/postman/) | Postman collection of the storefront API |
| [CHANGELOG.md](CHANGELOG.md) | What changed |
