# Architecture

An API-first **modular monolith**: one Laravel application, no microservices. The storefront is a separate app (any framework) that talks JSON to it;
the admin panel is a Vue single-page app that talks to the same API. **One deployment per client** – no multi-tenancy; client values live in `.env` and the `settings` table.

```
 Storefront (any framework) ──┐
 Admin panel (Vue 3)          ├──►  Laravel API  /api/v1
                              │        ├─ /store/*   public + customer bearer token
                              │        └─ /admin/*   admin session cookie (panel) or bearer token (scripts)
                                       │
                              MySQL 8 · local/S3 file storage · database cache/sessions
```

## Technology

| Concern | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.3 |
| Database | MySQL 8 (FULLTEXT search, row locks – SQLite is not supported) |
| Auth | Laravel Sanctum: customers = bearer tokens; admin panel = HttpOnly session cookie + CSRF (same endpoint also issues a token to non-browser clients) |
| Roles | spatie/laravel-permission is installed (a `super_admin` role is seeded) but permissions are **not enforced yet** – every admin can do everything |
| Media | spatie/laravel-medialibrary (category icons, product pictures) |
| API docs | dedoc/scramble → `/docs/api` |
| Admin panel | Vue 3 + Vite + Pinia + Vue Router in `admin-frontend/` (builds into `public/admin-assets`, served at `/admin`) |
| Tests | PHPUnit feature tests against a MySQL test database (`jhkstore_test`) |

## Code layout

```
app/
  Domain/                    business logic, one folder per module
    Address  Auth  Cart  Catalog  Order  Review  Reward  Support
      Models/  Actions/  Enums/  (+ module services)
  Http/
    Controllers/Store|Admin  thin controllers
    Middleware/              EnsureActor (alias `actor`)
    Requests/                FormRequest validation
    Resources/               API response shapes
  Support/                   Audit, FullTextSearch, GeneratesUniqueSlug, MediaUrl
routes/api_store.php  routes/api_admin.php  routes/web.php (serves the admin SPA at /admin)
database/migrations  database/seeders (DatabaseSeeder = base, DemoSeeder = optional sample data)
admin-frontend/          Vue admin panel
```

Rule: controller → FormRequest → **Action class** → Eloquent → Resource. Business rules live in `Domain/*/Actions` (`PlaceOrder`, `ChangeOrderStatus`,
`SaveProduct`, `CartPricing`, `RewardLedger`, …), never in controllers.

## Settings and optional features

* `Settings` (`app/Domain/Support/Settings.php`) is the only reader/writer of store configuration (see [SETTINGS.md](SETTINGS.md)).
* **Reward points** are optional (`rewards_enabled`, default off): *all* points logic goes through `RewardLedger` (`enabled()`, `pointsForLine()`,
  `spendable()`, `credit()`, `redeem()`, `refund()`). Off = nothing earned, redeemed or shown (cart, checkout, orders, products, admin). Data is kept.
* Money: amounts are `DECIMAL(12,2)`; cart maths run in whole minor units (`Settings::cents()/money()`), the number of decimals comes from `currency_decimals` (0–2).

## Catalogue model

* **Categories** – two levels: `categories` → `sub_categories`. A product has `category_id` (required) and `sub_category_id` (optional, must belong to the category).
  Customers see a cached tree (`CategoryTree`, 10 minutes, cleared on every admin write).
* **Products are parents of options (variants).** Price, MRP, stock, points, expiry, SKU and pictures are per option; the listing shows the cheapest option.
  A product is shown on the storefront when it is `active`, its category and sub-category are active and it has an active option.
* **Stock** lives on the option (`stock_qty`, `reserved_qty`, `low_stock_threshold`); every change writes `inventory_movements`.
* **Bulk prices** (`variant_price_tiers`): "buy N or more for X each", up to 5 per option.
* **Tags** – labels such as `best-seller`; the storefront builds home page sections with `GET /products?tag=best-seller`. The seven system tags are created by the demo seeder.
* Tax: product `tax_rate` (null = the store default), prices inclusive or exclusive of tax (setting).

## Cart, checkout, orders

* One cart per customer; guests get a server-issued `X-Cart-Token`, merged into the customer's cart on login/registration. **Every cart answer is recalculated on the server** (`CartPricing` is the only price calculator) and carries warnings (price changed, low stock, unavailable, quantity limits).
* **Checkout** (`POST /store/orders`, `PlaceOrder`) is one transaction: lock cart → lock the option rows (in id order, deadlock-free) → price again → validate → write order, lines (snapshots), addresses, payment, history → deduct stock → debit points → empty cart. An `Idempotency-Key` header makes retries return the same order.
* **Payment**: cash on delivery only today (`payments` row per order). Online payment gateways are a planned module.
* **Order numbers**: `<order_number_prefix>-<year>-<id, 6 digits>`.
* **Status flow** (`ChangeOrderStatus`, the only place a status changes): `pending → confirmed → packed → shipped → delivered`, one step at a time; `cancelled` while pending/confirmed/packed. Shipping needs a courier and tracking number. Delivered marks COD paid and credits points; cancelled returns stock and refunds points. Every change writes a history row and an audit log entry.

## Security and limits

* A credential only works on its own side (`actor:admin` / `actor:customer`). Admin login and public forms are throttled (login 8/min, forms 10/hour, cart 120/min, checkout 20/min, account 60/min – env-overridable).
* `Model::preventLazyLoading()` is on outside production; list endpoints are tested for a fixed number of queries.
* Known gaps: no permission enforcement for admins, no password reset / e-mail verification, customer tokens do not expire. See [FEATURES.md](FEATURES.md).
