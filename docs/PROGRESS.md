# Progress log

Updated: 2026-10-27. Read together with `ARCHITECTURE.md` / `DATABASE.md` (design).

## Done

### Phase 0 – Foundation
* Laravel 13 project created in this folder (PHP 8.3, MySQL 8.4 `dentalkart_db`, Laragon).
* Packages: Sanctum, spatie/laravel-permission, spatie/laravel-medialibrary, dedoc/scramble (API docs at `/docs/api`).
* **All 87 tables migrated** (whole schema from `DATABASE.md`; categories and sub_categories are separate tables).
  Migration files: `database/migrations/2026_10_27_00000{1..5}_*.php` + `0001_01_01_000000_create_users_table.php`.
* Seeders (`php artisan migrate:fresh --seed`): admin `admin/admin`, customer `test@gmail.com/test`,
  dropdown lookups (registration types, vendor types, 49 countries – placeholder values, admin-editable),
  channels (`standard` 5 days, `instadent` 3 days, login required), settings (delivery fee ₹95, free above ₹2800),
  in-house company "Dentalkart (In-house)".
* Two auth sides: admin (`username`; cookie session for the admin panel, bearer token for tools) and customer (`email` or `mobile`;
  bearer token). A credential only works on its own side (`actor:admin` / `actor:customer` middleware). Login throttled; public forms throttled.
* CORS for the frontend origins in `.env` (`FRONTEND_URLS`).
* Image URLs in every API response are absolute (`MediaUrl` helper) and `null` when missing. Run `php artisan storage:link` once.

### Phase 1 – Company module (done)
* Customer "Sell on Dentalkart" registration: options endpoint + submit endpoint (multipart, logo + optional catalogue upload).
  Creates a `pending` company with inactive brands. Rules: promotion text 200–500 chars, company logo required (png/jpg/jpeg/webp, ≤4 MB),
  catalogue optional (png/jpg/jpeg/webp/pdf, ≤10 MB, ≤10 files), ≥1 brand, GST unique among non-rejected companies.
* Admin: list (filters + status counts), create (auto-approved), detail (brands + catalogue), update, approve, reject (reason),
  suspend, delete (blocked if it has products), private catalogue download.
* Brands: admin CRUD per company (logo + banner upload, thumbnail generated), brands list for product forms.
  Customer-side brands API shows only active brands of approved companies (`?collection=top|featured|new|suggested`, `?q=`).
* Delivery settings: public read + admin update.
* Tests: 16 PHPUnit tests, all passing (`php artisan test`, uses the `dentalkart_test` database).
* Postman: one flat file `docs/postman/Dentalkart-APIs.postman_collection.json` with the 6 customer APIs (registration types, vendor types,
  countries, all dropdowns in one call, register a company, brands list). `docs/postman/sample-logo.png` is the logo it uploads.
  Live API docs are generated at `/docs/api` (Scramble).

### Admin panel (done)
* Separate Vue 3 app in `admin-frontend/` (dev: `npm run dev` -> http://localhost:5190; built: served by Laravel at `/admin`).
  How to run: `admin-frontend/README.md`.
* **Cookie login** (HttpOnly session + CSRF) for the panel; the same endpoints still accept bearer tokens for Postman/scripts.
  Mode is chosen automatically from the request origin (`SANCTUM_STATEFUL_DOMAINS`). Sessions are stored in MySQL (`sessions` table).
* Pages: login, dashboard (counts, pending requests, activity), companies list (tabs, search, filters, pagination),
  create/edit company form, company detail (approve / reject / suspend / reactivate, catalogue download, brands add/edit/delete/on-off).
* Sidebar has only Dashboard and Companies (more sections are added with each backend module).
* New API: `GET /admin/dashboard`. Tests: 22 PHPUnit tests; real-browser run 31/31 checks (dev server and Laravel-served build).

### Logo, compact UI, customer test page (done)
* **Company logo** is required (public form and admin form); catalogue is optional and private. Brands show their own logo, else the
  **company logo** by default (`logo_source`, `has_own_logo`; `remove_logo=1` clears a brand logo). Company update can replace the logo.
* Admin list API is lean (`CompanyListResource`: logo, contact, city, vendor/type, brand names, catalogue count) – no N+1.
* Admin UI made compact (13px base, ~48px table rows), richer companies table, sidebar **collapse inside the sidebar** (header
  hamburger only on phones), dropdown data cached once per session (`stores/lookups.js`).
* Public form rate limit is 500/hour in local mode (`FORMS_PER_HOUR`), 10/hour otherwise.
* Dropdown data has one API per list (`/store/lookups/registration-types|vendor-types|countries`) plus a combined one
  (`/store/company-registration/options`). A company needs at least one brand (form rule; the last brand cannot be deleted).
* Tests: 25 PHPUnit, Postman 36 requests (58 assertions) and a real-browser run of 46 + 5 checks, all passing.

## Next
1. Categories and sub-categories (admin CRUD with icon + image, customer tree endpoint).
2. Products (variants, tags, channels + stock, auto discount, images, content tabs).
3. Customer catalog (list, filters, search, detail, brand pages, Instadent gate).
4. Reviews/ratings, wishlist, Q&A, feedback.
5. Importer for the frontend's current demo data.

## Notes / decisions
* Nothing from this work has been committed or pushed yet.
* Admin brand logo update from a browser: send `POST` with `_method=PUT` when the request has files.
