# Changelog

## 2026-11-03 – Phase A (branch `lean-store`)

Settings foundation, delivery, payments, discounts and coupons, a persistent cart and order changes ([docs/FEATURE_SPEC.md](docs/FEATURE_SPEC.md), sections 2 to 9). The baseline migration was edited directly (no live database exists): **a database created from an earlier version must be rebuilt with `php artisan migrate:fresh --seed`**. 44 tables (was 36).

### Added
- **Settings**: tabs **Delivery**, **Payments**, **Store** and **Theme** (developer only); new setting types `color`, `choice` and `accounts`; theme tokens (colours, fonts from a fixed list, radius, shadow) public as `theme` in `GET /store/settings`; store open / closed with a closed message, a checkout notice and a delivery estimate text (a closed store still quotes, `POST /orders` answers `422 store_closed`); agency credit (`agency_name`, `agency_url`, https only) as `footer.agency`; public `payment` and `delivery.method`.
- **Delivery**: setting `delivery_method` (`flat`, `free`, `free_above`, `zones`, `owner_confirms`) and `delivery_unmatched` (`block`, `owner_confirms`, `flat`); table `delivery_zones` with the zone manager inside Settings → Delivery (city / area / postcode with `*` prefix, fee, free-above, minimum order, ETA, order, active); the quote takes an `address` and answers `delivery_fee` (null when unknown), `delivery_pending`, `delivery_needs_address`, `delivery_zone`; `delivery_fee_pending` orders with `PUT /admin/orders/{id}/delivery-fee`.
- **Payments**: `payment_method` `cod` or `bank_transfer` (`payment_method_unavailable` when switched off; the cash on delivery limit applies to cod only; bank accounts in Settings; card is only a "coming soon" label); the customer uploads a proof (`POST /store/orders/{number}/payment-proof?phone=`, private disk, wrong phone = the same 404); the admin sees it only through `GET /admin/orders/{id}/payment/proof`, verifies it (`payment/verify`) or rejects it (`payment/reject-proof`); an order cannot be confirmed before (`payment_not_verified`).
- **Discounts and coupons**: automatic discounts and coupons (tables `discounts`, `discount_targets`, `coupons`, `coupon_targets`, `coupon_usages`) calculated in `CartPricing`, admin screens and API (`/admin/discounts`, `/admin/coupons`, `/admin/coupons/{id}/usages`, `/admin/promotion-targets`), `POST /store/coupons/validate`, discounted prices and `discount_label` on product cards and the product page, order snapshots (`coupon_id`, `coupon_code`, `discount_total`, `coupon_discount`, line `discount_amount` / `discount_source`), usage written under a row lock (two orders cannot take the last use) and given back when the order is cancelled or rejected.
- **Persistent cart**: `GET/POST/PUT/DELETE /store/cart…` bound to a random token (`X-Cart-Token`), contact and coupon on the cart, `cart_token` accepted by the quote, the coupon check and `POST /store/orders` (the cart is emptied inside the order transaction), 30 days sliding expiry, `php artisan shop:prune-carts` scheduled daily.
- **Orders**: status `rejected` (only from pending, reason required, gives back stock, customer totals and the coupon use); order list filters `search`, `status`, `payment_method`, `payment_status` and per-status `counts`; the customer tracking answer has `payment {method, status, proof_status, reject_reason, …}`, `delivery_fee_pending`, `reject_reason`, `store_phone` and `whatsapp_url`.
- **Access control**: permissions `settings.theme` (developer only, never the admin), `settings.store`, `settings.delivery`, `settings.payments`, `discounts.view` / `discounts.manage`, `coupons.view` / `coupons.manage`. The admin starts with all of them except `settings.theme`; the developer with all. A permission added by an update is given to the roles whose defaults include it and never overrides what the developer switched.
- Rate limits for the cart (60/min), the coupon check (30/min) and the proof upload (10/min per IP, 10/hour per order number); config `PAYMENT_PROOF_MAX_KB`.
- Tests, `scripts/flow-check.mjs` (section F: Phase A over real HTTP, including the last coupon use across two PHP processes), the browser scripts and the docs, OpenAPI and Postman for all of it.

### Changed
- `delivery_fee`, `free_delivery_min_cart` and `delivery_days` moved from the Orders & Delivery tab to the new Delivery tab (permission `settings.delivery`); the keys are unchanged. The default `delivery_method` is `free_above`, which is the behaviour of earlier versions.
- `orders.shipping_fee` can be null (fee still to be confirmed); the product `price` on cards and detail pages is the price after a live percent discount (`option_price` has the option's own price, `compare_price` and `discount_label` are new).
- The dashboard's revenue and order count leave out rejected orders as well as cancelled ones.
- Settings → Orders & Delivery keeps the order number prefix, the minimum order and the cash on delivery limit.

### Fixed
- `PATCH /admin/orders/{id}/note` without the field `admin_note` answered `200` and **cleared the stored note**; the field is now required (`present`, null or "" clears on purpose) and a missing field is a `422`. Regression test: `OrderChangesTest::test_a_note_request_without_the_field_is_refused_and_does_not_clear_the_note`.

### Notes (where the spec left a choice)
- A **fixed** discount or coupon amount is taken off the eligible lines once, split by line total (remainder to the last lines); product cards and detail only apply **percent** discounts to the shown price, a fixed one only gives `discount_label`.
- `discount_total` is the automatic discounts only, `coupon_discount` is separate; per line the better of discount and coupon applies, never both; `discount_source` is `discount`, `coupon`, `bulk` or null.
- The store's minimum order and a coupon's `min_order` are checked after bulk prices and automatic discounts, before the coupon; free delivery above an amount uses the final subtotal.
- A coupon that is beaten everywhere by an automatic discount answers `no_eligible_items` and is not counted as used; a coupon that takes nothing off and is not free-delivery is not recorded as used.
- `PUT /store/cart/coupon` with a bad code is `422 coupon_invalid` and is not saved; `items` win over `cart_token` when both are sent; an unknown or expired token prices an empty cart in a quote and is `422 cart_invalid` in an order.
- `coupon_usages.released_at` marks a use that was given back; a coupon with any usage row cannot be deleted (deactivate it). The usage list only shows order numbers and phones to an admin who holds `orders.view`.
- Bank transfer: only a pending order takes a proof, one at a time, a new one only after a rejection; enabling bank transfer needs an active account; a verified bank order that is cancelled stays `paid` (refund by hand).
- The quote and the coupon check accept an optional `phone` so the per-customer and first-order rules can be checked; without it those two rules are skipped in the quote (the order always checks them).
- Only the Phase A permissions exist; `orders.export`, `settings.notifications` and `wholesale.*` arrive with Phase B (the developer can never hold `orders.export`).
## Unreleased – lean store (branch `lean-store`)

The product is now three things – **products, categories and orders** – plus the admin settings that control the website. Everything else is removed for now. The baseline migration was edited directly (no live database exists): rebuild a development database with `php artisan migrate:fresh --seed`.

### Removed
- **Customer accounts**: registration, login, logout, tokens and refresh, passwords, the customer address book, the `actor:customer` side, every customer-authenticated store endpoint, `CUSTOMER_TOKEN_MINUTES`.
- **Server-side cart**: `carts`, `cart_items`, `X-Cart-Token`, the cart endpoints, cart merging and the `carts:prune` command. The storefront keeps the cart.
- **Reward points** (wallets, transactions, redemptions, `RewardLedger`, `rewards_enabled` and every earn / redeem setting, `reward_points` on options), **reviews and ratings** (reviews, rating stats, `rating_avg` / `rating_count`, the `rating` filter and sort), **wishlists**, **coupons**, **notifications**, **FAQs**, **CMS pages**, homepage sections, collections, **invoices**, **refunds**, **webhook events**, shipment items and events, **pincodes** (and the check in `PlaceOrder`), product questions and feedback, password resets – code, routes, admin UI, settings, tests, seeders and docs. 36 tables remain.
- **Leftover columns**: `is_returnable`, `return_days`, `return_window_days` (setting), `returned_qty`, `expiry_date`, `barcode`, `users.business_name`, `country_of_origin`, `warranty_text`, every `tax_number`, `orders.membership_applied`, `rating` on categories and sub-categories, the refund bank / UPI / IFSC fields. The `warranty` content tab went with them.
- The generic **Users & roles** screen and API (`/admin/users`, `/admin/roles`), the roles `super_admin`, `manager` and `staff`, the Features settings tab and all feature switches, the Banners sidebar item (it is a Settings tab now), customer cancel and reorder.
- Test fixtures were neutralised (USD, `$`, `ORD`, "VAT", neutral country and phone numbers, no brand-specific names).

### Added
- **Guest checkout**: `POST /store/checkout/quote` (server-priced totals, bulk prices, tax, delivery fee, warnings; reuses `CartPricing`), `POST /store/orders` with `customer{name, phone, email?}`, `address{…}`, `items[]`, `customer_note?`, `expected_total?` (keeps `Idempotency-Key`, the stock locking and `409 price_changed`), `GET /store/orders/{orderNumber}?phone=` (a wrong phone is a `404`).
- **Customers**: `users` became `customers`, a contact record (name, unique normalised phone, optional e-mail, city, first / last order, orders count, total spent) found or created by phone on every order; `orders.customer_id`. Admin **Customers** list with search and CSV export (formula-safe).
- **Abuse protection** for the public endpoints: per-IP, per-phone and per-order rate limits, a maximum number of items and quantity per line, phone validation, a honeypot field (`config/shop.php`).
- **Two admin accounts and access control**: roles `developer` and `admin`, permissions per section and per Settings tab, a developer-only **Access Control** screen and API, `SEED_DEVELOPER_*` / `SEED_ADMIN_*`. The developer can never hold `orders.*` (tested), sees no orders and no order figures on the dashboard. Permission changes are audited. [docs/ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md).
- Admin panel: sidebar Dashboard, Orders, Products, Categories, Customers, Settings, Access Control; **Settings → Banners** tab with the full banner manager; per-tab Settings permissions.
- `docs/openapi.json`, a rewritten Postman collection and docs; the browser e2e covers both accounts.
- Tests: 270 → 227 (the removed modules' tests are gone; guest checkout, quote, customers, lookup, rate limits, access control added).

### Added (later)
- Setting `default_phone_country_code` (General tab, e.g. `+92`, empty by default): a phone with a single leading 0 and no country code gets this prefix, so `0300…` and `+92300…` are one customer (orders, order lookup, customer search). Empty = unchanged behaviour.

### QA
- Full flow check on the real app: `scripts/flow-check.mjs` (API, 239 assertions) and `admin-frontend/e2e/flow-ui.mjs` / `npm run flow-ui` (browser, 41 checks). Results and how to run them: [docs/QA_REPORT.md](docs/QA_REPORT.md).

### Fixed
- The admin **order search** and **customer search** for a local phone (`0300…`) now find the records stored with the default country code (`+92300…`).
- The demo seeder's bulk prices after the points column was removed were wrong; a test now checks them.

## Unreleased – stabilisation (branch `stabilize`)

Work done on top of the neutral base before any new feature. The baseline migration was edited directly (no live database exists): rebuild a development database with `php artisan migrate:fresh --seed`.

### Removed
- **Brands, completely**: tables `brands` and `brand_collections`, `products.brand_id`, `order_items.brand_id` / `brand_name`, models, actions, controllers, requests, resources, routes (`/brands`, the `brand` product filter, `brand` fields in product, cart and order answers, `brand_id` filter and field in the admin), the admin Brands page and product-form field, the `brands_enabled` setting, `features.brands`, seeders, tests and docs. Nothing replaces it.
- `PUT /admin/settings` no longer accepts the logo; `POST|DELETE /admin/settings/logo` stay (now stored with medialibrary).

### Added
- **Settings overhaul**: tabbed admin page (General, Branding, Footer & Social, Currency & Tax, Orders & Delivery, Features, SEO). New settings: favicon, ordered social / footer links (`links` type: platform, label, URL, active; unlimited; drag-to-reorder), footer text, copyright, support hours, WhatsApp number, default meta title / description, default share image. Logo, favicon and share image are uploaded (type, size and pixel limits; SVG refused) and stored with medialibrary. `Settings` gets the `links` and `file` types and `Settings::rules()` (validation by type). The admin payload is now `tabs` / `sections` / `files` instead of `groups` / `logo_url`.
- **Public `GET /store/settings`** now also returns `store.favicon_url`, `social_links[]` (active only, in order), `footer{}` and `seo{}`.
- **Banners**: admin management (create, edit, delete, reorder, activate, start / end dates, desktop + optional mobile picture, placements `home_hero`, `home_secondary`, `promo_strip`) and public `GET /store/banners?placement=` (live banners only, in order, absolute image URLs). `banners` table: `subtitle`, `button_text`, `link_url` replace `link_type` / `link_target`.
- **Product pictures**: `PUT /admin/products/{id}/images/{image}/primary`; uploads are limited to 100–8000 px (the admin already uploaded several pictures per product and per option, with reorder, delete and thumbnails).
- **Admin roles and permissions** (`super_admin`, `manager`, `staff`) enforced on every admin route with `admin.can:<permission>` (403 otherwise; a test walks all routes), `RolesSeeder`, `/auth/me` lists the permissions. **Users & roles** screen and API (`/admin/users`, `/admin/roles`): no self lock-out, the last active super admin stays, a new password or deactivation revokes API tokens.
- **Token expiry**: `CUSTOMER_TOKEN_MINUTES` (default 7 days) and `ADMIN_TOKEN_MINUTES` (default 12 hours); login / register return `expires_at`; new `POST /store/auth/refresh`; expired tokens are pruned daily.
- **Rate limits** for public reads (`PUBLIC_PER_MINUTE`, 240) and the admin API (`ADMIN_PER_MINUTE`, 600); every store and admin route is now limited. `APP_DEBUG` is forced off in production and the session cookie is secure by default in production.
- Admin panel: read-only forms and hidden buttons for roles without permission, "no access" page, `npm run e2e` browser smoke test (puppeteer-core), clearer validation messages (settings, banners, product pictures).
- Tests: 210 → 270 (brand tests removed; settings, banners, pictures, permissions, admin users, tokens, security added).

### Fixed
- Admin panel: the product edit page and the order detail page never opened (`\d+` in a JavaScript string became `d+`, so the routes did not match).
- "Add banner" from the page header opened with no placement selected; list pages flashed a skeleton after every save; initials of "Home & Kitchen" were "H&"; the "+" icon of empty-state buttons floated above the label; "Cod pending" now reads "COD pending".


## Unreleased – neutral, multi-client base

The project was cleaned up from a single-shop clone into a neutral, reusable e-commerce base (one deployment per client).

### Removed
- **Unused tables**: memberships, returns, OTP codes, referrals (and `users.referral_code` / `referred_by_user_id`), events, shipping rules, search terms, enquiries – 14 tables. Kept for the next phase: wishlist, coupons, reviews/Q&A, CMS, notifications, refunds, invoices, webhook events, shipment items/events.
- **Seller / company onboarding**: companies, vendor types, registration types and countries (tables, models, services, controllers, requests, routes, admin pages, seeders, tests, docs). Products and order lines no longer reference companies.
- **Sales channels** (standard / express): `sales_channels`, `product_channels`, `variant_channel_stock`, the channel middleware and query parameter, channel filters and switches in the admin panel.
- **Third category level** (sub-sub-categories): `sub_categories.parent_id`, the `children` API field, nested admin UI and queries. Existing products of a sub-sub-category move up to its parent sub-category.
- **Market-specific fields**: GSTIN validation, HSN/GST columns, dental customer profile fields (professional type, speciality, council number, verification status), `products.is_professional_only`, address type "clinic", India/Pakistan/PKR defaults.
- **Original-shop data and branding**: dental categories, demo products with hot-linked images, in-house company, demo orders, the `DK-` order prefix, admin branding, old Postman collections and docs.

### Added
- **One baseline migration**: all earlier migrations were squashed into `0001_01_01_000000_create_baseline_schema.php` for fresh installs (verified identical to the previous schema: 66 tables, columns, defaults, indexes and foreign keys). Existing development databases must be rebuilt with `migrate:fresh`.
- **Settings**: cached key/value `settings` table, one `Settings` service, admin **Settings** page (generic, built from the definitions), public `GET /store/settings`. Store name, logo, contact, currency (code, symbol, decimals), tax (rate, inclusive/exclusive, label), order number prefix, delivery, minimum order and COD limit are settings ([docs/SETTINGS.md](docs/SETTINGS.md)).
- **Optional brands** (`Enable brands`, default on) – brand is nullable on products; brand CRUD is a top-level admin page.
- **Optional reward points** (`Enable reward points`, default off) with earn rate and redeem rate; all points logic is behind `RewardLedger`.
- **Tax**: per-product `tax_rate` (null = store default), prices tax-inclusive or tax-exclusive.
- Stock now lives on the product option (`stock_qty`, `reserved_qty`, `low_stock_threshold`).
- `DemoSeeder` (optional, neutral sample data); `DatabaseSeeder` now seeds only default settings and the first admin.
- Docs: README, architecture, database, features, settings reference, storefront API, **How to create a new client project**; neutral Postman collection.

### Changed
- Database renamed to `jhkstore_db` (tests: `jhkstore_test`).
- API changes a storefront must know: `GET /store/settings` replaces `GET /store/settings/delivery`; no `?channel=`; cart/order summaries use `tax_total` / `tax_inclusive` / `tax_label` instead of `tax_included`; address field `tax_number` replaces `gst_number`; product `tax_rate` replaces `gst_rate`; variant `stock` is a number; points fields exist only while reward points are on.
- Admin panel: companies, channels and sub-sub-categories gone; new Brands and generic Settings pages; store name/logo/currency come from the settings.
- Tests: 231 → 210 (company, channel and sub-sub-category tests removed; settings, brand-toggle, rewards-toggle, tax and seeder tests added). All pass.

