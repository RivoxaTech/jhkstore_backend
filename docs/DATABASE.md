# Database (MySQL 8, database `jhkstore_db`)

The product is three things – **products, categories and orders** – plus the settings that control the website. The database holds exactly what those need: **36 tables** (35 created by the baseline migration plus Laravel's `migrations`).

Conventions: InnoDB, `utf8mb4`, `BIGINT UNSIGNED` ids, `created_at`/`updated_at` everywhere, soft deletes (`deleted_at`) on categories, sub-categories and products. Money `DECIMAL(12,2)` (price maths use whole minor units). Statuses are `VARCHAR` mapped to PHP backed enums.
Files use spatie medialibrary (`media` table). "Snapshot" = copied at the time of the event so history never changes.

The database name comes from `DB_DATABASE` in `.env` (`jhkstore_db`; tests use `jhkstore_test` from `phpunit.xml`). It is not hard-coded anywhere else.
The whole schema is ONE baseline migration, `database/migrations/0001_01_01_000000_create_baseline_schema.php` (tables first, foreign keys afterwards). A fresh install runs it with `php artisan migrate --seed`. Every later change is a new migration file after it.
No live database existed when the lean store was cut from the earlier project, so the baseline was edited directly: a database created from an earlier version of the file cannot be upgraded in place, rebuild it with `php artisan migrate:fresh --seed`.

## Relationships (ERD)

```
customers 1──* orders 1──* order_items *──1 product_variants          (customers never log in: a contact record, linked by phone)
                  orders 1──* order_addresses (shipping, billing: snapshots)
                  orders 1──* order_status_history          orders 1──* payments (polymorphic payable)       orders 1──* shipments

categories 1──* sub_categories                  categories 1──* products          sub_categories 1──* products (nullable)
products 1──* product_variants 1──* variant_price_tiers        product_variants 1──* inventory_movements
products 1──* product_images (remote rows)   products 1──* product_content_tabs   products *──* tags (product_tag)   products *──* products (product_relations)

admins *──* roles *──* permissions (spatie)     admins 1──* audit_logs (actor)     admins 1──* inventory_movements (who changed the stock)
settings (key/value)      banners      media (pictures of products, categories, banners, settings)
```

## Tables kept, and why

| Table | Why it is needed |
|---|---|
| **Orders** | |
| `customers` | Contact record created or updated by every order: `name`, `phone` (unique, normalised, the identity), `email` nullable, `city`, `first_order_at`, `last_order_at`, `orders_count`, `total_spent`. No password, no login. Feeds the admin Customers list and CSV export |
| `orders` | The order: `customer_id`, contact snapshot (`customer_name/email/phone`), `status`, `payment_status`, totals (`items_subtotal`, `mrp_total`, `product_discount_total`, `bulk_discount_total`, `shipping_fee`, `tax_total`, `tax_inclusive`, `grand_total`), `customer_note`, `admin_note`, delivery estimate, timestamps per step, cancel reason, `idempotency_key` (unique per customer) |
| `order_items` | The ordered options as they were: name, SKU, picture, MRP, unit price, bulk price used, tax rate and amount, line total, cancelled quantity |
| `order_addresses` | Shipping and billing address snapshots (`name, phone, line1, area, landmark, city, state, postcode, country`) |
| `order_status_history` | The timeline: who moved the order from which status to which, with a note |
| `payments` | One row per order (cash on delivery today: `cod_pending` → `cod_collected`). Polymorphic and gateway-ready for later online payments |
| `shipments` | The parcel: courier, AWB / tracking number and link, created when the admin ships the order |
| **Catalogue** | |
| `categories`, `sub_categories` | The two-level tree: name, slug, description, colour, sort order, active, SEO fields, `categories.is_top`; icon and image through `media` |
| `products` | Category, optional sub-category, name, slug, SKU, descriptions, badge, `tax_rate` (null = store default), min / max order qty, COD flag, status workflow, SEO fields; denormalised `min_price`, `max_discount_percent`, `is_in_stock`, `popularity_score` for fast lists |
| `product_variants` | The options: name, SKU, MRP, selling price, discount, weight, `stock_qty`, `reserved_qty`, `low_stock_threshold`, active, default |
| `variant_price_tiers` | Bulk prices: "buy N or more for X each" |
| `product_images` | Remote picture rows (only for imported data; uploaded pictures live in `media`) |
| `product_content_tabs` | Description tabs (features, description, packaging, specifications, directions, additional info) |
| `product_relations` | Hand-picked related products |
| `tags`, `product_tag` | Labels such as `best-seller`; the storefront builds home page sections from them |
| `inventory_movements` | Every stock change (`initial`, `adjustment`, `sale`, `cancel`) with the balance after |
| **Settings and content** | |
| `settings` | Key/value store settings ([SETTINGS.md](SETTINGS.md)); picture settings own their file through `media` |
| `banners` | Admin-managed banners per placement (`home_hero`, `home_secondary`, `promo_strip`), schedule, order; pictures through `media` |
| `media` | spatie medialibrary: product pictures and their conversions, category icons / images, banner pictures, logo / favicon / share image |
| **Admin accounts** | |
| `admins` | The two admin accounts (developer, admin): username, name, e-mail, password (bcrypt), active, last login |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie permission tables (guard `admin`): roles `developer` and `admin` and the permissions of `AdminAccess` ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)) |
| `personal_access_tokens` | Sanctum tokens, **admin** scripts only (customers have none) |
| `audit_logs` | Who changed what in the admin (settings, catalogue, banners, orders, permission changes, customer exports) and low-stock alerts |
| **Framework** | |
| `migrations` | Laravel's migration log |
| `sessions` | Admin panel session cookie storage |
| `cache`, `cache_locks` | Database cache (settings, category tree) and its locks |
| `jobs`, `job_batches`, `failed_jobs` | The queue tables (nothing is queued yet; `QUEUE_CONNECTION=database` works out of the box when something is) |

## Removed

Dropped from the baseline in the lean cut (data and code): `users` (became `customers`), `user_addresses`, `carts`, `cart_items`, `reward_wallets`, `reward_transactions`, `reward_redemptions`,
`product_reviews`, `product_rating_stats`, `product_questions`, `product_feedback`, `wishlists`, `wishlist_items`, `wishlist_shares`, `coupons`, `coupon_targets`, `coupon_usages`, `notifications`, `faqs`, `cms_pages`,
`homepage_sections`, `collections`, `collection_product`, `invoices`, `refunds`, `webhook_events`, `shipment_items`, `shipment_events`, `pincodes`, `password_reset_tokens`.

Dropped columns: `products.is_returnable`, `return_days`, `country_of_origin`, `warranty_text`, `rating_avg`, `rating_count`; `product_variants.reward_points`, `expiry_date`, `barcode`; `categories.rating`, `sub_categories.rating`;
`orders.coupon_id`, `coupon_code`, `coins_redeemed`, `coins_discount`, `coins_to_earn`, `cod_fee`, `discount_total`, `customer_tax_number`, `membership_applied`, `user_id` (now `customer_id`); `order_items.coins_earned`, `expiry_date`, `returned_qty`;
`order_addresses.tax_number`, `alt_phone`, `address_type`, `latitude`, `longitude`; the refund bank / UPI fields went with `refunds`. Earlier cuts (memberships, returns, OTP, referrals, events, shipping rules, search terms, enquiries, brands, companies, sales channels, third category level) are in [CHANGELOG.md](../CHANGELOG.md).
