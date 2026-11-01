# Features

✅ built and tested · 🔘 optional (setting) · ⏳ planned (schema may exist) · ❌ not planned in the base

## Built

| Area | Feature |
|---|---|
| Store configuration | ✅ key/value settings with a tabbed admin Settings page (General, Branding, Footer & Social, Currency & Tax, Orders & Delivery, Features, SEO): store profile, logo + favicon uploads, unlimited ordered social/footer links with an active switch, footer / copyright / support hours / WhatsApp, default meta title + description + share image, currency (code, symbol, decimals), tax (rate, inclusive/exclusive, label), order number prefix, delivery fee / free-delivery limit / days, minimum order, COD limit |
| Catalogue | ✅ two-level categories with icon + image, "top" categories for the home screen · ✅ products with options, per-option price / MRP / stock / SKU, ✅ uploaded pictures (several at once, for the product and for each option; reorder, make primary, delete; thumbnails), bulk prices, tags, content tabs, related products, SEO fields, status workflow (draft / active / inactive / archived) · ✅ stock per option with movement log and low-stock alerts · ✅ search (FULLTEXT + SKU) and filters |
| Customers | ✅ registration, login (email or mobile), address book, expiring tokens with refresh · ❌ password reset / e-mail verification / OTP (⏳) |
| Cart | ✅ guest + customer carts, merge on login, live repricing, bulk-price hints, stock and price-change warnings, quantity limits |
| Checkout & orders | ✅ cash on delivery, idempotent order placement, race-safe stock deduction, order timeline, cancel, reorder, internal notes, printable invoice / packing slip in the admin panel |
| Reward points | 🔘 `Enable reward points` (default off): earn per option or by earn rate, redeem at checkout with caps, expiry, refund on cancel, credit on delivery |
| Banners | ✅ banners per placement (home hero, home secondary, promo strip): title, subtitle, button text, link, desktop + optional mobile picture, start / end dates, active switch, drag-to-reorder; the storefront gets only the live ones in order |
| Reviews | ✅ list + post (one per customer, published immediately) · ⏳ moderation screen |
| Admin panel | ✅ dashboard, products, categories, tags, banners, orders, settings · ✅ users & roles (super admin, manager, staff) · ⏳ customers, coupons, reviews moderation, CMS |
| Platform | ✅ audit log · ✅ admin roles and permissions enforced on every admin route · ✅ rate limiting · ✅ cart clean-up and points-expiry commands · ✅ generated OpenAPI docs (`/docs/api`) |

## Next phase (schema already exists)

Wishlist · coupons · CMS (home page sections, pages, FAQ) · notifications · review moderation and product Q&A.

## Later

Online payments (the stock model already has `reserved_qty` for holds) · returns / refunds / invoices · e-mail / SMS notifications through a queue ·
customer password reset and verification · shipping providers.

## Removed from the original project (not part of the neutral base)

Seller / company onboarding · sales channels (standard / express) · third category level · membership plans · India-specific tax fields and validation · demo data of the original shop.
