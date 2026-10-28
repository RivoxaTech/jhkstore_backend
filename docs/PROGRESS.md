# Progress log

Updated: 2026-10-28 (products added). Read together with `ARCHITECTURE.md` / `DATABASE.md` (design). Setup steps: `../README.md`.

## Where we are

| Area | State |
|---|---|
| Database schema (88 tables) | ✅ complete, migrated |
| Auth (admin cookie + token, customer token) | ✅ |
| Company module (register, approve, brands, logos) | ✅ |
| Categories and sub-categories (admin + customer API) | ✅ |
| Products (admin CRUD, variants, tags, channels + stock, images, content tabs) | ✅ |
| Customer catalogue (list, filters, sort, detail, tags, Instadent gate) + reviews | ✅ |
| Admin panel (`admin-frontend/`) | ✅ dashboard + companies + categories + products + tags |
| Wishlist, Q&A, feedback, review moderation | ⏳ next |
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
  settings (delivery fee Rs 300, free above Rs 8,500), in-house company "Dentalkart (In-house)".
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

### Products, tags, catalogue and reviews
* **Domain:** `Domain/Catalog` (`Product`, `ProductVariant`, `VariantChannelStock`, `Tag`, `SalesChannel`, actions `SaveProduct`, `SyncProductVariants`, `RefreshProductSummary`,
  `ManageProductImages`, `SaveTag`, `ProductListing`, `ProductImages`) and `Domain/Review` (`SaveReview`, `RecomputeProductRating`). No migration was needed.
* **Rules:** a product needs an approved company, an active brand of that company, a category and an optional sub-category of it (all validated; an unchanged
  choice that was switched off later does not block other edits). Every product has at least one variant: a simple product keeps one hidden default variant
  (flat `mrp` / `selling_price` / `reward_points` / `stock` accepted), a product with options sends `variants`. `discount_percent` is calculated on save,
  price must be <= MRP. `min_price`, `max_discount_percent`, `is_in_stock`, `rating_avg`, `rating_count` are kept in sync. Stock is separate per channel
  (standard / instadent) and every change is written to `inventory_movements`. Slug is automatic, unique and stable; SKU unique.
* **Admin APIs:** `products` (lean list with filters + per-status counts, create, show, update, delete = soft), `products/options`, `PATCH products/{id}/status`,
  `PUT products/{id}/tags`, pictures (`POST products/{id}/images` several at once, `PUT .../images/order`, `DELETE .../images/{mediaId}`), `tags` CRUD (system tags cannot be deleted).
  All writes are audited and shown in the dashboard activity.
* **Customer APIs:** `GET store/products` (`tag`, `category`, `sub_category`, `brand`, `q`, `min_price`, `max_price`, `in_stock`, `rating`, `channel`, `sort`, paging),
  `GET store/products/{slug}` (content tabs, variants with the channel's stock, images, related), `GET store/tags`, `GET store/products/{slug}/reviews` (+ summary),
  `POST store/products/{slug}/reviews` (customer token; one review per customer, posting again updates it; stats recomputed in the same transaction).
  Homepage sections = the list with a tag (`?tag=best-seller&per_page=10`). `channel=instadent` answers 401 to guests (`channel` middleware).
  Only visible products are returned: active product + live brand + active category / sub-category + active channel row; out-of-stock ones list with `in_stock=false`.
  Everything is eager loaded (the tests assert a fixed number of queries and lazy loading is prevented).
* **Seeds:** `TagSeeder` (7 system tags, in `DatabaseSeeder`); optional `ProductDemoSeeder` (`php artisan db:seed --class=ProductDemoSeeder`, not in `DatabaseSeeder`): the 20 distinct
  products of the frontend (prices converted to PKR, about 3x the old INR numbers) plus 3 Dentaltech products (one option with 3 offers, three options, two options with offers), 0-3 bulk prices ("offers") on every product, a sub-category each, 4 demo reviewers' reviews (password `test`) (`database/seeders/data/demo-products.json`, exported once from `frontend/src/data/products.js`) under the in-house company, brands from the brand names,
  remote image urls, both channels with stock, tags from `section` / `badge` (plus best-price-zone for 50%+ off and new-arrival for four Dentaltech products so every section has data).

### Dentalkart-style product page (second pass)
* Compared with dentalkart.com product pages and added: options with **their own names and pictures**, **bulk prices** ("Buy 5+ for ... each", with extra %), **return period** ("10-Days Returnable"),
  **"Arrives by"** date from the channel's delivery days, **low stock** flag, per-option COD override, **related products** picker, SEO title/description, weight per option.
  Migration `2026_10_28_000003` (`products.return_days`). The detail API returns all of it (see ARCHITECTURE 6.4).
* **Currency is PKR** (`Rs`) everywhere: settings `currency_code` / `currency_symbol`, admin panel prices. HSN / GST labels became **HS code** / **Sales tax %** (API field names unchanged).
* Admin form: each option is a card (name, SKU, price, live discount, points, expiry, weight, bulk prices, its pictures); the product list shows at most 2 tags and "+N".
* Tests: 109 -> 118 PHPUnit feature tests; the browser run (Playwright) now has 79 checks, all passing.

### Parent product / options, drag and drop, pagination, search (third pass)
* **Every product is a parent of options.** The "simple product" mode is gone: the form always lists options (at least one, each with its own name, SKU, price, bulk prices, stock and pictures);
  the cheapest option gives "Starting at" (shown in the form and as the card price). A single option follows the product name / SKU until edited. Cards return `options_count`, `has_single_option`, `offers_count`, `show_starting_at` and `badge_label` (single option: "N Offers" or "1 Option" with a direct price and the option's own name; several options: "Starting at" and "N Options"). The detail page returns `display_mode`.
  Migration `2026_10_28_000004` renames the old hidden "Default" options to their product name. `has_options` was removed from the admin API.
* **Pictures:** drag and drop (plus arrows and "make primary"); imported (remote) pictures can be moved too - they share one order with uploaded ones. Option galleries are separate.
* **Pagination everywhere:** categories (totals in `counts`), tags (in the modal, with search), plus products, companies, brands, reviews and the store lists.
* **Rows per page (25 / 50 / 75 / 100)** at the top right of every admin table (products, companies, categories, tags): default 25, the choice is kept in the browser and shared by all tables (products and companies also keep it in the URL as `?per_page=`). The APIs accept `per_page` up to 100.
* **Search with indexes:** `ProductSearch` (FULLTEXT for words, unique SKU indexes, brand-name index; short words fall back to LIKE), company search (FULLTEXT name, GST / email starts),
  tag search by name start. FULLTEXT only sees committed rows, so `ProductSearchTest` runs with table truncation instead of a transaction.
* **Layout:** the product list fits 1366px and wider without a horizontal scroll bar (narrower screens get a slim, rounded scroll bar).

### Admin panel (`admin-frontend/`, Vue 3)
* **Products** (sidebar, under Catalogue): compact list (picture, name + SKU, brand + company, category, price + MRP + % off, stock per channel, tags, status), status tabs with counts,
  URL-synced filters (search, company, brand, category, sub-category, tag, channel), pagination, quick status select and quick tags modal per row, delete with confirmation, a **Tags** modal
  (add, rename, colour, on/off, delete custom tags). **Product form** (create / edit): Basic (company -> brands of that company, category -> sub-category tree), Pricing (simple product or options
  table, live "xx% off", client check price <= MRP), Stock and sales channels (Standard / Instadent switches, quantity per channel), Content tabs, Status, Tags (chips), Pictures (several at once,
  make primary / move / delete; on a new product they upload right after it is saved). Validation errors appear under the fields.
* Dev: `npm run dev` → http://localhost:5190 (proxies `/api`, `/sanctum`, `/storage`). Built: `npm run build`, served at `/admin`.
* Companies and Categories tables start with an S.no column (`#`; categories number `1`, `1.1`, `1.1.1`).
* Categories page: compact table (icon, name, slug, sub-category and product counts, Active / Home switches that save at once, order,
  edit / delete, a "Top" tag and a Home n/12 counter), rows expand to their sub-categories, which expand to their sub-sub-categories, with "Add" buttons; create / edit in a modal with icon + image previews.
* Pages: login, dashboard, companies (tabs, search, filters, compact table with logo, contact, location, vendor/type, GST, brand names,
  status, date), create/edit company (logo, repeatable brands, catalogue), company detail (approve / reject / suspend / reactivate,
  catalogue download, brands add / edit / delete / on-off, own-logo upload).
* Compact layout; collapse button inside the sidebar (header menu button only on phones). Sidebar: Dashboard, Companies and Categories.

### Tests
* `php artisan test` → PHPUnit feature tests pass (CategoryFlowTest, ProductAdminTest, ProductStoreTest, ProductSearchTest ...: see `php artisan test`) (uses the `dentalkart_test` database; create it empty once).
* A real-browser run of the admin panel (login, cookie, approve, brands, logos, forms, search, collapse, mobile) passed in full
  on the dev server and on the Laravel-served build. The categories page was checked the same way (41 checks at first; after the top flag and sub-sub-categories were added, 38 more: S.no, top flag and its 12 limit,
  three levels, adding / renaming / deleting a sub-sub-category with an icon, delete refusal, search, mobile width). The 6 Postman requests were run against the live API: all succeed.

* Products: a real-browser run (Playwright, first run 69 checks, later 79) of the admin pages on the Laravel-served build: list, columns, filters in the URL and after reload, status / tag quick actions, tags modal (add, duplicate error,
  rename, delete, system tag locked), empty-form errors, company -> brand filtering, live discount, price > MRP check, channels + stock, tags, pictures (choose, make primary, reorder, upload, delete), product with options,
  server error under an option SKU, channel toggle, what the website API then returns, delete, 400px width. All passed.

### Postman
* `docs/postman/Dentalkart-Products-Frontend.postman_collection.json` – for the frontend developer: 32 flat requests that SHOW products (login, tags, product list with every filter / sort / section / paging / Instadent, single-option and multi-option product pages, reviews list, post a review). Seed with `migrate:fresh --seed` + `ProductDemoSeeder`, press Send; the login request saves `customer_token` by itself.
* `docs/postman/Dentalkart-APIs.postman_collection.json` – 12 plain requests, one flat list (3 dropdown lists, all dropdowns, register a company, brands list, products list (`tag=best-seller`),
  product detail, tags, product reviews, customer login, post a review - paste the login token into the variable `customer_token`). Categories are in `Category-Display.postman_collection.json`. `docs/postman/sample-logo.png` is the logo it uploads. If Postman shows a ⚠ next to the `logo` file it did not send
  it: enable "Allow reading files outside working directory" in Postman settings and select the file again.

## Next
1. Wishlist, Q&A, feedback, review moderation (admin), product relations / price tiers in the admin form.
2. Cart, checkout and orders (stock reservation uses `reserved_qty` and `RefreshProductSummary`).
3. Collections / homepage sections / banners (content module).
4. Admin screens for the dropdown lists (registration types, vendor types, countries) and brand "top / featured / new / suggested" flags.

## Notes
* Admin file uploads from a browser: send `POST` with `_method=PUT` (a real PUT cannot carry files).
* Production checklist: HTTPS, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS` = the real admin domain, strong
  `SEED_ADMIN_PASSWORD` (the seeder refuses weak passwords in production), `APP_URL` = public API URL (image URLs use it).
