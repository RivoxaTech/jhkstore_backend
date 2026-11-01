# E-commerce base (Laravel API + Vue admin)

A neutral, reusable e-commerce backend. **One deployment per client**: the code is the same for every client, and everything that is specific to a
client (store name, logo, currency, tax, delivery, which optional features are on) lives in `.env` and the `settings` table.

* **Backend** – Laravel 13 / PHP 8.3 / MySQL 8, JSON API, Sanctum auth (`/api/v1/store/*` for the storefront, `/api/v1/admin/*` for the admin).
* **Admin panel** – Vue 3 single-page app in `admin-frontend/`, served by Laravel at `/admin` after a build.
* **Storefront** – not part of this repo. The API is storefront-agnostic: build it in any framework (see [docs/STOREFRONT-API.md](docs/STOREFRONT-API.md)).

## Quick start

```bash
composer install
cp .env.example .env              # set DB_USERNAME / DB_PASSWORD if needed
php artisan key:generate
# create two empty MySQL databases: jhkstore_db (the app) and jhkstore_test (the tests)
php artisan migrate --seed        # tables + default settings + the first admin user
php artisan db:seed --class=DemoSeeder   # optional: neutral sample categories, products and a demo customer
php artisan storage:link
php artisan serve                 # http://localhost:8000
```

Admin panel:

```bash
cd admin-frontend
npm install
npm run dev                       # http://localhost:5190  (login: the SEED_ADMIN_* values of .env, default admin / admin)
npm run build                     # production build into public/admin-assets, served at /admin
```

Tests (need an empty MySQL database `jhkstore_test`): `php artisan test`

Demo customer (after `DemoSeeder`): `DEMO_CUSTOMER_EMAIL` / `DEMO_CUSTOMER_PASSWORD` (default `demo@example.com` / `demo-password`).

Scheduled jobs (add `* * * * * php artisan schedule:run` to the server cron): `carts:prune` (expired guest carts, old empty carts) and `rewards:expire` (points past their expiry).
The shop time zone is `APP_TIMEZONE` in `.env`: "today", order dates and the admin date filters follow it.

## Documentation

| Doc | What is in it |
|---|---|
| [docs/NEW_CLIENT.md](docs/NEW_CLIENT.md) | **How to create a new client project** |
| [docs/SETTINGS.md](docs/SETTINGS.md) | Every setting, its default and what it does |
| [docs/FEATURES.md](docs/FEATURES.md) | What is built, what is optional, what is planned |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | How the code is organised and the main design decisions |
| [docs/DATABASE.md](docs/DATABASE.md) | Tables in use, tables kept for later, tables proposed for removal |
| [docs/STOREFRONT-API.md](docs/STOREFRONT-API.md) | The customer API for whoever builds the storefront |
| [docs/postman/](docs/postman/) | Postman collection of the customer API |
| [CHANGELOG.md](CHANGELOG.md) | What changed |
| `/docs/api` | Generated OpenAPI reference (Scramble) while the server runs locally |
