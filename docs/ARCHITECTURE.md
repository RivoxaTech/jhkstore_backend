# Architecture

An API-first **modular monolith**: one Laravel application, no microservices. The storefront is a separate app (any framework) that talks JSON to it;
the admin panel is a Vue single-page app that talks to the same API. **One deployment per client** – no multi-tenancy; client values live in `.env` and the `settings` table.

```
 Storefront (any framework) ──┐   public, no login, no token
 Admin panel (Vue 3)          ├──►  Laravel API  /api/v1
                              │        ├─ /store/*   public: settings, banners, catalogue, quote, coupons, cart, guest orders
                              │        └─ /admin/*   admin session cookie (panel) or bearer token (scripts), a permission per route
                                       │
                              MySQL 8 · local/S3 file storage · database cache/sessions
```

## Technology

| Concern | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.3 |
| Database | MySQL 8 (FULLTEXT search, row locks – SQLite is not supported) |
| Auth | Only the two admin accounts log in. Laravel Sanctum: admin panel = HttpOnly session cookie + CSRF (the same login endpoint issues an expiring bearer token to non-browser clients). **Customers have no account** |
| Roles | spatie/laravel-permission: roles `developer` and `admin`; the permission list and the hard rules are in `App\Domain\Auth\AdminAccess`, the role → permission rows are in the database and switched by the developer ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)). Every admin route is protected by `admin.can:<permission>` |
| Media | spatie/laravel-medialibrary (category icons, product pictures, banners, the logo / favicon / share image of the settings on the `public` disk; bank transfer payment proofs on the **private** `local` disk) |
| API docs | dedoc/scramble → `/docs/api`, exported to `docs/openapi.json` |
| Admin panel | Vue 3 + Vite + Pinia + Vue Router in `admin-frontend/` (builds into `public/admin-assets`, served at `/admin`) |
| Tests | PHPUnit feature tests against a MySQL test database (`jhkstore_test`); a puppeteer browser smoke test (`npm run e2e`) |

## Code layout

```
app/
  Domain/                    business logic, one folder per module
    Auth  Cart  Catalog  Content  Customer  Delivery  Order  Promotion  Support
      Models/  Actions/  Enums/  (+ module services)
  Http/
    Controllers/Store|Admin  thin controllers
    Middleware/              EnsureActor (`actor`), EnsureAdminPermission (`admin.can`), RejectHoneypot (`honeypot`)
    Requests/                FormRequest validation
    Resources/               API response shapes
  Support/                   Audit, FullTextSearch, GeneratesUniqueSlug, MediaUrl, TokenLifetime
routes/api_store.php  routes/api_admin.php  routes/web.php (serves the admin SPA at /admin)  routes/console.php (the schedule)
config/shop.php          checkout limits, honeypot field name, public rate limits, payment proof limits
database/migrations  database/seeders (DatabaseSeeder = base, DemoSeeder = optional sample data)
admin-frontend/          Vue admin panel
```

`Domain/Cart` holds `CartPricing` (the one price calculator) with its `PricedCart` / `PricedLine` / `PricingContext`, used by the quote, the coupon check, the persistent cart and checkout, and the persistent cart itself (`Cart`, `CartItem`, `Actions/ManageCart`, `Actions/QuoteCheckout`).
`Domain/Promotion` has `Discount`, `Coupon` (+ targets, `CouponUsage`), the `Promotions` reader (live discounts, loaded once per request) and the save actions. `Domain/Delivery` has `DeliveryZone`, the `ZoneMatcher` and `SaveDeliveryZone`.

Rule: controller → FormRequest → **Action class** → Eloquent → Resource. Business rules live in `Domain/*/Actions` (`PlaceOrder`, `ChangeOrderStatus`, `SaveProduct`, `UpsertCustomer`, …) and `CartPricing`, never in controllers.

## Settings

* `Settings` (`app/Domain/Support/Settings.php`) is the only reader/writer of store configuration (see [SETTINGS.md](SETTINGS.md)). Typed values include `file` (medialibrary picture owned by the setting row) and `links` (ordered JSON list), with validation rules by type in `Settings::rules()`.
* The admin Settings page is built from `SettingController::TABS`; each tab has its own permission, so the developer decides which tabs the client sees. A new tab is a new entry there (see [SETTINGS.md](SETTINGS.md)).
* Setting types: `string`, `text`, `bool`, `int`, `money`, `percent`, `file`, `links`, `accounts` (ordered bank accounts), `color` (`#RRGGBB`) and `choice` (one value of a fixed list, `Settings::CHOICES`). Two rules need more than one key and are checked in `SettingController` (one payment method stays on; bank transfer needs an active account).
* **Banners** (`Domain/Content`): one `banners` table, pictures with medialibrary (`desktop`, `mobile`). A placement is a key in `Banner::PLACEMENTS` (add a line for another spot). The public endpoint returns the `Banner::live()` ones in `sort_order`; one banner or a slider is the storefront's choice. Admin management is the *Banners* tab of Settings (`settings.banners`).
* Money: amounts are `DECIMAL(12,2)`; price maths run in whole minor units (`Settings::cents()/money()`), the number of decimals comes from `currency_decimals` (0–2).

## Catalogue model

* **Categories** – two levels: `categories` → `sub_categories`. A product has `category_id` (required) and `sub_category_id` (optional, must belong to the category).
  The storefront sees a cached tree (`CategoryTree`, 10 minutes, cleared on every admin write).
* **Products are parents of options (variants).** Price, MRP, stock, SKU and pictures are per option; the listing shows the cheapest option.
  A product is shown on the storefront when it is `active`, its category and sub-category are active and it has an active option.
* **Pictures** are uploaded in the admin panel (`POST /admin/products/{id}/images`, optionally with `variant_id` for an option's own gallery) and stored with medialibrary (`thumb` 256 px and `medium` 512 px conversions). PNG / JPG / WEBP, 100–8000 px, up to 8 MB, at most 12 per gallery; SVG is refused. The first picture of a gallery is its primary one (`PUT …/images/{id}/primary`, or `PUT …/images/order`). Remote-URL rows in `product_images` only exist for imported data.
* **Stock** lives on the option (`stock_qty`, `reserved_qty`, `low_stock_threshold`); every change writes `inventory_movements`.
* **Bulk prices** (`variant_price_tiers`): "buy N or more for X each", up to 5 per option.
* **Tags** – labels such as `best-seller`; the storefront builds home page sections with `GET /products?tag=best-seller`. The seven system tags are created by the demo seeder.
* Tax: product `tax_rate` (null = the store default), prices inclusive or exclusive of tax (setting).

## Guest checkout and orders

* **No customer accounts.** The storefront keeps the cart (variant ids and quantities) itself, or uses the **persistent server cart**: a random 40-character token the browser keeps (`X-Cart-Token`, `Domain/Cart`, tables `carts` / `cart_items`; prices are never stored, only option ids and quantities, and the cart is priced again on every read). A cart expires 30 days after its last activity (every call renews it); `php artisan shop:prune-carts` runs daily from the scheduler. `POST /store/checkout/quote` prices items or a `cart_token` with `CartPricing`; **every price is recalculated on the server**, the client's prices are ignored.
* **`CartPricing`** is the only place prices are calculated, in whole minor units: option price or bulk price → the best **automatic discount** per line (never stacked; priority on ties; a fixed amount is taken off the eligible lines once, split by line total, the remainder goes to the last lines) → an optional **coupon** (for each line the better of discount and coupon, never both; `min_order` after bulk prices and discounts; `max_discount` caps the total; a free-delivery coupon takes nothing off the items) → tax on what is paid → **delivery** by the store's method (`flat`, `free`, `free_above`, `zones`, `owner_confirms`; zones are matched in `sort_order`, an address outside every zone follows `delivery_unmatched`). The minimum order is checked after bulk prices and automatic discounts, before the coupon; free delivery above an amount uses the final subtotal. `CartPricing::displayPrice()` gives the product lists the same discounts: only a **percent** discount lowers a shown price, a fixed amount only gives `discount_label`.
* **Customers** (`customers`) are a contact record: every order finds or creates the customer by **normalised phone** (`Phone::normalise`: digits, leading `+` kept, `00` → `+`, a single leading `0` → the `default_phone_country_code` setting when it is set), updates the name / e-mail, links `orders.customer_id` and updates `orders_count`, `total_spent`, `first_order_at`, `last_order_at` (a cancelled order is taken out again). The order keeps snapshots of the contact and both addresses.
* **Checkout** (`POST /store/orders`, `PlaceOrder`) first refuses a closed store (`store_closed`) and a payment method the store switched off (`payment_method_unavailable`), then is one transaction: lock the cart row (when a `cart_token` is used) → lock the option rows (in id order, deadlock-free) → look for an earlier order with the same `Idempotency-Key` from the same phone → price again with the coupon row locked `FOR UPDATE` (always in this order: cart, options, coupon) → validate (stock, quantity limits, coupon → `422 coupon_invalid`, delivery zone → `422 delivery_not_available`, minimum order, COD limit for cash on delivery, the total the customer saw → `409 price_changed`) → find or create the customer → write order, lines, addresses, payment, history → deduct stock → write the coupon use and raise `used_count` (so two orders cannot take the last use) → empty the cart. A failed order leaves nothing behind, not even a customer.
* **Order lookup** (`GET /store/orders/{number}?phone=`): the order's phone must match; a wrong phone is a `404` identical to a missing order.
* **Payment**: `cod` (collected on delivery) or `bank_transfer` (`payments` row per order; card is only a "coming soon" label and can never be chosen). A bank transfer order starts `awaiting_payment`; the customer uploads a screenshot (`POST /store/orders/{number}/payment-proof`, stored with medialibrary on the **private** disk, no public URL; the admin sees it through the authenticated route `GET /admin/orders/{id}/payment/proof`); the admin verifies it (order and payment become `paid`) or rejects it with a reason (the customer uploads again). Until verified the order cannot be confirmed (`payment_not_verified`). Online gateways remain a possible later module.
* **Delivery fee pending**: with `owner_confirms` (or an unmatched address set to `owner_confirms`) the order is placed with `shipping_fee = null` and `delivery_fee_pending = true`; the admin sets the fee (`PUT /admin/orders/{id}/delivery-fee`), the total follows; confirming is refused until then (`delivery_fee_pending`).
* **Order numbers**: `<order_number_prefix>-<year>-<id, 6 digits>`.
* **Status flow** (`ChangeOrderStatus`, the only place a status changes; admin only): `pending → confirmed → packed → shipped → delivered`, one step at a time; `cancelled` while pending/confirmed/packed; `rejected` only from pending, with a reason (≤ 300) the customer sees. Shipping needs a courier and tracking number. Delivered marks COD paid. Cancelled and rejected return the stock, reverse the customer's totals, give the coupon use back (`coupon_usages.released_at`) and void a payment that was not taken (a verified bank transfer stays `paid`: the shop refunds it by hand); both are final and do not count as revenue on the dashboard. Every change writes a history row and an audit log entry.

## Security and limits

* **Access.** Two admin accounts, two roles, a permission per section and per Settings tab ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)). A missing permission answers `403 {"message":"You do not have permission to do this."}` – checked before the record is looked up, so nothing leaks. A test walks every admin route and fails if one has no `admin.can` middleware. The developer can never hold `orders.*` (enforced on write and on every check).
* **Admin tokens expire.** Admin API tokens live `ADMIN_TOKEN_MINUTES` (default 12 hours; the panel uses the session cookie, `SESSION_LIFETIME`); 0 = never. `sanctum:prune-expired` runs daily.
* **Passwords** are hashed with bcrypt (`BCRYPT_ROUNDS`, default 12) through the model's `hashed` cast and are never returned by the API or written to the audit log.
* **Public endpoints without login** are protected by validation (phone shape, field lengths, at most `SHOP_MAX_ITEMS_PER_ORDER` lines and `SHOP_MAX_QTY_PER_LINE` units per line), a honeypot field, and rate limits: quote 60/min per IP; orders 10/min and 60/hour per IP and 10/hour per phone; order lookup 20/min per IP and 15/hour per order number; cart (every call) 60/min per IP; coupon check 30/min per IP; payment proof upload 10/min per IP and 10/hour per order number; public reads 240/min per IP. Admin API 600/min per admin, login 8/min per name + IP. A test checks that every store and admin route has a limit. The proof upload checks the order and the phone before it validates the file, so a wrong phone is the same `404` as an unknown order whatever the file is.
* **CORS** allows only the origins of `FRONTEND_URLS` (never `*`; credentials are enabled for the admin panel session).
* **Debug is off in production.** `APP_DEBUG` defaults to false and is forced off when `APP_ENV=production` (a warning is logged); error answers carry no trace. `SESSION_SECURE_COOKIE` defaults to on in production; session cookies are HttpOnly and SameSite=lax.
* `Model::preventLazyLoading()` is on outside production; list endpoints are tested for a fixed number of queries.
* Known gaps: no captcha (honeypot and rate limits only); no e-mail, SMS or push notifications; no online card payments. See [FEATURES.md](FEATURES.md).
