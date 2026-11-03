# Features

The product is three things – **products, categories and orders** – plus the admin settings that control the website. Everything else was removed for now.

✅ built and tested · ⏳ possible later (not built) · ❌ removed

## Built

| Area | Feature |
|---|---|
| Catalogue | ✅ two-level categories with icon + image, "top" categories for the home screen · ✅ products with options, per-option price / MRP / stock / SKU, uploaded pictures (several at once, for the product and for each option; reorder, make primary, delete; thumbnails), bulk prices, tags, content tabs, related products, SEO fields, status workflow (draft / active / inactive / archived) · ✅ stock per option with movement log and low-stock alerts · ✅ search (FULLTEXT + SKU) and filters |
| Guest checkout | ✅ no customer accounts: the storefront keeps the cart, `POST /checkout/quote` prices it, `POST /orders` places it (name + phone + optional e-mail + address + items) · ✅ idempotent, race-safe stock deduction, `price_changed` check · ✅ order lookup with order number + phone (also shows payment, rejection reason and "delivery fee to be confirmed") · ✅ per-IP / per-phone / per-order rate limits, item and quantity limits, honeypot field · ✅ store open / closed (a closed store still quotes, `POST /orders` answers `store_closed`) |
| Payments | ✅ cash on delivery (with a limit) · ✅ **bank transfer**: accounts in Settings, the customer uploads a screenshot (private disk, no public URL, wrong phone = 404), the admin verifies it or rejects it with a reason, the order cannot be confirmed before · ✅ card is only a "coming soon" label, never selectable |
| Delivery | ✅ methods: flat, free, free above an amount, **delivery zones** (city / area / postcode with `*` prefix, fee, free-above, minimum order, ETA, ordered, zone manager inside Settings → Delivery), fee confirmed by the owner after the order · ✅ policy for an address outside every zone (block, owner confirms, flat fee) |
| Discounts and coupons | ✅ automatic **discounts** (percent / fixed; whole store, products, categories, sub-categories; dates; priority; best one per line, never stacked) · ✅ **coupons** (code; same values and scopes; minimum order, maximum discount, total and per-customer limits, first order only, free delivery; the better of discount and coupon per line; usage counted race-safe, given back when the order is cancelled or rejected) · ✅ `POST /store/coupons/validate` · ✅ discounted prices and a label on product cards and the product page (percent discounts) |
| Persistent cart | ✅ server cart bound to a random token (`X-Cart-Token`), add / change / remove / clear, contact and coupon on the cart, prices and stock checked on every read (an unavailable line stays with a warning), expires 30 days after the last activity, `shop:prune-carts` daily, `cart_token` accepted by the quote and the order |
| Customers | ✅ a contact record created or updated by every order (found by normalised phone; orders count, total spent, first / last order) · ✅ admin Customers list with search (name, phone, e-mail) and CSV export · ❌ registration, login, passwords, address book, wishlists |
| Orders | ✅ status workflow (pending → confirmed → packed → shipped → delivered, cancel before shipping, **reject** a pending order with a reason), status tabs with counts and filters (status, payment method, payment status, search), timeline, courier + tracking number, internal notes, search and filters, printable invoice / packing slip in the admin panel |
| Store configuration | ✅ tabbed Settings page: General, Branding, Banners, Footer & Social, Currency & Tax, Orders & Delivery, Delivery, Payments, Store, Theme (developer only), SEO – **theme** (colours, fonts from a fixed list, corner radius, shadow; public in `GET /store/settings`), store open / closed + closed message + checkout notice + delivery estimate text, agency credit in the footer, store profile, logo + favicon uploads, unlimited ordered social / footer links, footer / copyright / support hours / WhatsApp, default meta title + description + share image, currency, tax (rate, inclusive / exclusive, label), order number prefix, delivery fee / free-delivery limit / days, minimum order, COD limit |
| Banners | ✅ managed inside Settings → Banners: placements (home hero, home secondary, promo strip), title, subtitle, button text, link, desktop + optional mobile picture, start / end dates, active switch, drag-to-reorder · public `GET /store/banners` returns only the live ones in order |
| Admin panel | ✅ sidebar: Dashboard, Orders, Products, Categories, Discounts, Coupons, Customers, Settings, and (developer only) Access Control |
| Access control | ✅ exactly two accounts (developer, admin) · ✅ permissions per section and per Settings tab, switched by the developer · ✅ the developer never sees orders (API, screens, dashboard) · ✅ enforced on every admin route and in the panel ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)) |
| Platform | ✅ audit log (including permission changes) · ✅ rate limiting · ✅ expiring admin API tokens · ✅ generated OpenAPI docs (`/docs/api`, [openapi.json](openapi.json)) |

## Possible later (not built)

Online card payments (the stock model has `reserved_qty` for holds, `payments` is gateway-ready) · e-mail / SMS order notifications through a queue · shipping providers · customer accounts, if a client ever needs them.

## Removed

Reward points · reviews and ratings · wishlists · notifications · FAQs and CMS pages · invoices (the printable invoice / packing slip of an order stays) · refunds · webhooks · shipment items and events · pincodes · the old account-based cart and the customer address book (coupons and a token-based cart came back in Phase A with a different design) · customer registration, login and tokens · the generic Users & Roles screen (replaced by two fixed accounts) · the Features settings tab and every feature switch.
Earlier cuts: seller / company onboarding · sales channels · third category level · brands · memberships · returns · OTP · referrals · events · shipping rules · search terms · enquiries · market-specific tax and profile fields.
