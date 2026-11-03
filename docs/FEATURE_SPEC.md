# Feature spec: next build phase

Source of truth for the next two build phases. Where this file and the code disagree, follow this file and say what you changed.
Baseline: branch `lean-store` (products, categories, orders, guest checkout, settings, banners, two admin accounts with permissions).

## 0. Ground rules

- **Git:** stay on `lean-store`. Never merge, never push, never touch `main`. Commit locally, small commits.
- **Database:** no live databases exist, so edit the baseline migration directly (`0001_01_01_000000_create_baseline_schema.php`). Rebuild with `php artisan migrate:fresh --seed`.
- **Architecture rule stays:** controller → FormRequest → Action class → Eloquent → Resource. Business rules in `Domain/*/Actions`. Prices are calculated only in `CartPricing`.
- **Admin UI:** keep the current admin theme and layout. Only add screens and make them compact (see section 12).
- **Developer never sees orders** (API, screens, dashboard, emails, push, payment proofs). Every new order-related feature belongs to `orders.*` or a new permission the developer can never hold.
- **Every new admin route** gets `actor:admin` + `admin.can:<permission>` and a rate limit. The route-coverage test must keep passing.
- **Every new setting** is one line in `Settings::DEFINITIONS`; every new tab is an entry in `SettingController::TABS` + `AdminAccess::GROUPS`.
- **Every feature** ships with tests, OpenAPI/Postman/STOREFRONT-API updates and a CHANGELOG line.
- **Explicitly not built:** Google/Meta/TikTok tracking pixels or analytics IDs, customer accounts or login, deals/combos, online card payments (card is only a "coming soon" label), product CSV import.

## 1. Feature list and phases

| # | Feature | Phase |
|---|---|---|
| 1 | Settings foundation: theme tokens, store open/closed, checkout notice, delivery ETA text, agency credit | A |
| 2 | Delivery methods and delivery zones | A |
| 3 | Payment methods: COD, bank transfer (accounts, screenshot, verify), card "coming soon" | A |
| 4 | Discounts (automatic) and coupons (code) | A |
| 5 | Persistent server cart (token) with live re-checking | A |
| 6 | Order changes: reject with reason, payment verification, delivery fee confirmation, note-field fix | A |
| 7 | Customer order tracking by order number (already built, hardened) | A |
| 8 | Dashboard upgrade | B |
| 9 | Compact product UI, sold-out and featured toggles, sidebar badge | B |
| 10 | Product video | B |
| 11 | Wholesale requests | B |
| 12 | Order e-mails | B |
| 13 | Admin push notifications | B |
| 14 | Small extras: WhatsApp button, order CSV export, abandoned carts list, low-stock widget | B |

## 2. Permissions (additions)

| Permission | Allows | Admin default | Developer default |
|---|---|---|---|
| `settings.theme` | Settings → Theme (colours, fonts, radius) | never | on (fixed) |
| `settings.store` | Settings → Store (open/closed, notices) | on | on |
| `settings.delivery` | Settings → Delivery (method, zones) | on | on |
| `settings.payments` | Settings → Payments (methods, bank accounts) | on | on |
| `settings.notifications` | Settings → Notifications (e-mail, push) | on | on |
| `discounts.view` / `discounts.manage` | Discounts screen | on | on |
| `coupons.view` / `coupons.manage` | Coupons screen | on | on |
| `wholesale.view` / `wholesale.manage` | Wholesale requests (personal data) | on | off, can be switched on |
| `orders.export` | Order CSV export | on | **never** (belongs to orders) |

Existing hard rules stay: the developer can never hold `orders.*` or `orders.export`; `access.manage` is developer-only; `settings.theme` can never be given to the admin. Payment proofs, order e-mails, order push and the WhatsApp button are all part of `orders.view`. The Access Control screen and `AdminAccess::GROUPS` get the new rows automatically.

## 3. Settings foundation (Phase A)

### 3.1 Theme (developer only, tab "Theme")
Keys: `theme_primary`, `theme_primary_contrast`, `theme_accent`, `theme_bg`, `theme_surface`, `theme_text`, `theme_muted`, `theme_border` (hex colours, validated `#RRGGBB`), `theme_font_heading`, `theme_font_body` (from a fixed allow-list in code, e.g. Inter, Poppins, Playfair Display, Plus Jakarta Sans, Roboto, Lato, system-ui), `theme_radius` (`0px|4px|8px|12px|16px|999px`), `theme_shadow` (`none|soft|medium`).
The tab shows a small live preview card (product card). Public: `GET /store/settings` gets `theme: { primary, primary_contrast, accent, bg, surface, text, muted, border, font_heading, font_body, radius, shadow }`.
The admin panel's own look does not change.

### 3.2 Store tab
Keys: `store_open` (bool, default on), `store_closed_message` (text), `checkout_notice` (text ≤ 300), `delivery_eta_text` (string, e.g. "2 to 4 days", used when no zone ETA exists).
When `store_open` is off: quote still works, `POST /orders` answers `422 { code: "store_closed", message }`. Public settings expose `store.open`, `store.closed_message`, `checkout.notice`, `checkout.eta_text`.

### 3.3 Footer & Social additions
`agency_name`, `agency_url` (https only). Public in `footer.agency { name, url }`.

## 4. Delivery (Phase A)

### 4.1 Setting
`delivery_method` (Delivery tab): `flat` | `free` | `free_above` | `zones` | `owner_confirms`.
- `flat`: `delivery_fee` on every order (existing key).
- `free`: always 0.
- `free_above`: `delivery_fee` unless the discounted items total ≥ `free_delivery_min_cart`.
- `zones`: fee comes from the matching zone (4.2).
- `owner_confirms`: order is placed with the fee unknown; the admin sets it later (section 8.3).
The existing `delivery_fee`, `free_delivery_min_cart`, `delivery_days` settings move to the Delivery tab; keep their keys. Orders & Delivery keeps prefix, minimum order, COD limit.

### 4.2 Zones
Table `delivery_zones`: `id, name, match_type (city|area|postcode), match_values (json list), fee (decimal), free_above (decimal null), min_order (decimal null), eta_text (string null), sort_order, is_active, timestamps`.
Matching (first active zone by `sort_order`): `city` = case-insensitive equality with the address city; `area` = same on area; `postcode` = exact match or prefix entry ending with `*` (e.g. `54*`). No match: setting `delivery_unmatched` = `block` (default, 422 `delivery_not_available`) or `owner_confirms` (fee pending) or `flat` (use `delivery_fee`).
A zone's `min_order` overrides the global minimum for that order.
Zone manager is inside the Delivery tab (create, edit, delete, reorder, activate), like banners.
Quote and order both accept the address fields needed for matching: `city`, `area`, `postcode` (quote gets an optional `address` object). Without an address, a zones-store quote returns `delivery_fee: null, delivery_needs_address: true`.

### 4.3 Quote summary additions
`delivery_method`, `delivery_fee` (number or null), `delivery_pending` (bool), `delivery_zone { id, name, eta_text }`, `delivery_free`, `amount_for_free_delivery`.

## 5. Payments (Phase A)

### 5.1 Settings (Payments tab)
`pay_cod_enabled` (bool, default on), `pay_bank_enabled` (bool, default off), `pay_card_visible` (bool, default off; shown as "coming soon", never selectable), `pay_bank_instructions` (text), `pay_bank_accounts` (new type `accounts`: JSON list of `{bank, title, number, iban, active}`, ordered, unlimited, validated like `links`).
Public: `payment: { cod: {enabled}, bank_transfer: {enabled, instructions, accounts[]}, card: {visible, coming_soon: true} }`.
At least one of COD or bank must stay enabled (validation).

### 5.2 Order flow for bank transfer
1. `POST /orders` with `payment_method: "cod" | "bank_transfer"` (default `cod`). A disabled method is `422 payment_method_unavailable`. `cod_max_amount` applies to COD only.
2. Bank transfer order: `payments.status = awaiting_proof`, order `payment_status = awaiting_payment`.
3. `POST /orders/{number}/payment-proof?phone=` multipart: `proof` (jpg/png/webp, ≤ 4 MB, 100–6000 px), `reference` (optional text). Stored on a **private** disk (not public URL), status becomes `proof_submitted`. Allowed again after a rejection. Rate-limited, honeypot-compatible. Wrong phone = the same 404 as an unknown order.
4. Admin verifies (section 8.2). Until verified, the order cannot move past `pending` (confirm is blocked with `422 payment_not_verified`).
5. COD behaviour is unchanged (collected on delivery).
Table additions: `payments` gets `method`, `proof_status` (`none|submitted|verified|rejected`), `proof_reference`, `proof_reject_reason`, `verified_at`, `verified_by` ; proof file via medialibrary collection `proof` on the payment (private disk). Admin views it through an authenticated route only (`orders.view`).

## 6. Discounts and coupons (Phase A)

### 6.1 Shared model
Tables `discounts` and `coupons`, plus `discount_targets` / `coupon_targets` (`type: product|category|sub_category`, `target_id`) and `coupon_usages` (`coupon_id, order_id, customer_id, phone, amount`).
Common fields: `name`, `type` (`percent|fixed`), `value`, `scope` (`store|products|categories|sub_categories`, targets via the target table), `starts_at`, `ends_at`, `is_active`.
- Discount adds `priority` (int). Applies automatically, no code.
- Coupon adds `code` (unique, case-insensitive, 3–32 chars A–Z 0–9 - _), `description`, `min_order` (null), `max_discount` (cap, null), `usage_limit` (total, null), `per_customer_limit` (by normalised phone, null), `first_order_only` (bool), `free_delivery` (bool: type becomes "free delivery", no `value`), `used_count`.
Scope meaning: `store` = every line; `products` = listed products; `categories` = every product in those categories (including their sub-categories); `sub_categories` = products in those sub-categories only. Percent value 0.01–100; fixed value > 0.

### 6.2 Calculation (only in `CartPricing`)
Per line, in this order:
1. Unit price = option selling price, replaced by the bulk tier price when the quantity reaches it.
2. **Automatic discount:** the matching active, in-date discount with the biggest saving on that line (ties: higher `priority`). Never stacked with another discount. Fixed discounts on `store`/multi-target scope are split proportionally across eligible lines by line total (use minor units, remainder to the last line, never below 0).
3. **Coupon (optional):** `coupon_code` on quote and order. Eligible lines by scope. For each eligible line the **better of** (automatic discount, coupon) applies, never both ("best one wins"). `min_order` is checked against the items total after bulk and automatic discounts; `max_discount` caps the coupon's total saving; percent/fixed rules as above; a `free_delivery` coupon sets delivery to 0 and discounts nothing.
4. Tax after discounts (existing rule); delivery per section 4; `grand_total` as before.
Results: line `discount_amount`, `discount_source` (`bulk|discount|coupon`, `discount_id`/`coupon_id`), summary `product_discount`, `bulk_discount`, `discount_total`, `coupon_code`, `coupon_discount`, `coupon_error { code, message }` (the order is not blocked by an invalid coupon in the quote; it **is** in `POST /orders`: `422 coupon_invalid` with codes `not_found, inactive, expired, not_started, min_order, usage_limit, per_customer_limit, first_order_only, no_eligible_items`).
Orders snapshot: `orders.coupon_id`, `coupon_code`, `discount_total`, `coupon_discount`; `order_items` keep `discount_amount`, `discount_source`. Coupon usage is written inside the order transaction (row-locked, race-safe); cancelled or rejected orders release one use. `first_order_only` = the phone has no earlier non-cancelled, non-rejected order.
Products list/detail show the discounted price: `price`, `compare_price` (MRP or pre-discount price) and `discount_label` where an automatic discount applies (computed with the same service, cached per request; keep the fixed query count tested).

### 6.3 Admin
Two sidebar items, **Discounts** and **Coupons** (compact tables: name/code, type+value, scope, dates, status, used). Form: type, value, scope selector with searchable multi-select for products, categories, sub-categories; dates; limits. Activate/deactivate toggle in the list. Delete is blocked for a coupon that has usages (deactivate instead). Everything audited. Endpoints under `/admin/discounts` and `/admin/coupons`, plus `GET /admin/coupons/{id}/usages`.
Public: `POST /store/coupons/validate { code, items[], address? }` returns the quote with the coupon applied (same engine, no separate maths).

## 7. Persistent cart (Phase A)

Customers never log in, so the cart is bound to a random token kept by the browser (`localStorage`). Tables `carts` (`id, token (unique, 40 chars), customer_phone null, customer_name null, customer_email null, coupon_code null, last_activity_at, expires_at, timestamps`) and `cart_items` (`cart_id, variant_id, qty`, unique per cart+variant).
Endpoints (public, header `X-Cart-Token`; no header = a new cart is created and its token returned in `data.token` and the header):
- `GET /store/cart` priced cart (same shape as quote + `token`), prices and stock re-checked on every read, lines that became unavailable stay with a blocking warning, never silently deleted.
- `POST /store/cart/items { variant_id, qty }` add or add-to-qty; `PUT /store/cart/items/{variantId} { qty }`; `DELETE /store/cart/items/{variantId}`; `DELETE /store/cart`.
- `PUT /store/cart/contact { name?, phone?, email? }` saved so the shop can follow up (abandoned carts list, section 14).
- `PUT /store/cart/coupon { code }` / `DELETE /store/cart/coupon`.
- `POST /store/orders` accepts `cart_token` instead of `items` (items still work for storefronts that keep their own cart). On success the cart is emptied inside the transaction.
Limits: at most `SHOP_MAX_ITEMS_PER_ORDER` lines, `SHOP_MAX_QTY_PER_LINE` per line, 60 mutations/min per IP, a cart expires 30 days after `last_activity_at` (sliding), `php artisan shop:prune-carts` runs daily (scheduled). Cart token is never exposed in admin lists beyond an id.
Quote (`POST /checkout/quote`) stays stateless and accepts `items` or `cart_token`.

## 8. Order changes (Phase A)

### 8.1 Reject
New status `rejected`, only from `pending`, reason required (≤ 300). Same effects as cancel: stock returns, customer totals reversed, coupon use released, final state. Shown to the customer on tracking with the reason. Cancel keeps its current rules.

### 8.2 Payment verification (admin, `orders.manage`)
`POST /admin/orders/{id}/payment/verify` (marks proof verified, payment `paid`, history + audit) and `POST /admin/orders/{id}/payment/reject-proof { reason }` (customer must upload again). The order screen shows the proof image, reference, the accounts shown to the customer, and the two buttons.

### 8.3 Delivery fee confirmation
When `delivery_pending` is true the order stores `shipping_fee = null`, `delivery_fee_pending = true`. Admin `PUT /admin/orders/{id}/delivery-fee { fee }` sets it, recalculates tax and `grand_total`, writes history + audit. Confirming a pending-fee order is blocked with `422 delivery_fee_pending`. Customer tracking shows "delivery fee to be confirmed", then the final total (and an e-mail if enabled, section 13).

### 8.4 Fixes
- `PATCH /admin/orders/{id}/note`: field `admin_note` is now required (`present`, nullable string); missing field = 422 instead of clearing the note. Add a regression test.
- Order list gets filters `status`, `payment_method`, `payment_status`, `search`, and a `counts` object per status for the tabs.

## 9. Customer order tracking (Phase A)

Already built: `GET /store/orders/{orderNumber}?phone=` (wrong phone = same 404). Keep this as the only tracking. Extend the answer with: `payment { method, status, proof_status, reject_reason }`, `delivery_fee_pending`, `reject_reason`, `store_phone`/`whatsapp_url` for contact. The tracking e-mail (Phase B) contains the order number and a link built from the setting `storefront_track_url` (e.g. `https://shop.example/track`, the storefront reads `?order=` and asks for the phone). No tokens in URLs, no tracking pixels, no analytics.

## 10. Dashboard (Phase B)

`GET /admin/dashboard?range=today|7d|30d|month|all` (default 30d).
With `orders.view`: KPI cards: revenue (orders not cancelled/rejected, by `grand_total`), pending amount, total orders, average order value, pending count, cancelled+rejected count, awaiting-payment-proof count; daily series (revenue + orders) for the chart; top 5 selling products (qty, revenue); 8 most recent orders. With `products.view`: low-stock options (≤ threshold, top 8), product counts by status. With `wholesale.view`: new requests count. With `customers.view`: new customers in range. A user without a permission gets none of that section (developer: catalogue widgets only, no order or customer figures, no wholesale). Layout like the RS Home dashboard: date tabs, coloured KPI cards, chart, top sellers, recent orders. Charts are plain SVG/CSS (no heavy library).

## 11. Sidebar badge (Phase B)
Orders item shows the count of `pending` orders plus bank-transfer orders with a submitted proof; polled every 30 s through `GET /admin/orders/pending-count` (`orders.view`). Wholesale item shows the count of `new` requests the same way.

## 12. Compact product UI, toggles (Phase B)

- Product form: collapsible sections (Basics, Options and pricing, Stock, Images, Video, Content, SEO, Related), all open on create, remembered per section; sticky save bar; options as a compact editable table (name, SKU, MRP, price, stock, threshold, active) with bulk-tier popover per row; inline validation messages.
- Product list: dense table (image, name, category, price, stock, status, sold-out switch, featured switch), filters by category/status/stock, search.
- New product columns: `is_sold_out` (bool, default false; shown as "Sold out", cannot be ordered, still visible), `is_featured` (bool). Endpoints `PATCH /admin/products/{id}/sold-out`, `PATCH /admin/products/{id}/featured` (`products.manage`, audited). Public `GET /products?featured=1`, product cards carry `is_sold_out`, `is_featured`.
- Category list: same density. No change to the admin colours or fonts.
- If a store has no sub-categories, the product and category forms hide the sub-category field (derived: no sub-category exists).

## 13. Product video (Phase B)
Medialibrary collection `video` on products (max 2 files): mp4/webm, ≤ 50 MB each (value in `config/shop.php`; document that `upload_max_filesize` and `post_max_size` must be raised on the server), optional poster image per video (≤ 2 MB). Also an optional `video_url` (https YouTube/Vimeo/direct) string. Public product detail: `videos: [{ url, poster_url, mime, source: "upload"|"embed" }]`. Admin: Video section in the product form (upload, progress, delete, reorder). No server-side transcoding.

## 14. Wholesale requests (Phase B)
Table `wholesale_requests`: `id, reference (WS-YYYY-000001), customer_id null, name, phone, email null, business_name null, product_id null, variant_id null, product_name (snapshot, free text allowed), quantity (int), message (text ≤ 1000), status (new|contacted|quoted|closed), admin_note, handled_at, handled_by, timestamps`; `wholesale_request_items` is not needed, one request = one product line; free-text for "other".
Public: `POST /store/wholesale-requests` (name, phone required, email, business_name, product_id/variant_id or product_name, quantity ≥ 1, message), honeypot, 5/hour per IP and 5/hour per phone, upserts the customer record by phone (same normaliser). Answer: reference only.
Admin screen **Wholesale** (`wholesale.view/manage`): list with status tabs, search, detail with note and status change, WhatsApp link button, CSV export. Audited. E-mail/push on new request (sections 15, 16).

## 15. Order e-mails (Phase B)
Settings tab **Notifications**: `mail_enabled`, `mail_from_name`, `mail_reply_to`, `notify_admin_email` (store owner address for new-order alerts, optional), switches `mail_on_placed`, `mail_on_confirmed`, `mail_on_shipped`, `mail_on_delivered`, `mail_on_cancelled` (covers rejected), `mail_on_payment_proof_rejected`, plus `storefront_track_url`.
Customer mails go only when the order has an e-mail. Content: order number, items, totals, payment method and bank instructions (for bank transfer), delivery estimate, courier + tracking number (shipped), reason (cancelled/rejected), tracking link (section 9), store name/logo/contact. Plain, mobile-friendly Blade templates in `resources/views/mail`, neutral wording, no external images or trackers. One mailable per event, queued (`ShouldQueue`, after commit). A mail failure must **never** fail or roll back an order. `notify_admin_email` gets "new order" and "new payment proof" alerts. A "send test e-mail" button in the tab (`settings.notifications`).
Queue: `QUEUE_CONNECTION=database` is now required. Document two options in NEW_CLIENT: a worker (`php artisan queue:work --tries=3`) under supervisor, or on shared hosting a cron `* * * * * php artisan queue:work --stop-when-empty --max-time=55`. Add `failed_jobs` visibility to the Notifications tab (count only).

## 16. Admin push notifications (Phase B)
Web Push (VAPID) with `minishlink/web-push`. Table `push_subscriptions` (`admin_id, endpoint (unique), p256dh, auth, user_agent, last_used_at`). `.env`: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`; command `php artisan shop:vapid` prints a new pair. Endpoints (admin): `POST /admin/push/subscribe`, `POST /admin/push/unsubscribe`, `GET /admin/push/status`. Public key through `GET /admin/auth/me` or `/admin/push/status`.
Events: new order, payment proof submitted, new wholesale request. Recipients: subscribed admins who hold `orders.view` (orders and proofs) or `wholesale.view`; the developer is therefore never notified about orders. Payload: title, short body (order number and total), URL `/admin/orders/{id}`; sent through a queued job; 404/410 responses delete the subscription; a failure never affects the order. Admin panel: service worker `public/admin-sw.js` (scope `/admin`), a bell button in the header to enable/disable on this device, permission prompt only on click. Works on HTTPS and localhost. The polled badge (section 11) is the fallback when push is unavailable. Setting `push_enabled` (Notifications tab).

## 17. Small extras (Phase B)
- **WhatsApp button** on the order detail and wholesale detail: `https://wa.me/<digits>?text=<prefilled message with order number and status>` built in the panel.
- **Order CSV export** (`orders.export`, audited): order number, date, customer, phone, status, payment method/status, items count, totals, city; respects the current filters.
- **Abandoned carts**: a tab inside Customers (`customers.view`): carts with a saved phone or e-mail and items, last activity older than 2 hours and no order since; columns name, phone, email, items, value, last activity, WhatsApp button, CSV export (`customers.export`).
- **Low-stock widget** on the dashboard (section 10).

## 18. Data and API summary

New tables: `delivery_zones`, `discounts`, `discount_targets`, `coupons`, `coupon_targets`, `coupon_usages`, `carts`, `cart_items`, `wholesale_requests`, `push_subscriptions`.
Changed tables: `orders` (`coupon_id`, `coupon_code`, `discount_total`, `coupon_discount`, `delivery_fee_pending`, `shipping_fee` nullable, `reject_reason`, `rejected_at`, `delivery_zone_id`), `order_items` (`discount_amount`, `discount_source`), `payments` (proof fields), `products` (`is_sold_out`, `is_featured`).
New setting types: `accounts`. New settings: sections 3 to 5, 15, 16.
New public endpoints: `GET/POST/PUT/DELETE /store/cart…`, `POST /store/coupons/validate`, `POST /store/orders/{number}/payment-proof`, `POST /store/wholesale-requests`.
New admin endpoints: discounts, coupons, delivery zones, payment verify/reject-proof, delivery-fee, product sold-out/featured/video, wholesale, push, order export, abandoned carts, dashboard range.

## 19. Definition of done (each phase)

1. Full PHPUnit suite green; new tests for every rule above, including: coupon scopes and limits, best-one-wins, usage race (two simultaneous orders on the last coupon use), zone matching, delivery-pending flow, bank-transfer flow with the wrong-phone 404, rejected order releases stock and coupon, cart persistence and expiry, developer blocked from every new order-related endpoint, route-coverage test, rate-limit coverage test.
2. `scripts/flow-check.mjs` and `admin-frontend/e2e` extended with the new flows; `npm run build`, `npm run e2e` and `migrate:fresh --seed` pass.
3. Docs updated: README, ARCHITECTURE, DATABASE, FEATURES, SETTINGS, STOREFRONT-API, ACCESS_CONTROL, NEW_CLIENT, CHANGELOG, QA_REPORT, `docs/openapi.json`, Postman.
4. Repo search for leftovers (dental, dentalkart, gst, hsn, brand, reward, coins, wishlist, review, company) is clean, and no tracking or analytics code exists.
5. `git branch --show-current` = `lean-store`, `git status` clean, nothing merged or pushed.
