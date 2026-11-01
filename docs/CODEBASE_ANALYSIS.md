# Codebase Analysis (read-only audit)

Date: 2026-11-01 · Branch `main` @ `6b89d70` · Scope: whole repo (`app/`, `routes/`, `database/`, `config/`, `tests/`, `docs/`, `admin-frontend/`).

**Method.** I read the code and docs directly. I ran only read-only commands (`php artisan route:list`, `about`, `list`). I did **not** run the migrations, seeders or tests: the database `dentalkart_db` does not exist on this machine (`db:show` → *Unknown database*). The `admin-frontend/` read was done by a helper agent and I spot-checked its key claims (branding, locale, hardcoded strings) against the files. Where something comes only from `docs/*.md` and I did not confirm it in code, it is marked *(docs)*.

---

## 0. One-paragraph summary

This is a **well-built, API-first Laravel 13 modular monolith** with a **separate Vue 3 admin SPA**. There is **no customer storefront in this repo**: it exposes a JSON API (`/api/v1/store/*`) for a storefront that lives elsewhere (docs say `../frontend`, a mock-data Vue 3 clone of dentalkart.com). Real, tested features: auth, companies/brands (seller onboarding), 3-level categories, products with options (variants), bulk prices, two sales channels, cart (guest + customer), addresses, COD checkout, orders with a status flow, a coin (reward) ledger, reviews, admin panel for all of that. Everything else in the 82-table schema (wishlist, coupons, returns, refunds, invoices, memberships, banners/CMS, enquiries, OTP, notifications…) is **schema only, no code**. Dentalkart/dental specifics are **mostly data, labels and defaults** rather than structural, with three real structural couplings: **(1) every product requires a company + brand, (2) the two hard-coded-by-seed sales channels "standard / instadent", (3) "coins" wired through cart → order → user wallet.** Authorization is **login-only: roles exist but are never enforced**.

---

## 1. Stack and versions

| Item | Value | Source |
|---|---|---|
| PHP | `^8.3` required; local 8.3.33 | `composer.json`, `php -v` |
| Laravel | `laravel/framework ^13.17`, installed **13.38.0** | `composer.lock` |
| Auth | **Laravel Sanctum 4.3.3** (no Passport/JWT). Two guards: `web` (users) and `admin` (session, `admins` provider) | `config/auth.php` |
| Roles | `spatie/laravel-permission 8.3.2` (guard `admin`) | |
| Media | `spatie/laravel-medialibrary 11.23.11` (GD driver, 10 MB max, `public` disk) | `config/media-library.php` |
| API docs | `dedoc/scramble 0.13.51` → `/docs/api`, OpenAPI 3.1 | `config/scramble.php` |
| Dev tools | pail 1.2.7, pao 1.1.5, pint 1.32.1, tinker 3.0.2, collision | `composer.json` |
| Tests | `phpunit 12.5.40`, 14 feature-test files, **no `tests/Unit`** (but `phpunit.xml` lists that suite) | |
| Admin SPA | Vue **3.5.44**, vue-router **5.4**, pinia **4.1**, `@lucide/vue 1.61`, Vite **8.3.5**, `@vitejs/plugin-vue 6.0.10`. No axios, no UI lib, no i18n, no TypeScript, no lint, no tests | `admin-frontend/package.json` |
| Node | no `engines` field; Vite 8 needs `^20.19 \|\| >=22.12`; local Node 24.18 | lockfile |
| Root `package.json` | Stock Laravel scaffold: Vite 8, `laravel-vite-plugin`, Tailwind 4. **Builds nothing the app uses** (`resources/js/app.js` is empty, `welcome.blade.php` is Laravel's default page) | |
| Database | **MySQL 8 only** (FULLTEXT indexes, row locks, `FOR UPDATE`); tests also need MySQL (`dentalkart_test`) – SQLite will not work | `phpunit.xml`, migrations |
| Queue | configured `database` driver, **no jobs, listeners, mailables or notifications exist** in `app/`. Media conversions are explicitly `->nonQueued()`. `php artisan queue:work` does nothing today | `.env.example`, grep |
| Cache | `database` store. Used for: category tree (10 min, key `store.categories.tree`), `SiteSetting::read` (`rememberForever` per key) | |
| Sessions | `database` driver (admin cookie sessions) | |
| Mail | `MAIL_MAILER=log`; no mail code | |
| Redis | env vars present, `phpredis` default client; **not used** | |
| Payment | **COD only** (`PaymentMethod` enum has a single case `cod`). No gateway interface/adapter exists, despite `docs/ARCHITECTURE.md` §3 promising "adapters with a fake driver" | `app/Domain/Order/Enums` |
| SMS / OTP / courier | **none**. `otp_codes` table exists, unused. Courier = free-text `carrier` + `awb_number` typed by an admin | |
| Search | MySQL FULLTEXT + indexed prefix lookups (`ProductSearch`, `OrderSearch`). No Scout/Meilisearch | |
| Scheduler | `carts:prune` daily 03:15, `rewards:expire` daily 03:30 (`routes/console.php`) | |
| Timezone | `APP_TIMEZONE` env (default `UTC` in `config/app.php`; `.env.example` says `Asia/Karachi`; **the local `.env` does not set it**) | |

**Auth model in detail**
- **Admin panel**: `POST /api/v1/admin/auth/login` picks the mode automatically. A browser on a stateful origin (`SANCTUM_STATEFUL_DOMAINS`) gets an HttpOnly session cookie + CSRF (`/sanctum/csrf-cookie`). Anything else (Postman, scripts) gets a bearer token. Admin routes accept `auth:admin,sanctum` + `actor:admin`.
- **Customer**: bearer tokens only (`createToken('customer')`), login by email **or** mobile + password. Tokens **never expire** (`sanctum.expiration = null`).
- `EnsureActor` middleware stops a customer token from working on admin routes and vice versa (also rejects `is_active=false`).
- `bootstrap/app.php` calls `statefulApi()`; API errors always render as JSON.
- Rate limits (`AppServiceProvider`): login 8/min per login+IP; forms 10/hr per IP (500 in local); reviews 30/hr; cart 120/min; orders 20/min; account 60/min.
- **Missing**: customer or admin password reset, email/mobile verification, OTP login, customer profile update endpoint, admin user/role management, admin password change.

---

## 2. Folder structure

```
.
├─ app/
│  ├─ Console/Commands/          ExpireRewardCoins (rewards:expire), PruneCarts (carts:prune)
│  ├─ Domain/                    business logic, one folder per module (Models / Actions / Enums)
│  │   Address  Auth  Cart  Catalog  Company  Order  Review  Reward  Support
│  ├─ Http/
│  │   Controllers/Admin|Store   thin controllers (10 admin, 11 store)
│  │   Middleware/               EnsureActor (alias `actor`), ResolveChannel (alias `channel`)
│  │   Requests/(Admin|Store)    FormRequests (validation lives here, some are 280+ lines)
│  │   Resources/                API response shapes (+ OrderPresenter)
│  ├─ Providers/AppServiceProvider.php   rate limiters, `Model::preventLazyLoading` (non-prod)
│  └─ Support/                   Audit, FullTextSearch, GeneratesUniqueSlug, MediaUrl
├─ bootstrap/app.php             routing: web + two API route files prefixed /api/v1/{store,admin}
├─ config/                       default Laravel + cors, permission, media-library, sanctum, scramble
├─ database/
│  ├─ migrations/ (17 files)     see §3
│  ├─ seeders/ (11) + data/demo-products.json
│  └─ (no factories/ directory)  models declare `HasFactory` but there are no factories
├─ docs/                         4 markdown docs + 4 Postman collections + sample-logo.png (§13)
├─ public/                       index.php, favicon, robots. `admin-assets/` (built admin SPA) is git-ignored
├─ resources/                    Laravel scaffold only (welcome.blade.php, empty app.js, app.css with Tailwind)
├─ routes/                       api_store.php, api_admin.php, web.php, console.php
├─ storage/                      standard Laravel (media goes to `storage/app/public` → `/storage`; private company catalogues on `local` disk)
├─ tests/                        Feature/ (14 files), Concerns/ (BuildsCatalog, BuildsShop), TestCase.php
└─ admin-frontend/               Vue 3 admin SPA (own package.json, builds into ../public/admin-assets)
```

Architecture rule (docs + code agree): `Controller → FormRequest → Action class → Eloquent → Resource`. In practice the code follows it well. Business rules sit in `Domain/*/Actions` (`PlaceOrder`, `ChangeOrderStatus`, `SaveProduct`, `CartPricing`, `RewardLedger`…). Not present although the docs mention them: Policies, Events, Jobs, Notifications, `Wishlist/Payment/Shipping/Returns/Membership/Promotion/Content/Enquiry/Notification` domains.

---

## 3. Database

### 3.1 Migrations (17 files, 82 literal `Schema::create` + 5 spatie tables ≈ 87 tables; docs say 88)

| File | What it does |
|---|---|
| `0001_01_01_000000_create_users_table` | **Edited Laravel default**: `users` = *customers* (`full_name`, mobile, professional/dental fields…) + `password_reset_tokens` |
| `0001_01_01_000001/2` cache, jobs | `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` |
| `2026_10_26_223249/50` | `personal_access_tokens`, `media`, spatie permission tables |
| `2026_10_27_000001` | `admins`, `user_addresses`, `otp_codes`, `audit_logs`, `notifications` |
| `…000002` | `countries`, `company_registration_types`, `vendor_types`, `companies`, `brands`, `brand_collections` |
| `…000003` | catalog: `categories`, `sub_categories`, `tags`, `products`, `product_variants`, `variant_price_tiers`, `sales_channels`, `product_channels`, `variant_channel_stock`, `inventory_movements`, `product_images`, `product_content_tabs`, `product_relations`, `collections`, `collection_product`, `search_terms`, `product_tag`, reviews/questions/feedback, wishlists |
| `…000004` | commerce: `carts`, `cart_items`, `orders`, `order_items`, `order_addresses`, `order_status_history`, `payments`, `webhook_events`, `shipments(+items,+events)`, `invoices`, returns/refunds, rewards, membership, coupons, referrals |
| `…000005` | `enquiries(+notes)`, `banners`, `homepage_sections`, `cms_pages`, `faqs`, `events(+registrations)`, `pincodes`, `shipping_rules`, `site_settings` |
| `…000006` | `sessions` |
| `2026_10_28_000001` | `categories.is_top`, `sub_categories.parent_id` (3rd level) |
| `…000002` | drops `categories.show_in_menu` |
| `…000003` | `products.return_days` |
| `…000004` | search indexes (`products(name)`, `brands(name)`, `companies(email)`, FULLTEXT `companies(name)`), renames old "Default" options |
| `2026_10_29_000001` | address column renames (`line2→area`, `pincode→postcode`) and additions, many `orders` / `order_items` columns, new settings, `coin_value` 1.5→1 |

Note: the schema was designed in one up-front pass (phase 0). The big create-files hold many unused tables, so removing a module means editing a historical migration (fine pre-production, painful once a client DB exists).

### 3.2 Tables by state

**In use (code + tests):** `users`, `admins`, `sessions`, `personal_access_tokens`, spatie `roles/permissions/*` (seeded `super_admin` only), `countries`, `company_registration_types`, `vendor_types`, `companies`, `brands`, `categories`, `sub_categories`, `tags`, `product_tag`, `products`, `product_variants`, `variant_price_tiers`, `sales_channels`, `product_channels`, `variant_channel_stock`, `inventory_movements`, `product_images` (remote-URL rows only), `product_content_tabs`, `product_relations`, `product_reviews`, `product_rating_stats`, `carts`, `cart_items`, `user_addresses`, `orders`, `order_items`, `order_addresses`, `order_status_history`, `payments`, `shipments`, `reward_wallets`, `reward_transactions`, `reward_redemptions`, `site_settings`, `audit_logs`, `pincodes` (read-only in `PlaceOrder`; no admin UI/seed), `media`, `cache`, `cache_locks`.

**Schema only (no model, no code):** `otp_codes`, `notifications`, `brand_collections` (model exists, no endpoint uses it), `collections`, `collection_product`, `search_terms`, `product_questions`, `product_feedback`, `wishlists`, `wishlist_items`, `wishlist_shares`, `webhook_events`, `shipment_items`, `shipment_events`, `invoices`, `return_reasons`, `return_requests`, `return_request_items`, `return_status_history`, `refunds`, `membership_plans`, `user_memberships`, `coupons`, `coupon_targets`, `coupon_usages`, `referrals`, `enquiries`, `enquiry_notes`, `banners`, `homepage_sections`, `cms_pages`, `faqs`, `events`, `event_registrations`, `shipping_rules`, `jobs/failed_jobs/job_batches`.

### 3.3 ERD (text, from the real foreign keys in the migrations)

Legend: `A 1—* B` = A has many B · `(R)` restrict · `(C)` cascade · `(N)` null on delete.

```
IDENTITY
 admins ──* audit_logs (actor polymorphic by string)         admins ──* reward_transactions.created_by (N)
 users  1—* user_addresses (C)   users 1—1 reward_wallets (C)   users 1—* reward_transactions (C)
 users  1—* carts (C)            users 1—* orders (R)           users 1—* product_reviews (C)
 users  1—* wishlists* (C)       users 1—* user_memberships*     users 1—* referrals*   (* = unused)
 users.referred_by_user_id → users (N)

COMPANY / BRAND  (seller onboarding)
 countries 1—* companies (R, origin_country_id)     countries 1—* brands (N)
 company_registration_types 1—* companies (R)        vendor_types 1—* companies (R)
 admins 1—* companies.reviewed_by
 companies 1—* brands (R)           brands 1—* brand_collections (C)
 companies 1—* products (R)         brands 1—* products (R)          (+ code rule: brand.company_id = product.company_id)
 companies / brands → order_items.company_id / brand_id (R)  + name snapshots

CATALOG
 categories 1—* sub_categories (R)                    sub_categories 1—* sub_categories (parent_id, R)  ← 3rd level
 categories 1—* products (R)                          sub_categories 1—* products (R, nullable)
 products 1—* product_variants (C)                    product_variants 1—* variant_price_tiers (C)
 products 1—* product_images (C)  (variant_id N)      products 1—* product_content_tabs (C)
 products *—* tags via product_tag (C)                products *—* products via product_relations (C)
 products 1—* product_channels (C) *—1 sales_channels (R)
 product_variants 1—* variant_channel_stock (C) *—1 sales_channels (R)
 product_variants 1—* inventory_movements (C) *—1 sales_channels (R)
 products 1—* product_reviews (C)  1—1 product_rating_stats (C)       product_reviews.order_item_id → order_items (N)
 products *—* collections via collection_product (C)   [unused]

CART / ORDER
 carts 1—* cart_items (C) *—1 product_variants (C)     carts *—1 sales_channels (R)    carts.coupon_id → coupons (N)
 orders 1—* order_items (C)    order_items *—1 products/variants/companies/brands (R)
 orders 1—* order_addresses (C, UQ order+type)   orders 1—* order_status_history (C)
 orders 1—* shipments (C)      orders 1—* payments (polymorphic payable_type/id, no FK)
 orders *—1 sales_channels (R), orders.coupon_id → coupons (N)
 orders 1—* invoices*, refunds*, return_requests*, coupon_usages*  (C)

REWARDS
 reward_transactions (append-only ledger; "earn/reverse/adjust" rows are batches with `remaining`, `expires_at`)
 reward_redemptions: redeem_transaction_id → reward_transactions, earn_transaction_id → reward_transactions (both C)
 reward_wallets (user_id PK) = cached balance
 orders.coins_redeemed / coins_discount / coins_to_earn,  order_items.coins_earned,  carts.coins_to_redeem,
 product_variants.reward_points

SETTINGS
 site_settings(group_key, key UQ, value JSON)       sales_channels(code UQ, requires_login, delivery_days)
```

Schema conventions: `BIGINT` ids, `utf8mb4`, `DECIMAL(12,2)` money (cart maths in integer cents), statuses as `VARCHAR` mapped to PHP backed enums, soft deletes on users/companies/brands/categories/sub_categories/products/reviews, order lines are snapshots.

### 3.4 Seeders and factories

`DatabaseSeeder` runs: `LookupSeeder`, `SalesChannelSeeder`, `SettingsSeeder`, `AdminSeeder`, `CustomerSeeder`, `InHouseCompanySeeder`, `CategorySeeder`, `TagSeeder`.
Optional (not in `DatabaseSeeder`): `ProductDemoSeeder` (from `data/demo-products.json`), `OrderDemoSeeder` (15 orders, 5 customers).
- `AdminSeeder`: `admin` / `admin` (env `SEED_ADMIN_*`), role `super_admin`; refuses weak password in production.
- `CustomerSeeder`: `test@gmail.com` / `test`, `professional_type=dentist`, 500 demo coins + a Lahore address (non-production only).
- **Factories: none.** `HasFactory` is on 4 models but `User::factory()` would fail. Tests build data through `tests/Concerns/BuildsCatalog|BuildsShop`.

---

## 4. Modules and features (what is where)

Legend: ✅ built · 🟡 partial · ⛔ schema only / not built. Controllers are under `app/Http/Controllers/{Admin,Store}`; Vue files under `admin-frontend/src`.

| Module | State | Models (`app/Domain/…`) | Actions / services | Controllers | Admin Vue |
|---|---|---|---|---|---|
| **Auth** (admin + customer) | 🟡 login, logout, me, customer register. No reset/verify/OTP/profile edit | `Auth/Admin`, `Auth/User` | – (logic in controllers); `EnsureActor` | `Admin\AuthController`, `Store\AuthController` | `LoginView`, `stores/auth.js`, `api/client.js` |
| **Products** | ✅ parent product + options (variants), bulk tiers, images (spatie + remote), content tabs, SEO, related, tags, status | `Catalog/{Product,ProductVariant,VariantPriceTier,ProductImage,ProductContentTab,Tag}` | `SaveProduct`, `SyncProductVariants`, `RefreshProductSummary`, `ManageProductImages`, `SaveTag`, `ProductListing`, `ProductSearch`, `ProductImages` | `Admin\ProductController`, `Admin\TagController`, `Store\ProductController`, `Store\TagController` | `ProductsView`, `ProductFormView`, `VariantEditor`, `ProductImages`, `RelatedProducts`, `TagsModal`, `QuickTagsModal` |
| **Categories** | ✅ 3 levels | `Catalog/{Category,SubCategory}` | `SaveCategory`, `DeleteCategory`, `CategoryTree` | `Admin\CategoryController`, `Admin\SubCategoryController`, `Store\CategoryController` | `CategoriesView`, `CategoryModal` |
| **Inventory / channels** | ✅ stock per variant per channel, movement log | `Catalog/{SalesChannel,VariantChannelStock,InventoryMovement}` | inside `PlaceOrder`, `ChangeOrderStatus`, `SaveProduct`; `ResolveChannel` middleware | – | stock pills + channel switches in product form; `SettingsView` (delivery days per channel) |
| **Cart** | ✅ guest (`X-Cart-Token`) + customer, merge on login, price warnings, coins | `Cart/{Cart,CartItem}` | `CartPricing` (the single price calculator), `ManageCartItems`, `CartResolver`, `MergeGuestCart`, `PricedCart/Line` | `Store\CartController` | – |
| **Addresses** | ✅ | `Address/UserAddress` | `SaveAddress` | `Store\AddressController` | – (snapshots shown on order detail) |
| **Checkout / Orders** | ✅ COD only, idempotency key, stock deduct under row locks, status flow, cancel, reorder | `Order/{Order,OrderItem,OrderAddress,OrderStatusHistory,Payment,Shipment}` | `PlaceOrder`, `ChangeOrderStatus`, `ReorderOrder`, `OrderSearch`, `CommerceException` | `Store\OrderController`, `Admin\OrderController` | `OrdersView`, `OrderDetailView` (incl. print invoice/packing slip) |
| **Payments** | 🟡 COD row only; no gateway layer | `Order/Payment` | in `PlaceOrder`/`ChangeOrderStatus` | – | payment status shown on order |
| **Shipping** | 🟡 flat delivery fee (+ free threshold), per-channel delivery days, pincode serviceability read from `pincodes`; shipment = courier text + AWB when marking *shipped* | `Order/Shipment` | `CommerceSettings`, `CartPricing` | `Admin\SettingController`, `Store\SettingController` | `SettingsView` |
| **Coupons / promotions** | ⛔ (tables + `carts.coupon_id`, `orders.coupon_id`, `discount_total=0`) | – | – | – | – |
| **Reward / loyalty (coins)** | ✅ earn on delivery, redeem at checkout, refund on cancel, FIFO batches, expiry cron, admin `grant()` exists but **no endpoint** | `Reward/{RewardTransaction,RewardWallet}` | `RewardLedger`, `ExpireRewardCoins` | cart/order controllers | settings card + `reward_points` field + order coin figures |
| **Reviews** | 🟡 list + post (one per user, published immediately, stats recomputed in-transaction). `is_verified_purchase` always false; no moderation UI | `Review/{ProductReview,ProductRatingStat}` | `SaveReview`, `RecomputeProductRating` | `Store\ReviewController` | – |
| **Wishlist** | ⛔ | – | – | – | – |
| **Company / B2B** | ✅ seller registration + approval (see §9) | `Company/{Company,Brand,BrandCollection,CompanyRegistrationType,VendorType,Country}` | `CreateCompany`, `ReviewCompany`, `SaveBrand`, `UniqueCompanyGst` | `Store\CompanyRegistrationController`, `Store\BrandController`, `Admin\CompanyController`, `Admin\BrandController` | `Companies*`, `CompanyForm/Detail`, `BrandModal`, `LogoBox`, `stores/lookups.js` |
| **Returns / refunds / invoices** | ⛔ (`returned` enum case reserved; `products.return_days` stored and displayed only) | – | – | – | print-only invoice in `OrderDetailView` |
| **Membership** | ⛔ | – | – | – | – |
| **CMS / banners / homepage / FAQ / events** | ⛔ (homepage "sections" are done by tag: `GET /products?tag=best-seller`) | – | – | – | – |
| **Notifications / email / SMS** | ⛔ | – | – | – | bell icon = pending-company/order badge polling every 60 s |
| **Settings** | ✅ commerce keys only (no store profile: name, logo, address, contact, tax id) | `Support/{SiteSetting,AuditLog}` | `CommerceSettings`, `Audit` | `Admin\SettingController` | `SettingsView` |
| **Dashboard / audit** | ✅ today's orders & revenue, pending, low stock, company counts, activity feed from `audit_logs` | `Support/AuditLog` | `Audit::log` | `Admin\DashboardController` | `DashboardView` |

---

## 5. Routes (94 registered; `/api/v1/{store,admin}`)

Global for API: `api` middleware group + `statefulApi()` (Sanctum cookie/CSRF only for stateful origins). Customer middleware stack: `auth:sanctum` + `actor:customer`. Admin: `auth:admin,sanctum` + `actor:admin` (**no permission/role middleware**).

### 5.1 Web (`routes/web.php`)
| Route | Notes |
|---|---|
| `GET /` | Laravel default `welcome` view |
| `GET /admin/{path?}` | serves `public/admin-assets/index.html` (the built admin SPA); 404 with a hint if not built |
| `GET /up` (health), `/docs/api` (Scramble) | framework/vendor |

### 5.2 Customer API – prefix `/api/v1/store`
| Module | Method + path | Middleware |
|---|---|---|
| Auth | `POST auth/login` | `throttle:login` |
| | `POST auth/register` | `throttle:forms` |
| | `POST auth/logout`, `GET auth/me` | `auth:sanctum`, `actor:customer` |
| Settings | `GET settings/delivery` (fee, free limit, delivery days, currency) | – |
| Brands | `GET brands`, `GET brands/{slug}` | – |
| Categories | `GET categories` (`?top=1`), `GET categories/{slug}` | – |
| Products | `GET products`, `GET products/{slug}` | `channel` |
| Tags | `GET tags` | – |
| Reviews | `GET products/{slug}/reviews` | – |
| | `POST products/{slug}/reviews` | `auth:sanctum`, `actor:customer`, `throttle:reviews` |
| Seller registration | `GET company-registration/options`, `GET lookups/{registration-types,vendor-types,countries}` | – |
| | `POST company-registrations` (multipart) | `throttle:forms` |
| Cart | `GET/DELETE cart`, `POST cart/items`, `PUT/DELETE cart/items/{variantId}` | `channel`, `throttle:cart` |
| | `PUT cart/coins` | + `auth:sanctum`, `actor:customer` |
| Addresses | `GET/POST addresses`, `GET/PUT/PATCH/DELETE addresses/{id}`, `POST addresses/{id}/default` | customer, `throttle:account` |
| Orders | `GET orders`, `GET orders/{orderNumber}` | customer, `throttle:account` |
| | `POST orders` | customer, `throttle:orders`, `channel` |
| | `POST orders/{n}/cancel`, `POST orders/{n}/reorder` | customer, `throttle:orders` |

`channel` = `ResolveChannel` (`?channel=` default `standard`; 422 unknown; 401 for guests if the channel has `requires_login`). Cart identity: `Authorization: Bearer` (customer) or `X-Cart-Token` (guest, server-issued, also echoed in the response header; CORS exposes it).

### 5.3 Admin API – prefix `/api/v1/admin` (all behind admin auth except login)
| Module | Routes |
|---|---|
| Auth | `POST auth/login` (`throttle:login`), `POST auth/logout`, `GET auth/me` |
| Dashboard | `GET dashboard` |
| Companies | `GET companies/options`, CRUD `companies[/{id}]` (update accepts PUT/PATCH/POST for multipart), `POST companies/{id}/{approve,reject,suspend}`, `GET companies/{id}/catalogue/{media}` (private download) |
| Brands | `GET/POST companies/{id}/brands`, `GET brands`, `GET/PUT/PATCH/POST/DELETE brands/{id}` |
| Categories | `GET categories/options`, CRUD `categories[/{id}]`, `POST categories/{id}/sub-categories`, `POST sub-categories/{id}/children`, `GET/PUT/PATCH/POST/DELETE sub-categories/{id}` |
| Products | `GET products/options`, CRUD `products[/{id}]`, `PATCH products/{id}/status`, `PUT products/{id}/tags`, `POST products/{id}/images`, `PUT products/{id}/images/order`, `DELETE products/{id}/images/{image}` |
| Tags | CRUD `tags` (system tags protected) |
| Orders | `GET orders/options`, `GET orders`, `GET orders/{id}`, `POST orders/{id}/status`, `PATCH orders/{id}/note` |
| Settings | `GET/PUT settings/commerce`, `GET/PUT settings/delivery` (the second pair overlaps the first) |

Missing admin APIs for things the schema/UI imply: lookups CRUD (registration types, vendor types, countries), customers, coupons, reviews moderation, reward grant/ledger, admin users/roles, channels CRUD, pincodes.

---

## 6. Admin panel (`admin-frontend/`)

**How it is built.** Standalone Vue 3 SPA (Vite 8, Pinia, Vue Router 5, plain CSS in `src/assets/styles.css`, Lucide icons, Poppins from Google Fonts). `base` = `/admin-assets/` for builds; `outDir` = `../public/admin-assets`; Laravel serves it at `/admin`. Dev: port **5190** (strict), proxies `/api`, `/sanctum`, `/storage` to `BACKEND_URL` (default `http://localhost:8000`). ~2,900 lines of views, `ProductFormView` is 585.

**How it talks to the backend.** `src/api/client.js` is a custom `fetch` wrapper (`credentials: 'include'`). Base = `VITE_API_URL || '/api/v1'` (same-origin). Non-GET calls first ensure the `XSRF-TOKEN` cookie via `/sanctum/csrf-cookie`; sends `X-XSRF-TOKEN`; retries once on 419; 401 → `onUnauthorized` → store cleared → redirect `login?redirect=`. Multipart updates use `POST` + `_method=PUT`. `ApiError` carries Laravel's 422 `errors`. Nothing stored in `localStorage` except sidebar state.

**Routes (history mode `/admin/`, all lazy):** `/login` · `/` dashboard · `/products`, `/products/new`, `/products/:id/edit` · `/orders`, `/orders/:id` · `/settings` · `/categories` · `/companies`, `/companies/new`, `/companies/:id`, `/companies/:id/edit` · 404. Guard = logged in or not (no roles).

**Stores:** `auth` (login/restore/logout), `catalog` (`products/options` + `categories/options`, currency), `lookups` (`companies/options`), `ui` (sidebar, toasts, pending badges).

**Views → endpoints:** Dashboard → `dashboard`; Companies → `companies`; CompanyDetail → `companies/{id}`, `approve|reject|suspend`, `brands/{id}`; CompanyForm → `companies` (multipart); Categories → `categories`, `sub-categories`; Products → `products`, `products/{id}/status`; ProductForm → `products`, `products/{id}/images*`; Orders → `orders`, `orders/options`; OrderDetail → `orders/{id}`, `/status`, `/note`; Settings → `settings/commerce`. The layout polls `companies?status=pending` and `orders` every 60 s for sidebar badges.

**Components (16):** `AppModal`, `BrandModal`, `CategoryModal`, `ConfirmDialog`, `EmptyState`, `ImagePicker`, `LogoBox`, `PaginationBar`, `ProductImages` (drag-reorder, 12 max), `QuickTagsModal`, `RelatedProducts`, `RowsPerPage`, `StatusBadge`, `TableSkeleton`, `TagsModal`, `ToastHost`, `VariantEditor`.

**Not in the admin panel:** coupons, review moderation, wishlist, CMS/banners, customers, admin users/roles, reward ledger/manual adjustments, channels management, dropdown lookups management, store profile/currency/tax/country settings, any i18n. Test/lint tooling: none.

---

## 7. Customer-facing frontend

- **Nothing in this repo.** `resources/views/welcome.blade.php` is Laravel's stock page, `resources/js/app.js` is empty, the root Vite/Tailwind config builds only that. The README calls this "the Dentalkart clone"; ARCHITECTURE §1 refers to a Vue 3 frontend at `../frontend` that was "100% mock data" (not in this repo; I could not inspect it).
- **Coupling to the backend is purely JSON over HTTP:** CORS origins from `FRONTEND_URLS` (credentials enabled), bearer token + `X-Cart-Token`, absolute image URLs built from `APP_URL` (so `APP_URL` must be the public API URL), `Idempotency-Key` header for checkout.
- **Things that make the API opinionated about one storefront** (matter for the "swap the storefront" goal):
  - Presentation text in responses: `badge_label` ("3 Offers", "N Options"), `show_starting_at`, `display_mode`, `tax_note` "Inclusive of all taxes", `arrives_by`, `low_stock`, "10-Days Returnable"-style `return_days`, cart warnings with ready-to-show English messages.
  - Homepage composition is done by convention (system tags `best-seller`, `hot-selling`, `new-arrival`, … + `?top=1` categories) instead of a CMS/sections API.
  - Auth endpoints return bare `{token, user}` while list/detail endpoints use `{data, meta}`.
  - Guest checkout is not possible (order placement requires a login); the cart is guest-capable.
  - No API versioning beyond the `/v1` prefix; OpenAPI spec is generated by Scramble (`/docs/api`, `api.json` export path configured).

---

## 8. Dentalkart / dental-specific items

### 8.1 Backend code (hard-coded)
| Where | What |
|---|---|
| `app/Domain/Order/Actions/PlaceOrder.php:242` | order number format `DK-<year>-<id>`; `OrderSearch.php:24` regex `^DK-` |
| `PlaceOrder.php:246` | default address country `'Pakistan'` |
| `app/Domain/Support/CommerceSettings.php:89,94`; `Store/SettingController.php:17`; `Admin/ProductController.php:49` | currency defaults `PKR` / `Rs` (5 places) |
| `app/Http/Requests/AddressPayloadRules.php:15,32,53` | **Indian GSTIN regex** (15-char) on address `gst_number`; message "27ABCDE1234F1Z5" |
| `app/Domain/Catalog/Models/SalesChannel.php` | constants `STANDARD`, `INSTADENT` (used in one error message + default channel); `SaveProductRequest.php:22` doc mentions `{standard, instadent}` |
| `app/Domain/Reward/Actions/RewardLedger.php:13` | "Dentacoin" in comment (name is otherwise generic "coins") |
| `app/Http/Resources/CartResource.php:82`, `OrderPresenter.php:38` | `'Inclusive of all taxes'` (assumes tax-inclusive pricing) |
| Comments/docblocks | "Sell on Dentalkart", "Dentalkart edit-address form" in 6 files |
| `app/Domain/Auth/Models/User.php`, `Store\AuthController`, `RegisterCustomerRequest` | `professional_type`, `speciality`, `council_reg_no`, `anniversary`, `business_name`, `verification_status` exposed on the user payload |
| `Product` model | `is_professional_only`, `hsn_code`, `gst_rate` (default 18), `badge_text`, `return_days` (default 10) – **`is_professional_only` and `verification_status` are stored but never enforced** |
| `PlaceOrder` / `OrderItem` | copies `gst_rate` → `tax_rate`, `hsn_code`; `orders.customer_gst_number` |
| `ProductListing`, `Product::scopeStorefront` | visibility depends on **brand active AND company approved** |

### 8.2 Schema defaults/columns
`user_addresses.country` default **'India'** (`…000001`), `order_addresses.country` default **'India'**, `payments.currency` default **'PKR'**, `products.gst_rate` default 18, `users.professional_type/speciality/council_reg_no/verification_status`, `invoices.seller_gstin/buyer_gstin`, `return_requests.shipping_reimbursement` ("₹250 per policy" in docs), `membership_plans` (Plan-I/II, education pass, coin multiplier), `user_addresses.type = clinic|home|other`, `referrals`, `events`. The history of the schema (India → Pakistan edited in place, columns renamed `pincode→postcode`) shows the "market" was changed by editing data/migrations, not by config.

### 8.3 Seed / demo data
- `CategorySeeder`: **13 dental categories, 65 sub-categories** (Endodontics, Orthodontics, Equipments, Instruments, …) + 11 sample sub-sub-categories.
- `data/demo-products.json` (+ `ProductDemoSeeder`): ~20 dental products (Prime-Dent, Dentaltech, Waldent…) with **32 image URLs hot-linked from `r2dkmedia.dentalkart.com`** (Dentalkart's CDN), 4 demo reviewers. SKUs `DEMO-<id>`.
- `InHouseCompanySeeder`: company **"Dentalkart (In-house)"**, `support@dentalkart.com`, `+91-728-9999-456`, New Delhi address, GST `IN-HOUSE-000`.
- `SalesChannelSeeder`: `standard` "Standard Delivery" (5 d) and **`instadent` "Instadent"** (login-only, 3 d).
- `LookupSeeder`: Indian-style company types ("Private Limited Company", "LLP", "One Person Company"), vendor types, 49 countries (India first).
- `SettingsSeeder`: delivery fee **300**, free above **8,500**, COD max 150,000, PKR/Rs.
- `CustomerSeeder` ("Smile Dental Clinic", Lahore, `dentist`), `OrderDemoSeeder` ("Dr. Ayesha Khan", Karachi, …).
- `TagSeeder`: 7 system tags (best-seller, hot-selling, new-arrival, best-price-zone, featured, most-searched, freebie) – retail-generic, but the storefront contract leans on these slugs.

### 8.4 Config / env / repo
`APP_NAME=Dentalkart` (`.env`, `.env.example`; also feeds `MAIL_FROM_NAME`, `VITE_APP_NAME`), `DB_DATABASE=dentalkart_db`, test DB `dentalkart_test`, `composer.json` name `laravel/laravel` (skeleton text), git remote `startxlaravel02/DentalKart_backend`, folder name `DentalKart_backend`.

### 8.5 Admin SPA
Brand mark = **Stethoscope** icon + "Dentalkart Admin" (`AdminLayout.vue:53`, `LoginView.vue`, `index.html` title, router title suffix, `package.json` name `dentalkart-admin`, `README.md`); login marketing copy about sellers/companies; CSS comment "design tokens follow the customer site (blue primary, orange accent, Poppins)"; printed invoice/packing slip header is literal `Dentalkart` with no address/tax id/logo (`OrderDetailView.vue:263`); `Settings` card title **"Coins (Dentacoins)"** (`SettingsView.vue:96`); placeholders "Maarc Pure Eugenol (15ml)", "MADE IN USA", "TCS, Leopards"; `channelLabel = code === 'instadent' ? 'Instadent' : 'Standard'` (`ProductsView.vue:145`); default origin country `IN` (`CompanyFormView.vue:78`); "GSTIN"/"GST / VAT"/"HS code"/"Sales tax %"/"ZIP / PIN code" labels; formats `en-IN` for dates and `en-PK` for numbers; hard-coded order/payment status lists and audit-action label map (`utils/format.js`).

### 8.6 Docs and Postman
Every doc says "Dentalkart clone"; `ARCHITECTURE.md` §6.1–6.12 are written around dentalkart.com behaviours (Instadent, Dentacoins, Waldent, Vasa Denticity, ₹95/₹2800 free shipping, membership plans in ₹); 3 of the 4 Postman collections are named `Dentalkart-*`.

### 8.7 Dental-only business concepts (exist only because of the original business)
Instadent (fast-delivery login-only channel), Dentacoins, "professional only" products, dentist/council-registration customer fields, expiry date on variants (medical goods), HS/GST fields, "Sell on Dentalkart" seller form, bulk-price "Buy 5+" rails, membership plans, brand collections (top/featured/new/suggested).

---

## 9. Company / B2B flow – every touch point

**Important framing:** this is **seller/vendor onboarding (marketplace supply side)**, *not* buyer-side B2B. There are no business customer accounts, customer groups, credit terms, quotes (the `enquiries` table is unused) or company-specific pricing. The only B2B-ish buyer features are quantity bulk tiers, `users.business_name`, `orders.customer_gst_number`.

- **Tables:** `countries`, `company_registration_types`, `vendor_types`, `companies` (SD; status `pending|approved|rejected|suspended`, `source` `public_form|admin`, `is_in_house`, `vat_gst_number` unique-among-non-rejected by code, `reviewed_by`, `rejection_reason`), `brands` (company FK RESTRICT, `is_active`), `brand_collections` (unused). Media: `logo` (public) and `catalogue` (private `local` disk).
- **Models/actions:** `Company`, `Brand`, `BrandCollection`, lookups; `CreateCompany` (transaction: company + ≥1 brand + logo + catalogue; admin-created = auto-approved, public = pending with inactive brands), `ReviewCompany` (approve/reject/suspend; **brands' `is_active` follow the company**), `SaveBrand`, `UniqueCompanyGst`.
- **Products:** `products.company_id` and `brand_id` are **NOT NULL FKs (RESTRICT)**; `SaveProductRequest` requires an *approved* company and a brand that belongs to it. Storefront visibility (`Product::scopeStorefront`, `Brand::scopeStorefront`) requires an **active brand of an approved company** → suspending a company hides all its products.
- **Orders:** `order_items.company_id`, `brand_id` NOT NULL (RESTRICT) plus `company_name`/`brand_name` snapshots; `PlaceOrder` loads `product.company` and `product.brand`.
- **Users:** none (a seller does not log in; companies are not linked to users).
- **Routes:** store `company-registration/options`, `lookups/*`, `POST company-registrations`, `brands`, `brands/{slug}`; admin `companies*`, `brands*` (§5).
- **Seeds:** `InHouseCompanySeeder` (every demo product is owned by "Dentalkart (In-house)"), `LookupSeeder`.
- **UI:** `CompaniesView`, `CompanyDetailView`, `CompanyFormView`, `BrandModal`, `LogoBox`, `stores/lookups.js`, company→brand cascading selects in `ProductFormView`, dashboard company cards and "recent pending", header search/bell for pending companies, sidebar "Companies".
- **Tests/docs:** `CompanyFlowTest`; ARCHITECTURE §6.1–6.2, DATABASE §3, PROGRESS "Company and brand module".
- **Why it matters:** it is the *deepest* structural coupling. A generic shop (single seller, brands optional) would still have to create a hidden "default company" or the FKs/validation/scopes must be relaxed.

---

## 10. Categories – how the hierarchy is implemented

- **Schema:** two tables. `categories` (top level) and `sub_categories` with `category_id` (always the **top** category, NOT NULL, RESTRICT) and nullable self-FK `parent_id` (the 3rd level). Both: `name`, `slug`, `description`, `rating` (unused), `color`, `sort_order`, `is_active`, `meta_*`, soft deletes, media `icon` + `image` (+ `banner` declared in docs). `categories.is_top` (max 12, enforced in `SaveCategory`). Indexes: `sub_categories UNIQUE(category_id, slug)`.
- **Depth:** hard-limited to **3 levels** (category → sub → sub-sub). The "one level only" rule is enforced in `SaveCategory::createChild` (API), not in the DB. Adding a 4th level means a schema/logic change, not config.
- **Products:** `products.category_id` (top, NOT NULL) + `sub_category_id` (nullable; may point to a sub **or** a sub-sub). One category per product (no many-to-many).
- **Models:** `Category::subCategories()` (only `parent_id IS NULL`), `SubCategory::{category,parent,children}`, `scopeWithProductCounts` (a parent counts its children's products).
- **Queries:** `ProductListing::filter` – `?category=slug` → `category_id`; `?sub_category=slug` → the sub and its children (`whereIn parent_id`). `Product::scopeStorefront` requires the category active and sub-category active and, for a child, its parent active.
- **Customer tree:** `CategoryTree::storefront()` – 3 nested eager-loaded levels, cached 10 min (`store.categories.tree`), flushed by every admin write; `?top=1` filters the same cached copy.
- **Admin:** `CategoriesView` shows an expandable tree with inline `is_active`/`is_top` switches; create/edit in `CategoryModal` (icon + image).
- **Delete rules:** category with sub-categories/products or sub with children/products → 422 "deactivate instead".
- **Inconsistency to know:** the DB unique key is `(category_id, slug)` and `ProductListing` comments say "slugs are unique per category only", but `GeneratesUniqueSlug` makes slugs **globally unique** (the code is stricter than the schema; harmless but the two never agree).
- **Seed:** `CategorySeeder` is entirely dental.

---

## 11. Points / reward ("coins") system – depth of wiring

Generic enough in logic (name "Dentacoins" appears only in comments/one admin label), but **woven through five layers**:

| Layer | Wiring |
|---|---|
| **Product** | `product_variants.reward_points` (coins earned per unit); admin field in `VariantEditor` ("Reward points (coins)") |
| **Cart** | `carts.coins_to_redeem`; `CartPricing` computes `coins_to_earn`, applies the redemption cap = min(spendable wallet, items subtotal, `max_redeem_coins_per_order`, `redeem_cap_order_percent` of subtotal, `redeem_cap_balance_percent` of wallet); `PUT /store/cart/coins`; cart response has `coins{requested,applied,limited_by}`, `coins_discount`, `coins_to_earn`, `total_savings` |
| **Checkout / Order** | `orders.coins_redeemed`, `coins_discount`, `coins_to_earn`; `order_items.coins_earned`; `PlaceOrder` locks batches (`RewardLedger::redeem`) in the same transaction as stock |
| **Status flow** | `ChangeOrderStatus`: **delivered → `credit()`** (earn batch, expires after `reward_expiry_days`), **cancelled → `refund()`** (a new "reverse" batch). **No clawback when a delivered order is returned** (returns not built) |
| **User** | `reward_wallets` (cached balance, lifetime counters), `reward_transactions` (append-only, batch rows with `remaining`/`expires_at`), `reward_redemptions` (FIFO links); `rewards:expire` nightly; `CustomerSeeder` grants 500 demo coins |
| **Settings** | `coin_value` (money per coin), `max_redeem_coins_per_order`, `redeem_cap_*`, plus `reward_expiry_days` **which is read but not editable in the admin Settings screen** |
| **Admin** | settings card, per-order coin figures; **no ledger/wallet/adjust screen** (`RewardLedger::grant` is only called by the seeder) |

To make "loyalty" optional per client, the cart/order maths needs a feature switch (e.g. `coin_value`/redeem off + hide `reward_points`) because `CartPricing`, `PlaceOrder`, `ChangeOrderStatus`, the resources and the admin form all reference it directly. Removing the column set would touch schema + ~29 files (grep for "coin|reward").

---

## 12. Database name – where it is configured

| Place | Value |
|---|---|
| `.env` (local, git-ignored) | `DB_DATABASE=dentalkart_db`, `DB_CONNECTION=mysql`, `DB_USERNAME=root` |
| `.env.example` | `DB_DATABASE=dentalkart_db` |
| `config/database.php` | no hard-coded name; every connection reads `env('DB_DATABASE', 'laravel')` (sqlite default `database/database.sqlite`) |
| `phpunit.xml` | test DB `dentalkart_test` (and `DB_CONNECTION=mysql`) |
| `README.md` | "create an empty MySQL database named: `dentalkart_db`", "tests need `dentalkart_test`" |
| `docs/ARCHITECTURE.md`, `DATABASE.md` ("88 tables in `dentalkart_db`"), `docs/PROGRESS.md` ("MySQL 8.4 `dentalkart_db`, Laragon") | documentation mentions |
| Docker / CI | **none** (no Dockerfile, compose, or `.github/`) |
| Live state | `dentalkart_db` **does not exist** on this machine right now |

---

## 13. Existing documentation – every doc

| File | What it says |
|---|---|
| `README.md` | "Dentalkart Backend: Laravel 13 API for the Dentalkart clone with a Vue 3 admin panel". Setup (composer, `.env`, create `dentalkart_db`, `migrate --seed`, optional demo seeders, `storage:link`, `serve`), admin panel dev (`:5190`, `admin/admin`), tests (`dentalkart_test`), demo customer `test@gmail.com/test` (500 coins), scheduler cron, `APP_TIMEZONE`, links to docs |
| `docs/ARCHITECTURE.md` (393 lines) | Goal + scope (customer API, admin API, seller registration); tech decisions table; system diagram; modular code structure; auth model; **core design decisions** (company vs brand, registration workflow, 3-level categories, product/variant/price rules, sales channels, tags, reviews, orders/payment/returns design, cart/checkout/orders (built), rewards & membership, forms/leads, content); performance table; API surface (built vs planned); frontend notes (mock data, hardcoded ₹); build phases 0–8; assumptions/open items |
| `docs/DATABASE.md` (799 lines) | Mermaid entity map + every table, column, index (designed for all modules), which are built, how product/cart/order/reward tables are used, settings seeds |
| `docs/PROGRESS.md` (167 lines, dated 2026-10-29) | Status table, per-pass changelog (foundation → company → categories → products → Dentalkart-style product page → options/drag-drop/search → admin panel → cart/checkout/orders/coins), load/chaos test results, test counts (229), Postman notes, "Next" list, production checklist |
| `docs/FRONTEND-CATEGORIES-API.md` | Frontend-developer guide to `/store/categories` (tree shape, `top=1`, 404 rules, how to map dentalkart.com's category page) |
| `docs/postman/Dentalkart-APIs.postman_collection.json` | 12 requests: dropdowns, company registration, brands, products, tags, reviews, login |
| `…/Dentalkart-Products-Frontend…` | 32 requests demonstrating product listing/detail/filters/Instadent |
| `…/Dentalkart-Cart-Orders…` | 27 customer requests: guest cart, login merge, addresses, checkout (idempotency), orders, cancel, reorder |
| `…/Category-Display…` | 4 requests for the category tree |
| `docs/postman/sample-logo.png` | logo file used by the company-registration request |
| `admin-frontend/README.md` | dev/build instructions, `admin/admin`, stateful-domain setup, production notes. **Stale**: lists only dashboard/companies pages |
| (generated) `/docs/api` | Scramble OpenAPI UI (not committed) |

**Docs vs reality (drift).** ARCHITECTURE says Redis cache/queue, Meilisearch/Scout, Policies, Events/Jobs, payment/SMS/courier adapters with fake drivers, `Wishlist/Payment/Shipping/…` domains, `seller` flows with email on reject – **none of these exist in code**. Table count 88 vs 82+5 by my count. Test count 229 in PROGRESS vs ~231 test methods I count (not run). `docs/*.md` mix INR (₹, GST, "Indian") and PKR (Rs). PROGRESS says code is pushed to `startxlaravel02/DentalKart_backend`.

---

# A. Risks and tech debt

**Security / correctness**
1. **No authorization.** Roles are seeded but never checked (`grep hasRole/can/authorize` finds only empty `FormRequest::authorize()`); no Policies/Gates. Any active admin can do everything (including company approval and order status). Only `super_admin` is seeded.
2. **Customer & admin account lifecycle is incomplete**: no password reset, no email/phone verification (columns and `otp_codes` exist), no profile update, Sanctum tokens never expire, no admin management/password change, seed credentials are `admin/admin` and `test/test` in README/`.env.example` (production refusal exists only in the seeders).
3. **`is_professional_only` and `users.verification_status` are stored but not enforced** anywhere (a "professional-only" product is buyable by anyone).
4. **Hot-linked images from `r2dkmedia.dentalkart.com`** in the demo data (32 URLs): copyright/ToS risk and will break/leak requests to a third party; must not ship to other clients.
5. **Single-tenant by design.** No `tenant_id`/store scoping anywhere; "multi-client" must mean one deployment (code + DB + `.env`) per client. That is workable but then per-client branding/config must live in config/settings, which today it mostly does not (see §8).
6. **MySQL-only** (FULLTEXT, `FOR UPDATE`): tests, CI and any client on a different DB engine need MySQL 8.
7. `popularity_score` is **never written** (only read for default sort/related) → "popularity" sort is effectively `rating_count` then id.
8. `reserved_qty` and `payments.gateway*`, `webhook_events` are placeholders; online payment will need design work (stock is *deducted* at order time, designed for COD).
9. `SiteSetting::read` uses `Cache::rememberForever` per key with `DB` cache; fine on one server, but a settings write on one node does not purge others if the cache store is per-node (e.g. `file`/`array`). Only relevant if you move off the shared DB cache.
10. Dashboard "revenue today" sums `grand_total` over today's orders; I did not verify whether cancelled orders are excluded.

**Maintainability**
11. Business English is **embedded in API responses** (badges, warnings, error messages, tax note): no i18n, hard for another storefront/language.
12. Currency/country/tax are **constants in ~10 places** (PKR/Rs defaults, 'Pakistan' fallback, India default in migrations, GSTIN regex, `en-IN`/`en-PK`). Tax model = single tax-inclusive rate per product (GST/HSN style): no VAT/zones/exclusive pricing.
13. **Schema-first sprawl**: ~45 unused tables (coupons, returns, memberships, CMS…) carried in migrations; docs describe them as if planned but they have no code, so they constrain/confuse the "neutral base".
14. Large FormRequests (`SaveProductRequest` ~280 lines), `PlaceOrder`, `CartPricing`, `ProductFormView.vue` (585 lines), duplicated list-view logic in the SPA (`CompaniesView/OrdersView/ProductsView`).
15. Admin SPA: no tests, no lint, no i18n, module-level currency global, parsing backend URLs for downloads, modal without focus trap, stale README.
16. Repository hygiene: edited Laravel default `users` migration; `database/factories` missing but `HasFactory` used; `phpunit.xml` references missing `tests/Unit`; `composer.json` still says "The skeleton application for the Laravel framework"; root `package.json`/`vite.config.js`/`welcome.blade.php` are unused scaffolding; `settings/commerce` and `settings/delivery` endpoints overlap; two sub-category slug rules disagree (§10).
17. `.env` in the working tree contains local DB/Redis/mail password entries (git-ignored, not committed – fine, but do not copy it into client repos).

**What is genuinely good (keep)**: clean Domain/Action layering, transactional + row-locked checkout (load-tested per PROGRESS), single price calculator, idempotency key, snapshots on orders, audit log, rate limiting, lazy-loading guard, fixed-query-count tests, a data-driven channel model (the backend only hard-requires a channel with code `standard`).

---

# B. Things I need you to confirm

1. **Tenancy model:** one deployment per client (separate DB + `.env`) – my assumption – or one shared install serving many clients? This decides whether we add `tenant_id` or only config/branding layers.
2. **Company/Brand:** for a generic shop, should companies/sellers become **optional** (default hidden in-house company), **a feature flag**, or be **removed**? Do any target clients need multi-vendor/seller onboarding? Is "B2B" for you *seller onboarding* (as built) or *business buyers* (wholesale accounts, GST/NTN invoices, credit, quotes)?
3. **Channels (standard/instadent):** keep the generic "sales channel" concept (rename seed to just "Default"), or drop to a single channel? Keep login-gated channels?
4. **Rewards/coins:** keep as an optional module (off by default) or remove? Should delivered-then-returned orders reverse earned coins (needs returns)?
5. **Dental-specific fields:** drop `professional_type`, `speciality`, `council_reg_no`, `anniversary`, `is_professional_only`, `expiry_date` on options, etc., or generalize them (e.g. a customer "type" and a product "restricted" flag, expiry as optional)?
6. **Money & tax:** default currency/country/tax model for new clients? Per-client config of PKR/INR/USD, tax-inclusive vs exclusive, GST/VAT/NTN labels and validation regexes?
7. **Storefront contract:** do you want the API to stay as is (including display strings), or to become a stricter headless contract (no presentation text, i18n keys, stable codes only)? Also a **guest checkout** requirement?
8. **Categories:** is 3 levels fixed, or should it be unlimited depth (adjacency list with materialized path)? Multiple categories per product?
9. **Admin authorization:** do you want real roles/permissions (spatie is already installed) in the base?
10. **Payments/shipping/notifications:** which providers will the first clients need (PK gateways such as JazzCash/EasyPaisa/PayFast, Stripe, couriers, SMS/WhatsApp)? That decides the adapter interfaces.
11. **Repo strategy:** should the neutral base be a **new repo/template** (keeping this one as the Dentalkart reference), and should git history be rewritten (the demo JSON hot-links, seed emails, org name)?
12. **Demo/Postman/docs:** keep a neutral demo dataset (non-dental, own images) and re-baseline the docs (they are long and describe unbuilt modules as designed)?
13. **Admin SPA:** stay on Vue 3 + plain CSS, or move to a component library/TypeScript/i18n now (cheaper before more screens exist)?
14. **Is the `../frontend` repo available** for me to read? I could not verify its API usage, and it determines which response fields are actually relied on.

---

# C. Proposed order of work (neutral, multi-client-ready base)

Principle: **stabilize and decide first, remove coupling next, add missing platform pieces last**, with the test suite (≈230 tests, MySQL) as the safety net after every step. *(Nothing below has been started.)*

0. **Baseline & safety (½ day).** Create the MySQL DBs, run `migrate --seed` + `php artisan test` to confirm the starting state is green; tag the commit as the "dentalkart reference". Answer §B questions 1–3, 5–6 (they gate everything).
1. **Branding/config layer.** Introduce a small config/settings surface (`config/shop.php` + `site_settings`): store name, logo, contact, currency (code/symbol/locale), default country, order-number prefix, tax label/regex, default channel. Replace the ~10 hard-coded spots in §8.1 and the admin SPA labels/`format.js`; make `APP_NAME`, DB names, package names, Postman names neutral (`shop_db`, `shop_test`…).
2. **Strip Dentalkart-only data.** Replace seeders with a neutral demo (own categories, own images, no CDN hot-links, neutral in-house/default company, neutral channel names, generic tags), delete or isolate `demo-products.json`; neutral Postman collections.
3. **Decouple Company/Brand.** Per §B2: introduce a default seller or make `company_id`/`brand_id` nullable + a feature flag, adjust `scopeStorefront`, `PlaceOrder`/`order_items` snapshots, product form, dashboard and admin nav accordingly; keep company onboarding as an optional module.
4. **Generalize channels and coins.** Rename "Instadent" to a configurable channel (keep the model); add a `features.rewards` switch that hides `reward_points`, coin settings, cart/order coin blocks; add the missing ledger admin screen if kept.
5. **Generalize customer profile & fixes.** Remove or neutralize dental fields; fix `is_professional_only`/`verification_status` (enforce or delete); add `popularity_score` job or remove the column; settle the sub-category slug rule; fix the minor items in Risks 7, 10, 16.
6. **Authorization & account lifecycle.** Real roles/permissions (policies or permission middleware per admin route group), admin user management and password change, customer password reset + email verification, token expiry, tighter seed/default credentials.
7. **Headless API contract.** Decide the presentation-text policy (§B7), freeze the response shapes, add a documented error-code catalog, version/OpenAPI export (Scramble `api.json`), and a thin CMS/sections API (banners, homepage sections, pages) if storefronts need it (tables already exist).
8. **Platform modules in value order.** Payment-gateway interface + first provider (with `reserved_qty` hold), notifications/email/SMS via queue (actually start using the queue), coupons, returns/refunds/invoices PDF, wishlist, review moderation, customers screen, admin lookups CRUD.
9. **Per-client deployment kit.** `.env` templates, Docker/compose, CI (MySQL service, tests), a `make:client`/checklist script (new DB, seed neutral data, create admin, set branding), documented upgrade path for client forks.
10. **Docs rewrite.** Replace the Dentalkart-flavoured docs with: README (neutral), one accurate ARCHITECTURE (built vs planned clearly separated), generated DB reference, API reference, client-onboarding guide.

Suggested rough effort split: steps 0–2 are small and low risk; step 3 and 6 are the biggest structural changes; step 8 is where most new feature time goes.
