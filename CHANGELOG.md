# Changelog

## Unreleased – neutral, multi-client base

The project was cleaned up from a single-shop clone into a neutral, reusable e-commerce base (one deployment per client).

### Removed
- **Unused tables** (migration `2026_11_02_000001`): memberships, returns, OTP codes, referrals (and `users.referral_code` / `referred_by_user_id`), events, shipping rules, search terms, enquiries – 14 tables. Kept for the next phase: wishlist, coupons, reviews/Q&A, CMS, notifications, refunds, invoices, webhook events, shipment items/events.
- **Seller / company onboarding**: companies, vendor types, registration types and countries (tables, models, services, controllers, requests, routes, admin pages, seeders, tests, docs). Products and order lines no longer reference companies.
- **Sales channels** (standard / express): `sales_channels`, `product_channels`, `variant_channel_stock`, the channel middleware and query parameter, channel filters and switches in the admin panel.
- **Third category level** (sub-sub-categories): `sub_categories.parent_id`, the `children` API field, nested admin UI and queries. Existing products of a sub-sub-category move up to its parent sub-category.
- **Market-specific fields**: GSTIN validation, HSN/GST columns, dental customer profile fields (professional type, speciality, council number, verification status), `products.is_professional_only`, address type "clinic", India/Pakistan/PKR defaults.
- **Original-shop data and branding**: dental categories, demo products with hot-linked images, in-house company, demo orders, the `DK-` order prefix, admin branding, old Postman collections and docs.

### Added
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

