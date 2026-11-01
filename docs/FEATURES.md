# Features

✅ built and tested · 🔘 optional (setting) · ⏳ planned (schema may exist) · ❌ not planned in the base

## Built

| Area | Feature |
|---|---|
| Store configuration | ✅ key/value settings with an admin Settings page: store profile + logo, currency (code, symbol, decimals), tax (rate, inclusive/exclusive, label), order number prefix, delivery fee / free-delivery limit / days, minimum order, COD limit |
| Catalogue | ✅ two-level categories with icon + image, "top" categories for the home screen · ✅ products with options, per-option price / MRP / stock / SKU / pictures, bulk prices, tags, content tabs, related products, SEO fields, status workflow (draft / active / inactive / archived) · ✅ stock per option with movement log and low-stock alerts · ✅ search (FULLTEXT + SKU) and filters |
| Brands | 🔘 `Enable brands` (default on): optional brand per product, brand pages, brand filter |
| Customers | ✅ registration, login (email or mobile), address book · ❌ password reset / e-mail verification / OTP (⏳) |
| Cart | ✅ guest + customer carts, merge on login, live repricing, bulk-price hints, stock and price-change warnings, quantity limits |
| Checkout & orders | ✅ cash on delivery, idempotent order placement, race-safe stock deduction, order timeline, cancel, reorder, internal notes, printable invoice / packing slip in the admin panel |
| Reward points | 🔘 `Enable reward points` (default off): earn per option or by earn rate, redeem at checkout with caps, expiry, refund on cancel, credit on delivery |
| Reviews | ✅ list + post (one per customer, published immediately) · ⏳ moderation screen |
| Admin panel | ✅ dashboard, products, categories, brands, tags, orders, settings · ⏳ customers, coupons, reviews moderation, CMS, admin users & roles |
| Platform | ✅ audit log · ✅ rate limiting · ✅ cart clean-up and points-expiry commands · ✅ generated OpenAPI docs (`/docs/api`) |

## Next phase (schema already exists)

Wishlist · coupons · CMS (banners, home page sections, pages, FAQ) · notifications · review moderation and product Q&A.

## Later

Online payments (the stock model already has `reserved_qty` for holds) · returns / refunds / invoices · e-mail / SMS notifications through a queue · admin roles and permissions enforcement ·
customer password reset and verification · shipping providers.

## Removed from the original project (not part of the neutral base)

Seller / company onboarding · sales channels (standard / express) · third category level · membership plans · India-specific tax fields and validation · demo data of the original shop.
