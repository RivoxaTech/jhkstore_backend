# Database (MySQL 8, database `jhkstore_db`)

The product is **products, categories and orders** plus the settings that control the website, wholesale requests and push subscriptions. The database holds exactly what those need: **46 tables** (45 created by the baseline migration plus Laravel's `migrations`).

Conventions: InnoDB, `utf8mb4`, `BIGINT UNSIGNED` ids, `created_at`/`updated_at` everywhere, soft deletes (`deleted_at`) on categories, sub-categories and products. Money `DECIMAL(12,2)` (price maths use whole minor units). Statuses are `VARCHAR` mapped to PHP backed enums.
Files use spatie medialibrary (`media` table). "Snapshot" = copied at the time of the event so history never changes.

The database name comes from `DB_DATABASE` in `.env` (`jhkstore_db`; tests use `jhkstore_test` from `phpunit.xml`). It is not hard-coded anywhere else.
The whole schema is ONE baseline migration, `database/migrations/0001_01_01_000000_create_baseline_schema.php` (tables first, foreign keys afterwards). A fresh install runs it with `php artisan migrate --seed`. Every later change is a new migration file after it.
No live database existed when the lean store was cut from the earlier project, and none existed when Phase A (delivery zones, discounts, coupons, persistent cart, bank transfer, reject) and Phase B (wholesale requests, push subscriptions, product video and switches, bank transfer snapshot) were added, so the baseline was edited directly each time: a database created from an earlier version of the file cannot be upgraded in place, rebuild it with `php artisan migrate:fresh --seed`.

## Relationships (ERD)

```
customers 1──* orders 1──* order_items *──1 product_variants          (customers never log in: a contact record, linked by phone)
                  orders 1──* order_addresses (shipping, billing: snapshots)
                  orders 1──* order_status_history          orders 1──* payments (polymorphic payable)       orders 1──* shipments
                  orders *──1 coupons (coupon_id, no foreign key)    orders *──1 delivery_zones (delivery_zone_id, no foreign key)

discounts 1──* discount_targets        coupons 1──* coupon_targets        coupons 1──* coupon_usages *──1 orders
carts 1──* cart_items (variant_id has no foreign key: a deleted option stays in the cart as an unavailable line)      delivery_zones (matched by sort_order)

categories 1──* sub_categories                  categories 1──* products          sub_categories 1──* products (nullable)
products 1──* product_variants 1──* variant_price_tiers        product_variants 1──* inventory_movements
products 1──* product_images (remote rows)   products 1──* product_content_tabs   products *──* tags (product_tag)   products *──* products (product_relations)

admins *──* roles *──* permissions (spatie)     admins 1──* audit_logs (actor)     admins 1──* inventory_movements (who changed the stock)
wholesale_requests *──1 customers (nullable)      push_subscriptions *──1 admins
settings (key/value)      banners      media (pictures of products, categories, banners, settings)
```

## Tables kept, and why

| Table | Why it is needed |
|---|---|
| **Orders** | |
| `customers` | Contact record created or updated by every order: `name`, `phone` (unique, normalised, the identity), `email` nullable, `city`, `first_order_at`, `last_order_at`, `orders_count`, `total_spent`. No password, no login. Feeds the admin Customers list and CSV export |
| `orders` | The order: `customer_id`, contact snapshot (`customer_name/email/phone`), `status` (`pending … delivered`, `cancelled`, `rejected`), `payment_status` (`cod_pending`, `awaiting_payment`, `paid`, `cancelled`, …), `payment_method` (`cod`, `bank_transfer`), totals (`items_subtotal`, `mrp_total`, `product_discount_total`, `bulk_discount_total`, `discount_total` (automatic discounts), `coupon_discount`, `coupon_id`, `coupon_code`, `shipping_fee` (**null** while `delivery_fee_pending`), `delivery_fee_pending`, `delivery_zone_id`, `tax_total`, `tax_inclusive`, `grand_total`), `customer_note`, `admin_note`, delivery estimate, timestamps per step, cancel reason, `rejected_at` + `reject_reason`, `idempotency_key` (unique per customer) |
| `order_items` | The ordered options as they were: name, SKU, picture, MRP, unit price, bulk price used, tax rate and amount, line total, `discount_amount` (what a discount or coupon took off the line) with `discount_source` (`bulk` / `discount` / `coupon`) and `discount_source_id`, cancelled quantity |
| `order_addresses` | Shipping and billing address snapshots (`name, phone, line1, area, landmark, city, state, postcode, country`) |
| `order_status_history` | The timeline: who moved the order from which status to which, with a note |
| `payments` | One row per order. Cash on delivery: `cod_pending` → `cod_collected`. Bank transfer: `awaiting_proof` → `proof_submitted` → `paid`, with `proof_status` (`none`, `submitted`, `verified`, `rejected`), `proof_reference`, `proof_reject_reason`, `verified_at`, `verified_by`, and `bank_snapshot` (JSON: the accounts and instructions the customer was shown when the order was placed; the admin order detail and the customer tracking show this, never the current settings; null for cash on delivery); the screenshot is a medialibrary file on the **private** disk (`media`, collection `proof`). Polymorphic and gateway-ready for later online payments |
| `shipments` | The parcel: courier, AWB / tracking number and link, created when the admin ships the order |
| `delivery_zones` | Delivery zones: `name`, `match_type` (`city` / `area` / `postcode`), `match_values` (JSON list; a postcode may end with `*`), `fee`, `free_above`, `min_order` (replaces the store minimum), `eta_text`, `sort_order` (the order they are matched in), `is_active` |
| `discounts`, `discount_targets` | Automatic discounts: `name`, `type` (`percent` / `fixed`), `value`, `scope` (`store` / `products` / `categories` / `sub_categories`), dates, `is_active`, `priority`; the targets are `(type, target_id)` rows |
| `coupons`, `coupon_targets` | Coupons: unique `code` (stored in capitals), `type` (`percent` / `fixed` / `free_delivery`), `value` (null for free delivery), `scope`, dates, `min_order`, `max_discount`, `usage_limit`, `per_customer_limit`, `first_order_only`, `free_delivery`, `used_count`; targets as for discounts |
| `coupon_usages` | One row per order that used a coupon: `coupon_id`, `order_id`, `customer_id`, normalised `phone` (the per-customer limit counts these), `amount`, `released_at` (set when the order was cancelled or rejected: the use is given back and no longer counts). A coupon with any usage row cannot be deleted |
| `carts`, `cart_items` | The persistent server cart: `carts.token` (unique, 40 random characters, never shown outside the cart API), optional contact (`customer_name/phone/email`) and `coupon_code`, `last_activity_at`, `expires_at` (30 days after the last activity); `cart_items` = `(cart_id, variant_id, qty)`, unique per cart + option. Prices are never stored |
| **Catalogue** | |
| `categories`, `sub_categories` | The two-level tree: name, slug, description, colour, sort order, active, SEO fields, `categories.is_top`; icon and image through `media` |
| `products` | Category, optional sub-category, name, slug, SKU, descriptions, badge, `tax_rate` (null = store default), min / max order qty, COD flag, `is_sold_out` (visible but cannot be ordered), `is_featured`, `video_url` (https YouTube / Vimeo / direct link; uploaded videos are in `media`), status workflow, SEO fields; denormalised `min_price`, `max_discount_percent`, `is_in_stock`, `popularity_score` for fast lists |
| `product_variants` | The options: name, SKU, MRP, selling price, discount, weight, `stock_qty`, `reserved_qty`, `low_stock_threshold`, active, default |
| `variant_price_tiers` | Bulk prices: "buy N or more for X each" |
| `product_images` | Remote picture rows (only for imported data; uploaded pictures live in `media`) |
| `product_content_tabs` | Description tabs (features, description, packaging, specifications, directions, additional info) |
| `product_relations` | Hand-picked related products |
| `tags`, `product_tag` | Labels such as `best-seller`; the storefront builds home page sections from them |
| `inventory_movements` | Every stock change (`initial`, `adjustment`, `sale`, `cancel`) with the balance after |
| **Wholesale and notifications** | |
| `wholesale_requests` | A customer's request for a bulk price: `reference` (`WS-YYYY-000001`, unique), `customer_id` (nullable, found or created by phone), contact (`name`, normalised `phone`, `email`, `business_name`), `product_id` / `variant_id` (nullable) with a `product_name` snapshot (free text allowed), `quantity`, `message`, `status` (`new`, `contacted`, `quoted`, `closed`), `admin_note`, `handled_at`, `handled_by`. Personal data: `wholesale.view` / `wholesale.manage` |
| `push_subscriptions` | One row per browser of an admin that enabled push: `admin_id`, `endpoint` (unique), `p256dh`, `auth`, `user_agent`, `last_used_at`. A 404 / 410 answer from the push service deletes the row |
| **Settings and content** | |
| `settings` | Key/value store settings ([SETTINGS.md](SETTINGS.md)): theme, store status, delivery, payment methods and accounts (JSON), …; picture settings own their file through `media` |
| `banners` | Admin-managed banners per placement (`home_hero`, `home_secondary`, `promo_strip`), schedule, order; pictures through `media` |
| `media` | spatie medialibrary: product pictures and their conversions, product videos (collection `video`) and their posters (`video_poster`), category icons / images, banner pictures, logo / favicon / share image (public disk) and payment proofs (private disk) |
| **Admin accounts** | |
| `admins` | The two admin accounts (developer, admin): username, name, e-mail, password (bcrypt), active, last login |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie permission tables (guard `admin`): roles `developer` and `admin` and the permissions of `AdminAccess` ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)) |
| `personal_access_tokens` | Sanctum tokens, **admin** scripts only (customers have none) |
| `audit_logs` | Who changed what in the admin (settings, catalogue, banners, orders, wholesale requests, permission changes, every CSV export) and low-stock alerts |
| **Framework** | |
| `migrations` | Laravel's migration log |
| `sessions` | Admin panel session cookie storage |
| `cache`, `cache_locks` | Database cache (settings, category tree) and its locks |
| `jobs`, `job_batches`, `failed_jobs` | The queue tables (`QUEUE_CONNECTION=database`): order e-mails and push notifications wait here for the queue worker; the admin sees only the number of failed jobs |

## Removed

Dropped from the baseline in the lean cut (data and code): `users` (became `customers`), `user_addresses`, the loyalty-point tables (wallets, transactions, redemptions),
the customer feedback tables (feedback, rating statistics, questions), the saved-item list tables, `notifications`, `faqs`, `cms_pages`,
`homepage_sections`, `collections`, `collection_product`, `invoices`, `refunds`, `webhook_events`, `shipment_items`, `shipment_events`, `pincodes`, `password_reset_tokens`.

`carts`, `cart_items`, `coupons`, `coupon_targets` and `coupon_usages` were dropped in the lean cut and **exist again with a different meaning** (Phase A): the cart is now a token-bound server cart without accounts, coupons are code-based with limits and race-safe usage, and `coupon_usages` has a `released_at` column. They are described above, not the same tables as before.

Dropped columns: `products.is_returnable`, `return_days`, `country_of_origin`, `warranty_text`, the rating columns; `product_variants` points, `expiry_date`, `barcode`; the rating columns of both category tables;
the loyalty-point columns of `orders`, `cod_fee`, `customer_tax_number`, `membership_applied`, `user_id` (now `customer_id`) (`orders.coupon_id`, `coupon_code` and `discount_total` exist again, see `orders` above); `order_items` points, `expiry_date`, `returned_qty`;
`order_addresses.tax_number`, `alt_phone`, `address_type`, `latitude`, `longitude`; the refund bank / UPI fields went with `refunds`. Earlier cuts (memberships, returns, OTP, referrals, events, shipping rules, search terms, enquiries, manufacturer labels, seller organisations, sales channels, third category level) are in [CHANGELOG.md](../CHANGELOG.md).
