# Dentalkart Backend

Laravel 13 API for the Dentalkart clone (customer API, admin API) with a Vue 3 admin panel in `admin-frontend/`.

## Setup

```bash
composer install
cp .env.example .env              # set DB_USERNAME / DB_PASSWORD if needed
php artisan key:generate
# create an empty MySQL database named: dentalkart_db
php artisan migrate --seed        # tables + seed data
php artisan db:seed --class=ProductDemoSeeder   # optional: demo products from the frontend
php artisan db:seed --class=OrderDemoSeeder     # optional, AFTER ProductDemoSeeder: 15 demo orders in every status (4 demo customers, password test)
php artisan storage:link
php artisan serve                 # http://localhost:8000
```

Admin panel:

```bash
cd admin-frontend
npm install
npm run dev                       # http://localhost:5190  (login: admin / admin)
```

Tests (need an empty MySQL database named `dentalkart_test`): `php artisan test`

Demo customer (after `migrate --seed`): `test@gmail.com` / `test` - it has 500 demo coins and one saved address, so cart and checkout can be tried at once
(see the Postman collection `Dentalkart-Cart-Orders`).

Scheduled jobs (add `* * * * * php artisan schedule:run` to the server cron): `carts:prune` (expired guest carts, old empty carts) and `rewards:expire` (coins past their expiry).
The shop time zone is `APP_TIMEZONE` in `.env` (default `Asia/Karachi` in `.env.example`): "today", order dates and the date filters follow it.

## Docs

* `docs/ARCHITECTURE.md` – design and decisions
* `docs/DATABASE.md` – database tables
* `docs/PROGRESS.md` – what is done and what is next
* `docs/postman/` – Postman collections: `Dentalkart-Products-Frontend` (product APIs for the frontend developer, press Send), `Dentalkart-Cart-Orders` (customer side only: guest + customer cart, addresses, checkout, orders; for the frontend developer) and the customer APIs
* API reference (generated): `/docs/api` when the server runs
