# Features

The product is three things – **products, categories and orders** – plus the admin settings that control the website. Everything else was removed for now.

✅ built and tested · ⏳ possible later (not built) · ❌ removed

## Built

| Area | Feature |
|---|---|
| Catalogue | ✅ two-level categories with icon + image, "top" categories for the home screen · ✅ products with options, per-option price / MRP / stock / SKU, uploaded pictures (several at once, for the product and for each option; reorder, make primary, delete; thumbnails), bulk prices, tags, content tabs, related products, SEO fields, status workflow (draft / active / inactive / archived) · ✅ stock per option with movement log and low-stock alerts · ✅ search (FULLTEXT + SKU) and filters |
| Guest checkout | ✅ no customer accounts: the storefront keeps the cart, `POST /checkout/quote` prices it, `POST /orders` places it (name + phone + optional e-mail + address + items) · ✅ idempotent, race-safe stock deduction, `price_changed` check, cash on delivery · ✅ order lookup with order number + phone · ✅ per-IP / per-phone / per-order rate limits, item and quantity limits, honeypot field |
| Customers | ✅ a contact record created or updated by every order (found by normalised phone; orders count, total spent, first / last order) · ✅ admin Customers list with search (name, phone, e-mail) and CSV export · ❌ registration, login, passwords, address book, wishlists |
| Orders | ✅ status workflow (pending → confirmed → packed → shipped → delivered, cancel before shipping), timeline, courier + tracking number, internal notes, search and filters, printable invoice / packing slip in the admin panel |
| Store configuration | ✅ tabbed Settings page: General, Branding, Banners, Footer & Social, Currency & Tax, Orders & Delivery, SEO – store profile, logo + favicon uploads, unlimited ordered social / footer links, footer / copyright / support hours / WhatsApp, default meta title + description + share image, currency, tax (rate, inclusive / exclusive, label), order number prefix, delivery fee / free-delivery limit / days, minimum order, COD limit |
| Banners | ✅ managed inside Settings → Banners: placements (home hero, home secondary, promo strip), title, subtitle, button text, link, desktop + optional mobile picture, start / end dates, active switch, drag-to-reorder · public `GET /store/banners` returns only the live ones in order |
| Admin panel | ✅ sidebar: Dashboard, Orders, Products, Categories, Customers, Settings, and (developer only) Access Control |
| Access control | ✅ exactly two accounts (developer, admin) · ✅ permissions per section and per Settings tab, switched by the developer · ✅ the developer never sees orders (API, screens, dashboard) · ✅ enforced on every admin route and in the panel ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)) |
| Platform | ✅ audit log (including permission changes) · ✅ rate limiting · ✅ expiring admin API tokens · ✅ generated OpenAPI docs (`/docs/api`, [openapi.json](openapi.json)) |

## Possible later (not built)

Online payments (the stock model has `reserved_qty` for holds, `payments` is gateway-ready) · a small *Payment details* Settings tab (see [SETTINGS.md](SETTINGS.md)) · e-mail / SMS order notifications through a queue · shipping providers · customer accounts, if a client ever needs them.

## Removed

Reward points · reviews and ratings · wishlists · coupons · notifications · FAQs and CMS pages · invoices (the printable invoice / packing slip of an order stays) · refunds · webhooks · shipment items and events · pincodes · the server-side cart and the customer address book · customer registration, login and tokens · the generic Users & Roles screen (replaced by two fixed accounts) · the Features settings tab and every feature switch.
Earlier cuts: seller / company onboarding · sales channels · third category level · brands · memberships · returns · OTP · referrals · events · shipping rules · search terms · enquiries · market-specific tax and profile fields.
