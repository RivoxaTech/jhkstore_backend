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

## Docs

* `docs/ARCHITECTURE.md` – design and decisions
* `docs/DATABASE.md` – database tables
* `docs/PROGRESS.md` – what is done and what is next
* `docs/postman/` – Postman collections: `Dentalkart-Products-Frontend` (product APIs for the frontend developer, press Send) and the customer APIs
* API reference (generated): `/docs/api` when the server runs
