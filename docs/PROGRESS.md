# Progress log

Updated: 2026-10-28. Read together with `ARCHITECTURE.md` / `DATABASE.md` (design). Setup steps: `../README.md`.

## Where we are

| Area | State |
|---|---|
| Database schema (88 tables) | ✅ complete, migrated |
| Auth (admin cookie + token, customer token) | ✅ |
| Company module (register, approve, brands, logos) | ✅ |
| Categories and sub-categories (admin + customer API) | ✅ |
| Admin panel (`admin-frontend/`) | ✅ dashboard + companies + categories |
| Products, catalog, reviews, wishlist | ⏳ next |
| Cart, orders, payments, returns, rewards, membership, coupons, content | ⏳ later (schema ready) |

Code is pushed to `startxlaravel02/DentalKart_backend` (`main`).

## Done

### Foundation
* Laravel 13 (PHP 8.3, MySQL 8.4 `dentalkart_db`, Laragon). Packages: Sanctum, spatie/laravel-permission, spatie/laravel-medialibrary,
  dedoc/scramble (generated API docs at `/docs/api`).
* **All 88 tables migrated** (`database/migrations/`, six files: users, admin/addresses, companies, catalog, commerce, engagement/content,
  sessions). `categories` and `sub_categories` are separate tables.
* Seeders (`php artisan migrate:fresh --seed`): admin `admin`/`admin`, customer `test@gmail.com`/`test`, lookups (7 registration types,
  7 vendor types, 49 countries – placeholder values), sales channels (`standard` 5 days, `instadent` 3 days and login-only),
  settings (delivery fee ₹95, free above ₹2800), in-house company "Dentalkart (In-house)".
* Auth: admin panel = HttpOnly cookie session + CSRF; Postman/scripts and the customer site = bearer tokens. A credential only works
  on its own side. Login and public forms are throttled. CORS origins come from `.env` (`FRONTEND_URLS`).
* Image URLs in API responses are absolute (and `null` when missing). Run `php artisan storage:link` once.

### Company and brand module
* **Customer APIs** (`/api/v1/store`): dropdown lists (`lookups/registration-types`, `lookups/vendor-types`, `lookups/countries`,
  combined `company-registration/options`), `POST company-registrations`, `brands`, `brands/{slug}`, `settings/delivery`, `auth/*`.
* Registration rules: all company, contact and address fields; promotion text 200–500 characters; **company logo required**
  (png/jpg/jpeg/webp, ≤ 4 MB); **at least one brand**; GST number unique among non-rejected companies; catalogue optional and private.
  Creates a `pending` company with inactive brands; the admin approves it and its brands go live.
* **Brands, not companies, are shown to customers.** A brand shows its own logo, else the company logo (`logo_source`, `has_own_logo`,
  `remove_logo=1`). The last brand of a company cannot be deleted.
* **Admin APIs** (`/api/v1/admin`): `dashboard`; companies (list with status counts, create = auto-approved, show, update incl. logo,
  delete, approve, reject, suspend, private catalogue download); brands (per company and global list, create, update, delete);
  delivery settings (fee, free-delivery limit, delivery days per channel). The company list is lean (no N+1).

### Categories, sub-categories and sub-sub-categories
* Two tables (`categories`, `sub_categories`; a sub-sub-category is a `sub_categories` row with `parent_id`, one level only), soft deletes, slug from the name (unique, regenerated on rename), each with an optional `icon`
  (256px thumb) and `image` (spatie media, absolute URLs or `null`). `Domain/Catalog`: models, `SaveCategory`, `DeleteCategory`, `CategoryTree`.
* **Admin APIs**: `categories` (list = every category with its sub-categories, `sub_categories_count`, `products_count`, `?q=`), `categories/options`
  (light tree of active ones, for the product form), create, show, update (`POST` + `_method=PUT` with files, `remove_icon` / `remove_image`),
  delete; `categories/{id}/sub-categories` (create); `sub-categories/{id}/children` (create a sub-sub-category); `sub-categories/{id}` (show, update, delete). Every write is audited and clears the cache.
* **Top categories**: `categories.is_top` marks the (max 12) categories of the home screen; a 13th is refused with 422. A parent sub-category counts the products of its children.
* **Delete rules**: category with sub-categories or products, or a sub-category with children or products → 422 "Deactivate it instead of deleting".
  `is_active = false` hides it (and everything under it). There is no "show in menu" switch.
* **Customer APIs**: `GET store/categories` (`?top=1` for the home screen; each sub-category has `children`) and `GET store/categories/{slug}` (404 when inactive), cached 10 minutes.
* `CategorySeeder` (13 categories, 65 sub-categories from the frontend data, 11 example sub-sub-categories, the first 12 categories are top, no images; re-running it keeps admin edits).

### Admin panel (`admin-frontend/`, Vue 3)
* Dev: `npm run dev` → http://localhost:5190 (proxies `/api`, `/sanctum`, `/storage`). Built: `npm run build`, served at `/admin`.
* Companies and Categories tables start with an S.no column (`#`; categories number `1`, `1.1`, `1.1.1`).
* Categories page: compact table (icon, name, slug, sub-category and product counts, Active / Home switches that save at once, order,
  edit / delete, a "Top" tag and a Home n/12 counter), rows expand to their sub-categories, which expand to their sub-sub-categories, with "Add" buttons; create / edit in a modal with icon + image previews.
* Pages: login, dashboard, companies (tabs, search, filters, compact table with logo, contact, location, vendor/type, GST, brand names,
  status, date), create/edit company (logo, repeatable brands, catalogue), company detail (approve / reject / suspend / reactivate,
  catalogue download, brands add / edit / delete / on-off, own-logo upload).
* Compact layout; collapse button inside the sidebar (header menu button only on phones). Sidebar: Dashboard, Companies and Categories.

### Tests
* `php artisan test` → 55 PHPUnit feature tests pass (28 of them in `CategoryFlowTest`) (uses the `dentalkart_test` database; create it empty once).
* A real-browser run of the admin panel (login, cookie, approve, brands, logos, forms, search, collapse, mobile) passed in full
  on the dev server and on the Laravel-served build. The categories page was checked the same way (41 checks at first; after the top flag and sub-sub-categories were added, 38 more: S.no, top flag and its 12 limit,
  three levels, adding / renaming / deleting a sub-sub-category with an icon, delete refusal, search, mobile width). The 6 Postman requests were run against the live API: all succeed.

### Postman
* `docs/postman/Dentalkart-APIs.postman_collection.json` – 8 plain requests (3 dropdown lists, all dropdowns, register a company,
  brands list, categories tree, one category). `docs/postman/sample-logo.png` is the logo it uploads. If Postman shows a ⚠ next to the `logo` file it did not send
  it: enable "Allow reading files outside working directory" in Postman settings and select the file again.

## Next
1. Products (variants, tags, channels + stock, auto discount, images, content tabs).
2. Customer catalog (list, filters, search, detail, brand pages, Instadent gate).
3. Reviews and ratings, wishlist, Q&A, feedback.
4. Importer for the frontend's current demo data.
5. Admin screens for the dropdown lists (registration types, vendor types, countries) and brand "top / featured / new / suggested" flags.

## Notes
* Admin file uploads from a browser: send `POST` with `_method=PUT` (a real PUT cannot carry files).
* Production checklist: HTTPS, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS` = the real admin domain, strong
  `SEED_ADMIN_PASSWORD` (the seeder refuses weak passwords in production), `APP_URL` = public API URL (image URLs use it).
