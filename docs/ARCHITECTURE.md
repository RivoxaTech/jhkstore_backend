# Architecture

An API-first **modular monolith**: one Laravel application, no microservices. The storefront is a separate app (any framework) that talks JSON to it;
the admin panel is a Vue single-page app that talks to the same API. **One deployment per client** – no multi-tenancy; client values live in `backend/.env` and the `settings` table.

```
 Storefront (static site, CDN)──┐   public, no login, no token
 Admin panel (Vue 3)          ├──►  Laravel API  /api/v1
                              │        ├─ /store/*   public: settings, banners, catalogue, quote, coupons, cart, guest orders, wholesale requests
                              │        └─ /admin/*   admin session cookie (panel) or bearer token (scripts), a permission per route
                                       │
                              MySQL 8 · local/S3 file storage · database cache/sessions/queue
                                       └─ queue worker (e-mails, push) · browser push service (VAPID)
```

## Technology

| Concern | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.3 |
| Database | MySQL 8 (FULLTEXT search, row locks – SQLite is not supported) |
| Auth | Only the two admin accounts log in. Laravel Sanctum: admin panel = HttpOnly session cookie + CSRF (the same login endpoint issues an expiring bearer token to non-browser clients). **Customers have no account** |
| Roles | spatie/laravel-permission: roles `developer` and `admin`; the permission list and the hard rules are in `App\Domain\Auth\AdminAccess`, the role → permission rows are in the database and switched by the developer ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)). Every admin route is protected by `admin.can:<permission>` |
| Media | spatie/laravel-medialibrary (category icons, product pictures, product videos and their posters, banners, the logo / favicon / share image of the settings on the `public` disk; bank transfer payment proofs on the **private** `local` disk) |
| Mail and push | Laravel mail (queued mailables, `MAIL_MAILER=log` in development) · minishlink/web-push (VAPID) · queue `database` |
| API docs | dedoc/scramble → `/docs/api`, exported to `docs/openapi.json` |
| Admin panel | Vue 3 + Vite + Pinia + Vue Router in `admin-frontend/` (builds into `backend/public/admin-assets`, served at `/admin`) |
| Tests | PHPUnit feature tests against a MySQL test database (`jhkstore_test`); a puppeteer browser smoke test (`npm run e2e`) |

## Code layout

```
app/
  Domain/                    business logic, one folder per module
    Auth  Cart  Catalog  Content  Customer  Delivery  Notification  Order  Promotion  Support  Website  Wholesale
      Models/  Actions/  Enums/  (+ module services)
  Http/
    Controllers/Store|Admin  thin controllers
    Middleware/              EnsureActor (`actor`), EnsureAdminPermission (`admin.can`), RejectHoneypot (`honeypot`), StoreCacheHeaders (`store.cache`), TrackStorefrontChanges (`storefront.changes`)
    Requests/                FormRequest validation
    Resources/               API response shapes
  Mail/                      the queued mailables (one per order event, the owner alert, the test e-mail)
  Support/                   Audit, Csv, FullTextSearch, GeneratesUniqueSlug, MediaUrl, ShopTime, TokenLifetime
backend/routes/api_store.php  backend/routes/api_admin.php  backend/routes/web.php (serves the admin SPA at /admin)  backend/routes/console.php (the schedule)
backend/config/shop.php          checkout limits, honeypot field name, public rate limits, payment proof and video limits
backend/config/webpush.php       the VAPID keys (.env)
backend/resources/views/mail     the e-mail templates
backend/public/admin-sw.js       the service worker of the admin push notifications
backend/database/migrations  backend/database/seeders (DatabaseSeeder = base, DemoSeeder = optional sample data)
admin-frontend/          Vue admin panel
```

`Domain/Cart` holds `CartPricing` (the one price calculator) with its `PricedCart` / `PricedLine` / `PricingContext`, used by the quote, the coupon check, the persistent cart and checkout, and the persistent cart itself (`Cart`, `CartItem`, `Actions/ManageCart`, `Actions/QuoteCheckout`).
`Domain/Notification` has `Notifier` (the one place that tells people about an event: customer mail, owner mail, push; every step isolated; one switch per event and channel, `Settings::EVENTS`), `PushMessage` (the payloads: title, body, logo icon, tag, link), `SendPushNotification` (the queued push job), `SendTestPush` (the test notification), `PushSender` / `WebPushSender`, `PushSubscription`, `PushLog` (last push and test results, cache) and `QueueHealth` / `QueueHeartbeat` (is a queue worker running). `Domain/Wholesale` has `WholesaleRequest` and the submit / update actions. `Domain/Support/Dashboard` holds the dashboard queries, `Domain/Cart/AbandonedCarts` the abandoned cart query.
`Domain/Website` has `Rebuild` (the debounce, the call to the hosting's deploy hook and its status) and `TriggerRebuild` (the queued job); `Domain/Catalog/Sitemap` builds the storefront sitemap.
`Domain/Promotion` has `Discount`, `Coupon` (+ targets, `CouponUsage`), the `Promotions` reader (live discounts, loaded once per request) and the save actions. `Domain/Delivery` has `DeliveryZone`, the `ZoneMatcher` and `SaveDeliveryZone`.

Rule: controller → FormRequest → **Action class** → Eloquent → Resource. Business rules live in `Domain/*/Actions` (`PlaceOrder`, `ChangeOrderStatus`, `SaveProduct`, `UpsertCustomer`, …) and `CartPricing`, never in controllers.

## Settings

* `Settings` (`backend/app/Domain/Support/Settings.php`) is the only reader/writer of store configuration (see [SETTINGS.md](SETTINGS.md)). Typed values include `file` (medialibrary picture owned by the setting row) and `links` (ordered JSON list), with validation rules by type in `Settings::rules()`.
* The admin Settings page is built from `SettingController::TABS`; each tab has its own permission, so the developer decides which tabs the client sees. A new tab is a new entry there (see [SETTINGS.md](SETTINGS.md)).
* Setting types: `string`, `text`, `bool`, `int`, `money`, `percent`, `file`, `links`, `accounts` (ordered bank accounts), `color` (`#RRGGBB`) and `choice` (one value of a fixed list, `Settings::CHOICES`). Two rules need more than one key and are checked in `SettingController` (one payment method stays on; bank transfer needs an active account).
* **Banners** (`Domain/Content`): one `banners` table, pictures with medialibrary (`desktop`, `mobile`). A placement is a key in `Banner::PLACEMENTS` (add a line for another spot). The public endpoint returns the `Banner::live()` ones in `sort_order`; one banner or a slider is the storefront's choice. Admin management is the *Banners* tab of Settings (`settings.banners`).
* **Time zone**: `APP_TIMEZONE` is the **shop time zone**. Dates are stored as shop wall-clock time, `now()` is shop time, and every date in an API answer carries the shop's offset (`2026-11-03T23:59:00+05:00`, set in `AppServiceProvider`). A date typed in the panel without an offset is shop time, one with an offset is converted (`App\Support\ShopTime`); the panel learns the zone from `GET /admin/auth/me` and formats, types and compares dates in it, so a discount ending 23:59 ends then whatever the browser's zone is. Dashboard ranges are shop days.
* Money: amounts are `DECIMAL(12,2)`; price maths run in whole minor units (`Settings::cents()/money()`), the number of decimals comes from `currency_decimals` (0–2).

## Catalogue model

* **Categories** – two levels: `categories` → `sub_categories`. A product has `category_id` (required) and `sub_category_id` (optional, must belong to the category).
  The storefront sees a cached tree (`CategoryTree`, 10 minutes, cleared on every admin write).
* **Products are parents of options (variants).** Price, MRP, stock, SKU and pictures are per option; the listing shows the cheapest option.
  A product is shown on the storefront when it is `active`, its category and sub-category are active and it has an active option.
* **Sold out and featured**: `products.is_sold_out` keeps a product visible but blocks ordering (`sold_out` issue in the quote, `cart_invalid` on the order) and makes the `in_stock` filter skip it; `is_featured` feeds `?featured=1`.
* **Video**: the media collection `video` (at most 2 files, mp4 / webm, content checked, `shop.video.max_kb`) with a poster in `video_poster` (custom property `video_id`), plus the optional `video_url` (https YouTube / Vimeo / direct file). The public detail has `videos [{url, poster_url, mime, source}]`; nothing is transcoded. PHP `upload_max_filesize` and `post_max_size` must allow the size.
* **Pictures** are uploaded in the admin panel (`POST /admin/products/{id}/images`, optionally with `variant_id` for an option's own gallery) and stored with medialibrary (`thumb` 256 px and `medium` 512 px conversions). PNG / JPG / WEBP, 100–8000 px, up to 8 MB, at most 12 per gallery; SVG is refused. The first picture of a gallery is its primary one (`PUT …/images/{id}/primary`, or `PUT …/images/order`). Remote-URL rows in `product_images` only exist for imported data.
* **Stock** lives on the option (`stock_qty`, `reserved_qty`, `low_stock_threshold`); every change writes `inventory_movements`.
* **Bulk prices** (`variant_price_tiers`): "buy N or more for X each", up to 5 per option.
* **Tags** – labels such as `best-seller`; the storefront builds home page sections with `GET /products?tag=best-seller`. The seven system tags are created by the demo seeder.
* Tax: product `tax_rate` (null = the store default), prices inclusive or exclusive of tax (setting).

## Guest checkout and orders

* **No customer accounts.** The storefront keeps the cart (variant ids and quantities) itself, or uses the **persistent server cart**: a random 40-character token the browser keeps (`X-Cart-Token`, `Domain/Cart`, tables `carts` / `cart_items`; prices are never stored, only option ids and quantities, and the cart is priced again on every read). A cart expires 30 days after its last activity (every call renews it); `php artisan shop:prune-carts` runs daily from the scheduler. `POST /store/checkout/quote` prices items or a `cart_token` with `CartPricing`; **every price is recalculated on the server**, the client's prices are ignored.
* **`CartPricing`** is the only place prices are calculated, in whole minor units: option price or bulk price → the best **automatic discount** per line (never stacked; priority on ties; a fixed amount is taken off the eligible lines once, split by line total, the remainder goes to the last lines) → an optional **coupon** (for each line the better of discount and coupon, never both; `min_order` after bulk prices and discounts; `max_discount` caps the total; a free-delivery coupon takes nothing off the items) → tax on what is paid → **delivery** by the store's method (`flat`, `free`, `free_above`, `zones`, `owner_confirms`; zones are matched in `sort_order`, an address outside every zone follows `delivery_unmatched`). The minimum order is checked after bulk prices and automatic discounts, before the coupon; free delivery above an amount uses the final subtotal. `CartPricing::displayPrice()` gives the product lists the same discounts: only a **percent** discount lowers a shown price, a fixed amount only gives `discount_label`.
* **Customers** (`customers`) are a contact record: every order finds or creates the customer by **normalised phone** (`Phone::normalise`: digits, leading `+` kept, `00` → `+`, a single leading `0` → the `default_phone_country_code` setting when it is set), updates the name / e-mail, links `orders.customer_id` and updates `orders_count`, `total_spent`, `first_order_at`, `last_order_at` (a cancelled order is taken out again). The order keeps snapshots of the contact and both addresses.
* **Checkout** (`POST /store/orders`, `PlaceOrder`) first refuses a closed store (`store_closed`) and a payment method the store switched off (`payment_method_unavailable`), then is one transaction: lock the cart row (when a `cart_token` is used) → lock the option rows (in id order, deadlock-free) → look for an earlier order with the same `Idempotency-Key` from the same phone → price again with the coupon row locked `FOR UPDATE` (always in this order: cart, options, coupon) → validate (stock, quantity limits, coupon → `422 coupon_invalid`, delivery zone → `422 delivery_not_available`, minimum order, COD limit for cash on delivery, the total the customer saw → `409 price_changed`) → find or create the customer → write order, lines, addresses, payment, history → deduct stock → write the coupon use and raise `used_count` (so two orders cannot take the last use) → empty the cart. A failed order leaves nothing behind, not even a customer.
* **Order lookup** (`GET /store/orders/{number}?phone=`): the order's phone must match; a wrong phone is a `404` identical to a missing order.
* **Bank transfer snapshot**: when a bank transfer order is placed, `payments.bank_snapshot` keeps the accounts and instructions the customer was shown; the admin order detail and the customer tracking answer show that snapshot, never the current settings.
* **Payment**: `cod` (collected on delivery) or `bank_transfer` (`payments` row per order; card is only a "coming soon" label and can never be chosen). A bank transfer order starts `awaiting_payment`; the customer uploads a screenshot (`POST /store/orders/{number}/payment-proof`, stored with medialibrary on the **private** disk, no public URL; the admin sees it through the authenticated route `GET /admin/orders/{id}/payment/proof`); the admin verifies it (order and payment become `paid`) or rejects it with a reason (the customer uploads again). Until verified the order cannot be confirmed (`payment_not_verified`). Online gateways remain a possible later module.
* **Delivery fee pending**: with `owner_confirms` (or an unmatched address set to `owner_confirms`) the order is placed with `shipping_fee = null` and `delivery_fee_pending = true`; the admin sets the fee (`PUT /admin/orders/{id}/delivery-fee`), the total follows; confirming is refused until then (`delivery_fee_pending`).
* **Order numbers**: `<order_number_prefix>-<year>-<id, 6 digits>`.
* **Status flow** (`ChangeOrderStatus`, the only place a status changes; admin only): `pending → confirmed → packed → shipped → delivered`, one step at a time; `cancelled` while pending/confirmed/packed; `rejected` only from pending, with a reason (≤ 300) the customer sees. Shipping needs a courier and tracking number. Delivered marks COD paid. Cancelled and rejected return the stock, reverse the customer's totals, give the coupon use back (`coupon_usages.released_at`) and void a payment that was not taken (a verified bank transfer stays `paid`: the shop refunds it by hand); both are final and do not count as revenue on the dashboard. Every change writes a history row and an audit log entry.

## Notifications and the queue

* **One place**: `Notifier` is called by the actions (`PlaceOrder`, `ChangeOrderStatus`, `HandlePaymentProof`, `ConfirmDeliveryFee`, `SubmitWholesaleRequest`) **after their transaction has committed**. Each channel runs inside its own `try / catch` (the error is logged), so a mail server that is down, a missing queue table or a push service that refuses can never fail or roll back an order, a status change or an upload.
* **E-mail**: one queued mailable per event in `backend/app/Mail` (`ShouldQueue`, `afterCommit`), plain Blade templates in `backend/resources/views/mail`, no images, no tracking pixels, only the store's own tracking link (`storefront_track_url?order=NUMBER`). Customer mails need `mail_enabled` (default off), the event's switch and an e-mail on the order; the owner alert (`notify_admin_email`) is not an account of the admin panel. The test e-mail button sends at once, not queued, so a wrong setup is reported. `GET /admin/notifications/status` shows counts only (waiting and failed jobs), never the content of a job.
* **Push**: `SendPushNotification` is queued with a permission (`orders.view` for new orders and payment proofs, `wholesale.view` for wholesale requests). When the job runs it picks the subscribed, active admins who hold that permission **at that moment** through `AdminAccess::allows`, so the developer account never receives an order notification. A 404 / 410 answer deletes the subscription; other errors are logged. Push needs `push_enabled`, the three VAPID values in `backend/.env` and HTTPS (or localhost).
* **Push content (Phase C)**: title "New order" ("Payment proof to check", "New wholesale request"), body with the order number and total, the store logo as `icon`, a `tag` per order, `url` `/admin/orders/{id}`. `backend/public/admin-sw.js` shows it with `requireInteraction` (it stays until clicked), tells every open panel tab (`postMessage`) so the panel can chime and show a toast, and on click focuses a panel tab that is already open and asks it to go to the order (otherwise opens a new window). The system plays its own notification sound, which a site cannot change: the panel plays a bundled chime itself (`src/assets/chime.wav`, `utils/chime.js`) when the polled pending count rises or a push message arrives, unless muted (header toggle, `localStorage`); browsers allow sound only after a click, so the first click unlocks it and the header shows a hint until then.
* **Test notification and status**: `POST /admin/push/test` queues `SendTestPush` for the caller's own device(s); `GET /admin/push/test/{id}` reports `queued` / `sent` / `failed` / `no_device` with the push service's own error (addresses removed). `GET /admin/push/status` and `/admin/notifications/status` give the VAPID state, the switch, devices, the last push (`PushLog`) and the **queue worker state** from `QueueHealth`: `running` (a worker finished or failed a job in the last 10 minutes, or the scheduled `shop:queue-heartbeat` job ran in the last 3), `stopped` (a job has waited longer than 2 minutes and no worker was seen), `idle` (cannot be told), `sync`. The panel shows it in the *Notification status* card (Settings → Notifications and the header popover next to the bell).
* **Queue**: `QUEUE_CONNECTION=database`. Run `php artisan queue:work --tries=3` under a process manager, or on shared hosting a cron job `* * * * * php artisan queue:work --stop-when-empty --max-time=55` ([NEW_CLIENT.md](NEW_CLIENT.md)). Without a worker the e-mails and push messages wait in the `jobs` table; the sidebar badges (polled every 30 seconds) work without it.

## The static storefront (Stage 1 of the storefront work)

The customer site is built as a **static site** (prerendered pages on a CDN), so the API has to give it three things: everything to prerender, headers a CDN may cache, and a way to ask for a new build.

* **Sitemap**: `GET /store/sitemap` (`Domain/Catalog/Sitemap`) lists the slugs of every visible product, category and sub-category with `updated_at` and one picture; no prices. Cached 5 minutes, products read in chunks (a fixed number of queries per chunk), dropped on every storefront-relevant admin write.
* **Cache headers**: the `store.cache` middleware wraps the whole store route group. A successful `GET` of a route named in `StoreCacheHeaders::PUBLIC_ROUTES` (settings, banners, categories, products, tags, sitemap) and without an `X-Cart-Token` gets `Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=60` and an ETag (304 on `If-None-Match`); everything else, errors included, gets `no-store`. The values are in `backend/config/shop.php`. The decision is by route **name**, so a new route is private until it is listed.
* **Change tracking**: the `storefront.changes` middleware sits on the admin API group. After a successful write it looks at the route (not at the data): products, categories, sub-categories, tags, banners, discounts and the public settings (all groups except notifications and website) count; orders, customers, wholesale, coupons and delivery zones do not, and a sale never passes there at all. It drops the sitemap cache and calls `Rebuild::changed()`, inside a `try / catch`: it can never fail the admin action.
* **Rebuild**: `Rebuild::changed()` counts the change and, when no window is open, schedules ONE `TriggerRebuild` job delayed by `rebuild_debounce_seconds` (the window is a cache key added atomically). The job (3 tries, 30 s then 120 s pause) calls `Rebuild::ping()`: an empty `POST` to the hook, 10 s timeout, header `X-Rebuild-Secret` when a secret is set. The result and a short error (the hook address and the secret are stripped from it) go to the cache for the Website tab. `POST /admin/website/rebuild` and `php artisan shop:rebuild` call the same `ping()` at once. Settings and the `secret` type: [SETTINGS.md](SETTINGS.md).
* **SEO fields**: product cards and details carry `updated_at`, `availability` and the picture size (`MediaDimensions` reads it once and keeps it in the media row); the detail adds `seo`, `breadcrumbs` and the sizes of every picture; categories and sub-categories carry their meta fields. [STOREFRONT-API.md](STOREFRONT-API.md).
* **CORS**: `FRONTEND_URLS` plus the optional `FRONTEND_URL_PATTERNS` (regular expressions, e.g. for `*.pages.dev` previews); `X-Cart-Token` is exposed.

## Security and limits

* **Access.** Two admin accounts, two roles, a permission per section and per Settings tab ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)). A missing permission answers `403 {"message":"You do not have permission to do this."}` – checked before the record is looked up, so nothing leaks. A test walks every admin route and fails if one has no `admin.can` middleware. The developer can never hold `orders.*` (enforced on write and on every check).
* **Admin tokens expire.** Admin API tokens live `ADMIN_TOKEN_MINUTES` (default 12 hours; the panel uses the session cookie, `SESSION_LIFETIME`); 0 = never. `sanctum:prune-expired` runs daily.
* **Passwords** are hashed with bcrypt (`BCRYPT_ROUNDS`, default 12) through the model's `hashed` cast and are never returned by the API or written to the audit log.
* **Public endpoints without login** are protected by validation (phone shape, field lengths, at most `SHOP_MAX_ITEMS_PER_ORDER` lines and `SHOP_MAX_QTY_PER_LINE` units per line), a honeypot field, and rate limits: quote 60/min per IP; orders 10/min and 60/hour per IP and 10/hour per phone; order lookup 20/min per IP and 15/hour per order number; cart (every call) 60/min per IP; coupon check 30/min per IP; payment proof upload 10/min per IP and 10/hour per order number; wholesale form 5/hour per IP and 5/hour per phone number (`WHOLESALE_PER_HOUR`, `WHOLESALE_PER_PHONE_HOUR`); public reads 240/min per IP. Admin API 600/min per admin, login 8/min per name + IP, test e-mail 5/min. A test checks that every store and admin route has a limit. The proof upload checks the order and the phone before it validates the file, so a wrong phone is the same `404` as an unknown order whatever the file is.
* **CORS** allows only the origins of `FRONTEND_URLS` (never `*`; credentials are enabled for the admin panel session).
* **Debug is off in production.** `APP_DEBUG` defaults to false and is forced off when `APP_ENV=production` (a warning is logged); error answers carry no trace. `SESSION_SECURE_COOKIE` defaults to on in production; session cookies are HttpOnly and SameSite=lax.
* `Model::preventLazyLoading()` is on outside production; list endpoints are tested for a fixed number of queries.
* Known gaps: no captcha (honeypot and rate limits only); no online card payments; no customer accounts. See [FEATURES.md](FEATURES.md).
