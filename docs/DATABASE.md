# Database (MySQL 8, database `jhkstore_db`)

Conventions: InnoDB, `utf8mb4`, `BIGINT UNSIGNED` ids, `created_at`/`updated_at` everywhere, soft deletes (`deleted_at`) on users, categories,
sub-categories, products and reviews. Money `DECIMAL(12,2)` (cart maths use whole minor units). Statuses are `VARCHAR` mapped to PHP backed enums.
Files use spatie medialibrary (`media` table). "Snapshot" = copied at the time of the event so history never changes.

The database name comes from `DB_DATABASE` in `.env` (`jhkstore_db`; tests use `jhkstore_test` from `phpunit.xml`). It is not hard-coded anywhere else.
The whole schema is ONE baseline migration, `database/migrations/0001_01_01_000000_create_baseline_schema.php` (squashed from the earlier history; tables first, foreign keys afterwards). A fresh install runs it with `php artisan migrate --seed`. Every later change is a new migration file after it. A database that ran the old, un-squashed migrations cannot be upgraded in place: rebuild it with `php artisan migrate:fresh --seed`.

## Relationships (text ERD)

```
admins ──* audit_logs                                  users 1─* user_addresses            users 1─1 reward_wallets
users  1─* carts 1─* cart_items *─1 product_variants   users 1─* reward_transactions       users 1─* orders
users  1─* product_reviews

categories 1─* sub_categories
categories 1─* products                                sub_categories 1─* products (sub_category_id nullable)
products 1─* product_variants 1─* variant_price_tiers  products 1─* product_images / product_content_tabs
products *─* tags (product_tag)                        products *─* products (product_relations: related)
products 1─* product_reviews, 1─1 product_rating_stats product_variants 1─* inventory_movements

orders 1─* order_items (snapshots)  orders 1─* order_addresses (shipping, billing)  orders 1─* order_status_history
orders 1─* payments (polymorphic payable)  orders 1─* shipments
reward_transactions (append-only ledger) ─* reward_redemptions (FIFO links between redeem and earn batches)

settings (key/value)        pincodes (optional serviceability / COD per postcode)
```

## Tables in use

| Table | Purpose / key columns |
|---|---|
| `users` | customers: name, email, mobile, password, gender, date_of_birth, business_name, is_active |
| `admins` | admin users: username, name, email, password; roles via spatie tables |
| `user_addresses` | address book: name, phones, line1, area, landmark, city, state, postcode, country, tax_number, type `home|work|other`, lat/long, is_default |
| `categories`, `sub_categories` | two-level tree: name, slug, description, color, sort_order, is_active, meta_*; `categories.is_top` (max 12, home screen); media icon + image |
| `products` | category_id, sub_category_id?, name, slug, sku, descriptions, badge_text, **tax_rate?**, country_of_origin, warranty_text, min/max_order_qty, is_returnable/return_days, is_cod_available, status, published_at, meta_*; denormalised min_price, max_discount_percent, rating_avg/count, is_in_stock |
| `product_variants` | options: name, sku, mrp, selling_price, discount_percent, reward_points, expiry_date, barcode, weight, **stock_qty, reserved_qty, low_stock_threshold**, is_active, is_default |
| `variant_price_tiers` | bulk prices (min_qty, price) |
| `product_images`, `product_content_tabs`, `product_relations`, `product_tag`, `tags` | remote-image rows, description tabs, related products, tag pivot, tags (`is_system` protects the home-page tags) |
| `inventory_movements` | every stock change (`initial`, `adjustment`, `sale`, `cancel`) |
| `product_reviews`, `product_rating_stats` | one review per customer and product, rating distribution |
| `carts`, `cart_items` | one active cart per customer or guest token |
| `orders`, `order_items`, `order_addresses`, `order_status_history` | orders (totals, tax_total, tax_inclusive, points, notes, idempotency key), line snapshots, address snapshots, timeline |
| `payments`, `shipments` | payment rows (COD today), courier + tracking number |
| `reward_wallets`, `reward_transactions`, `reward_redemptions` | points: cached balance, append-only ledger with expiring batches, FIFO links |
| `settings` | key/value store settings (see [SETTINGS.md](SETTINGS.md)) |
| `pincodes` | optional per-postcode serviceability and COD flag (no admin screen yet) |
| `audit_logs` | who did what (admin actions, status changes, low-stock alerts) |
| `media` | spatie medialibrary |
| framework | `migrations`, `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `personal_access_tokens`, spatie `roles`, `permissions`, `model_has_*`, `role_has_permissions` |

## Schema kept for the next phase (no code yet, on purpose)

`wishlists`, `wishlist_items`, `wishlist_shares` · `coupons`, `coupon_targets`, `coupon_usages` (carts/orders already have a nullable `coupon_id`) ·
`banners`, `homepage_sections`, `collections`, `collection_product`, `cms_pages`, `faqs` (content / CMS) · `notifications` ·
`product_questions`, `product_feedback` (reviews family) · `refunds`, `invoices`, `webhook_events`, `shipment_items`, `shipment_events` (online payments, invoicing and courier tracking).

## Removed tables

Dropped because nothing used them and they are not part of the plan: `membership_plans`, `user_memberships`, `return_reasons`, `return_requests`,
`return_request_items`, `return_status_history`, `otp_codes`, `referrals`, `events`, `event_registrations`, `shipping_rules`, `search_terms`, `enquiries`, `enquiry_notes`
(and the columns `users.referral_code`, `users.referred_by_user_id` and `refunds.return_request_id`). The database has **67 tables**.
