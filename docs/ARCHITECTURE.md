# Dentalkart Clone – Backend Architecture

Status: **design, awaiting approval** (no Laravel code written yet).
Companion document: [DATABASE.md](DATABASE.md) (every table, column and index).

---

## 1. Goal and scope

Build the backend for the existing Vue 3 frontend in `../frontend` (a clone of dentalkart.com):

* **Customer API** – catalog, search, product detail, reviews, wishlist, cart, checkout, orders,
  order tracking, returns, rewards (Dentacoins), membership, coupons, forms, events, CMS content.
* **Admin API** – manage companies, brands, categories, products, stock, orders, returns, users,
  coupons, membership, rewards, content, enquiries, settings.
* **Seller (company) registration** – public form on the customer site (footer → "Sell on Dentalkart")
  *and* admin-created companies, both going through one approval workflow.

The database is designed **completely up front** (all modules) so later work is only code.
Build order is in section 10. Phase 1 = company, brand, category, product, review, wishlist, auth.

---

## 2. Technology decisions

| Concern | Decision | Why |
|---|---|---|
| Framework | **Laravel (latest stable), PHP 8.3+** | Requested. Eloquent, queues, policies, resources, testing built in. |
| Database | **MySQL 8 (InnoDB, utf8mb4)** | Requested. |
| Cache / queue / rate limit | **Redis** | Fast category tree, home sections, queues, throttling. |
| Auth | **Laravel Sanctum** (bearer tokens), two guards: `admin`, `customer` | Stateless → easy to scale horizontally. |
| Roles | **spatie/laravel-permission** | Standard, tables well known. |
| Media | **spatie/laravel-medialibrary** | Icons, banners, product images, catalogue files, thumbnails; local disk now, S3/R2 later by config. |
| API docs | **Scramble** (OpenAPI generated from code) | Frontend developer gets a live spec. |
| Tests | **PHPUnit** feature tests per endpoint (`php artisan test`, separate `dentalkart_test` database) | Ships with Laravel; no extra dependency. |
| Search | MySQL FULLTEXT first → **Meilisearch via Laravel Scout** when catalogue is large | Same API either way. |
| Style | **API-first modular monolith** | One deployable unit, clear module boundaries. No microservices at this size. |

---

## 3. System view

```
 Customer site (Vue)  ──┐
 Admin panel            ├──►  Laravel API (stateless, versioned /api/v1)
 Seller form (customer) ──┘        ├─ /api/v1/store/*   public + customer token
                                   └─ /api/v1/admin/*   admin token + permissions
                                            │
      ┌──────────────┬──────────────┬───────┴───────┬────────────────┬─────────────────┐
    MySQL 8        Redis          Queue worker     File storage      Search            Payment / SMS /
  (source of     (cache, queue,   (rating recalc,  (local → S3/R2    (FULLTEXT →       Courier / Mail
   truth)         throttle)        emails, images,   + CDN)           Meilisearch)      (adapters)
                                   webhooks)
```

All third parties (payment gateway, SMS/OTP, courier, mail) sit behind **interfaces** with a
`fake` driver for development, so the real ones are a config switch later.

---

## 4. Code structure (modular)

```
app/
  Domain/                       one folder per module; business logic lives here
    Auth/ Company/ Catalog/ Review/ Wishlist/ Cart/ Order/ Payment/ Shipping/
    Returns/ Reward/ Membership/ Promotion/ Content/ Enquiry/ Notification/ Support/
      Models/  Actions/  Enums/  Policies/  Events/  Jobs/
  Http/
    Controllers/Store/…         thin controllers
    Controllers/Admin/…
    Requests/                   FormRequest validation
    Resources/                  API Resources (response shape)
  Support/                      money, slug, settings, channel context helpers
database/migrations, seeders, factories
routes/api_store.php, api_admin.php
tests/Feature/…
docs/
```

Rules: controller → FormRequest → **Action class** → Eloquent → Resource.
No business logic in controllers or models' magic. Policies for authorization.
Enums (PHP backed enums) for every status/type column.

---

## 5. Authentication and access

* **admins** table (separate from customers) – login by `username` (seed: `admin`), roles via spatie.
  Seed role `super_admin` first; `catalog_manager`, `order_manager`, `support` can be added without schema change.
* **users** (customers) – login by email or mobile; seed: `test@gmail.com / test`.
  Only **login** is built now. Register, OTP and forgot-password tables exist in the schema, endpoints later.
* Seed passwords come from `.env` and the seeders refuse to run with weak defaults when `APP_ENV=production`.
* Throttling on login, OTP and every public form.
* **Instadent access:** the `sales_channels` row for `instadent` has `requires_login = true`.
  A `channel` middleware enforces it on the API, so a guest cannot read Instadent catalogue even by calling
  the API directly. Wishlist and review endpoints always require a customer token.

---

## 6. Core design decisions

### 6.1 Company vs Brand
* `companies` – the legal entity that registers (seller form). Internal, admin-facing.
* `brands` – customer-facing label, **belongs to one company**; a company has many brands
  (e.g. one company → Waldent, Waldent Instruments, Waldent AlChem).
* Brand pages (`/brands/:brand`) list products by `brand_id`.
* A product has **required `company_id` and `brand_id`**; validation enforces `brand.company_id = product.company_id`.
  Only **approved** companies appear in the product form.
* Imported legacy/frontend products get a default company **"Dentalkart (In-house)"** and brands created from their brand names.

### 6.2 Company registration workflow (customer form + admin)
Customer form (screens supplied by you), 2 steps + catalogue:
1. Company Name, Registered As (dropdown), Company Email, Origin Country (dropdown), Vendor Type (dropdown),
   Company Contact Number, VAT/GST Number, Brands (name + country, repeatable via **Add**).
2. Contact Name, Mobile, Contact Email, WhatsApp (optional), Company Address, Landmark (optional), City, ZIP/PIN,
   "support you can provide to promote your products" (**200–500 characters**), **catalogue upload**
   (PNG/JPG/JPEG/WEBP/PDF; "Register Now" stays disabled until a file is uploaded).

Statuses: `pending → approved | rejected`, plus `suspended`.
* Public submission → `source = public_form`, `status = pending`, brands created inactive.
* Admin "Create new company" → `source = admin`, `status = approved` immediately (same fields).
* Approve → company + its brands become active and selectable. Reject → reason stored, email queued.
* Dropdown options (Registered As, Vendor Type, Country) are **admin-managed lookup tables**, not hardcoded.
* GST number is unique among non-rejected companies (duplicate-registration guard).
* Every status change is written to `audit_logs`.

### 6.3 Categories
Two tables, as decided by the owner: `categories` and `sub_categories` (`sub_categories.category_id` FK).
Both have an icon and an image (media attachments). Products store **both** `category_id` and `sub_category_id` (nullable),
so either filter is a single indexed lookup; validation guarantees the sub-category belongs to the chosen category.
A third level is not supported by this schema (not needed on the live site).

### 6.4 Product, variant, price
* Every product has **at least one variant** (a hidden *default* variant is auto-created for products without
  options). Price, MRP, coins, SKU-level stock, cart lines, order lines, wishlist lines all point to a **variant**.
  This is what keeps cart/orders simple and scalable.
* `discount_percent = round((mrp − selling_price) / mrp × 100, 2)`, calculated on save (backend), stored for sort/filter.
  Validation: `selling_price ≤ mrp`. The UI shows e.g. "50% off" from this value.
* Product keeps denormalized `min_price`, `max_discount_percent`, `rating_avg`, `rating_count`,
  `is_in_stock` – refreshed by observers/jobs – so listing pages never aggregate.
* Detail tabs (Features, Description, Packaging, Key Specifications, Direction To Use, Additional Info,
  Warranty) live in `product_content_tabs` – flexible, no schema change per tab.
* Quantity tier pricing ("Buy 2+ for ₹X each") → `variant_price_tiers`.
* Tax: `hsn_code` + `gst_rate` per product → needed for invoices.

### 6.5 Sales channels (Standard vs Instadent)
* `sales_channels`: `standard`, `instadent` (extensible).
* `product_channels(product_id, channel_id, is_active)` – admin decides: Standard only / Instadent only / both,
  and can toggle Instadent active/inactive.
* `variant_channel_stock(variant_id, channel_id, stock_qty, reserved_qty, price_override?)` – **separate quantity per channel**.
  `price_override` is nullable and unused for now (same price on both channels; ready if that changes).
* A product is purchasable on a channel only if: product active + channel row active + stock available.
* Cart and order belong to exactly one channel (no mixing).

### 6.6 Tags and collections
* **Tags** (Best Seller, New Arrival, Hot Selling, Freebie, Featured, Most Searched, custom…) = labels on a product (`product_tag`).
* **Collections** = curated/rule lists used for homepage sliders ("Hot Selling", "Dentaltech"), with their own title/banner.
* `homepage_sections` makes the homepage data-driven per channel (hero, brands slider, category grid, product sliders, promo banners…).

### 6.7 Reviews and rating
* `product_reviews` (rating 1–5, title, body, status, verified-purchase flag later). One review per user per product.
* `products.rating_avg / rating_count` + `product_rating_stats` (1–5 distribution) recomputed by a **queued job**
  on create / edit / delete / status change. Pages read the cached numbers only.
* Also designed: `product_questions` (customer Q&A, admin answers) and `product_feedback`
  (the private "Valuable Feedback" form: quality / price / overall rating + purchased yes/no).

### 6.8 Orders, payment, tracking, returns (designed now, coded after phase 1)
* `orders` + `order_items` (**snapshots** of name/sku/price/tax so history never changes) + `order_addresses` (snapshot)
  + `order_status_history` (this is the timeline shown in *Track Order*).
* `payments` is polymorphic (`payable` = order **or** membership purchase) – COD, UPI, card, net banking, wallet, EMI;
  every gateway call/webhook stored (`webhook_events`, unique event id → idempotent).
* `shipments` + `shipment_items` + `shipment_events` (courier AWB + tracking events). Track Order API =
  `order_number + mobile/email` for guests, or by token for logged-in users.
* `return_requests` (+ items, media, status history): replace first, refund if out of stock; refund to original / bank / store coupon.
* `refunds`, `invoices` (GST invoice number + PDF).
* Stock safety: reserve inside a DB transaction with row locks; a scheduled job releases reservations of unpaid orders.

### 6.9 Rewards (Dentacoins) and membership
* `reward_transactions` is an **append-only ledger** (earn / redeem / expire / adjust / reverse / referral).
  Each *earn* row is a batch with `expires_at = credited + 365 days`; redemption consumes the earliest-expiring
  batches first (`reward_redemptions`). `reward_wallets` caches balances.
* Rules live in data, not code: coin value (₹0.5), redemption caps (10% / 20% of order, 20% / 40% of balance,
  max 5,000 coins) in `site_settings` and `membership_plans`.
* `membership_plans` (Plan-I 6 months ₹499, Plan-II 12 months ₹799; multiplier 2×, free shipping from ₹499,
  20% redemption cap, priority processing, education pass) and `user_memberships` (starts/ends, status, payment).

### 6.10 Forms and leads
Dedicated tables where a workflow exists (company registration, returns, reviews, questions).
Everything else uses one **`enquiries`** table with `type` + JSON `payload` + status + assignee + notes:
`dealer_bulk`, `waldent_dealership`, `clinic_setup`, `bulk_quote`, `contact_us`, `other`.
A new form = a new `type` and validation rule, no migration.

### 6.11 Content (admin-managed)
`banners`, `homepage_sections`, `collections`, `cms_pages` (about, privacy, terms, return policy…), `faqs`,
`events` (+ `event_registrations`), `site_settings`. Blogs/Magazine/Shorts are external or optional → listed as "later".

---

## 7. Performance and scalability

| Risk | Design answer |
|---|---|
| Slow listing / filters | Targeted composite indexes (see DATABASE.md §Indexes), denormalized price/rating/stock on `products`, cursor pagination for deep pages |
| N+1 queries | Eager loading enforced (`Model::preventLazyLoading()` in non-production), lean API Resources |
| Repeated reads | Redis cache: category tree, tags, brands, homepage sections, settings. Tag-based invalidation on admin writes |
| Search over 20k+ products | FULLTEXT now, Scout + Meilisearch later (same endpoint) |
| Heavy side effects | Queue: rating recompute, images/thumbnails, emails/SMS, webhook processing, reservation release |
| Images | Thumbnails (256/512) generated by medialibrary, served from CDN/object storage |
| Overselling | Transactional stock reservation + `inventory_movements` audit trail |
| Horizontal scale | Stateless API (token auth), sessions/cache/queue in Redis, files in object storage, MySQL read replica later |
| Abuse | Rate limiting on login, OTP, public forms, review/question posting |
| Data safety | Soft deletes on catalogue/company/user, `audit_logs` for admin actions, encrypted columns for bank details |
| Money correctness | `DECIMAL(12,2)`, never float; order totals computed server-side only |

Later options without redesign: Laravel Octane, read replicas, Meilisearch, S3/R2 + CDN, splitting
Order/Payment into a service (boundaries already modular).

---

## 8. API surface (versioned `/api/v1`)

**Store (customer)**
auth (login, logout, me) · categories tree · products (list, filter, sort, search, suggestions, detail, related) ·
brands (list, brand products) · collections / homepage sections per channel · reviews (list, create, edit) ·
questions · feedback · wishlists (CRUD, items, share) · cart · checkout (address, shipping fee, coupon, coins, place order) ·
orders (list, detail, cancel, invoice) · **track-order** · returns · rewards (balance, history) · membership (plans, buy, mine) ·
coupons · addresses · notifications · pincode check · **company registration** (+ catalogue upload) · enquiries (dealer, bulk quote, clinic setup, contact) ·
events · CMS pages · FAQs · site settings (public).

**Admin**
auth · dashboard · lookups (registered-as, vendor type, countries, return reasons) · **companies** (list, create, edit, approve, reject, suspend) ·
**brands** · **categories / sub-categories** · **products** (+ variants, price tiers, stock per channel, tags, images, content tabs, related) ·
tags · collections · banners · homepage sections · review moderation · Q&A answering · orders (status, shipment, invoice) ·
returns & refunds · users · coupons · membership plans · reward adjustments · enquiries (+ notes) · events · CMS · settings ·
admins & roles · audit log.

---

## 9. Frontend notes found while reading the code

* The frontend is 100% mock data today. It will need an API layer (e.g. axios + Pinia stores) pointing to `/api/v1/store`.
* `CartView` reads `item.sku`, which products in `data/products.js` do not have → will come from the variant.
* Free-shipping is hardcoded ₹499 / ₹49 in `CartView`. The live site charges a flat ₹95 everywhere and is free above ₹2800.
  These values (and delivery days: standard 5, Instadent 3) now come from `GET /api/v1/store/settings/delivery`
  and are editable by admin, so the frontend should read them instead of hardcoding.
* `siteInfo.js` has US address/phone; the live site is Indian (+91, Vasa Denticity Limited) → will come from `site_settings`.

---

## 10. Build phases

0. **Foundation** – Laravel install, MySQL/Redis config, **all migrations for the whole schema**, enums, seeders
   (admin, test customer, lookups, channels, default company), Sanctum guards, roles, Scramble docs, PHPUnit setup, CI-ready `.env.example`.
1. **Company module** – public registration (+ catalogue upload), admin list / create / approve / reject, brands.
2. **Categories & sub-categories** – admin CRUD with icon + image.
3. **Products** – admin CRUD, variants, tiers, tags, channels + stock, images, content tabs, auto discount.
4. **Customer catalog** – listing, filters, search, detail, brand pages, Instadent (login-gated), homepage sections.
5. **Reviews, ratings, wishlist, Q&A, feedback.**
6. **Seed importer** – load the frontend's current products/brands/categories into the DB.
7. Later modules on the same schema: cart → checkout → payments → orders/tracking → returns/refunds → rewards → membership → coupons → enquiries → content/events.

Each phase ends with: migrations (already done in phase 0), actions, endpoints, PHPUnit tests, OpenAPI docs.

---

## 11. Assumptions and open items (to confirm)

1. Dropdown options for **Company Registered As**, **Vendor Type**, **Country** were not visible → seeded with typical values, admin-editable.
2. Dealer-enquiry and Waldent-dealership form fields beyond Personal Details were not visible → stored in `enquiries.payload` JSON.
3. Courier, payment gateway, SMS and mail providers are not chosen → adapters + fake drivers for now.
4. Instadent specifics (delivery ETA, serviceable pincodes) → modelled on `pincodes`; rules to be confirmed.
5. Guest checkout: schema supports guest carts (`guest_token`); orders require a logged-in customer unless you decide otherwise.
6. Review moderation: default is "published immediately", with an admin switch to require approval.
7. ~~Free-shipping threshold~~ – resolved: flat ₹95 delivery, free above ₹2800, both admin-editable; delivery days standard 5 / Instadent 3.
