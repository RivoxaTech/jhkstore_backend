# Handover: every feature on one page

Neutral Laravel 13 + Vue 3 store, **one deployment per client**, API first, separate storefront, guest checkout (no customer accounts), two admin accounts (`developer` never sees orders; `admin` is the client). Paths are relative to the repository root. Rule of the code: controller → FormRequest → Action (`app/Domain/*/Actions`) → Eloquent → Resource; prices only in `app/Domain/Cart/CartPricing.php`. Details: [ARCHITECTURE.md](ARCHITECTURE.md), [ACCESS_CONTROL.md](ACCESS_CONTROL.md), [SETTINGS.md](SETTINGS.md).

| Feature | Where it lives | Permission (admin screen / API) |
|---|---|---|
| Access control, two accounts | `app/Domain/Auth/AdminAccess.php`, `AccessControlController`, `AccessControlView.vue` | `access.manage` (developer only) |
| Audit log | `app/Support/Audit.php`, table `audit_logs` | written by every admin change |
| Settings and tabs | `app/Domain/Support/Settings.php`, `SettingController`, `SettingsView.vue` | `settings.<tab>` per tab |
| Theme (colours, fonts, radius, shadow) | `Settings::DEFINITIONS` group `theme`, `ThemePreview.vue`, public `GET /store/settings` | `settings.theme` (developer only) |
| Store open / closed, notices | group `store`, `PlaceOrder::checkStoreAndPayment` | `settings.store` |
| Time zone | `.env` `APP_TIMEZONE`, `app/Support/ShopTime.php`, `AppServiceProvider` (dates with offset), `admin-frontend/src/utils/format.js` | none (`/admin/auth/me` sends it) |
| Banners | `app/Domain/Content`, `BannerController`, `BannersPanel.vue` | `settings.banners` |
| Categories, sub-categories | `app/Domain/Catalog`, `CategoryController`, `CategoriesView.vue` | `categories.view` / `.manage` |
| Products, options, stock, bulk prices, tags | `app/Domain/Catalog/Actions/SaveProduct.php`, `ProductController`, `ProductsView.vue`, `ProductFormView.vue`, `OptionsTable.vue` | `products.view` / `.manage` |
| Sold out, featured | `SaveProduct::setSoldOut/setFeatured`, `PATCH products/{id}/sold-out`, `/featured`; `CartPricing` blocks sold out | `products.manage` |
| Product video | `ManageProductVideos`, `ProductVideos`, `VideoManager.vue`, `config/shop.php` `video` | `products.manage` |
| Delivery methods and zones | `app/Domain/Delivery`, `DeliveryZoneController`, `DeliveryZonesPanel.vue` | `settings.delivery` |
| Payments: COD, bank transfer, proof | `HandlePaymentProof`, `OrderController::proof`, private disk, `payments.bank_snapshot` | `settings.payments`; `orders.view` / `.manage` |
| Discounts, coupons | `app/Domain/Promotion`, `DiscountController`, `CouponController`, `PromotionList.vue` | `discounts.*`, `coupons.*` |
| Persistent cart | `app/Domain/Cart/Actions/ManageCart.php`, `Store\CartController`, `shop:prune-carts` | public (`X-Cart-Token`) |
| Guest checkout, order flow, reject, fee | `PlaceOrder`, `ChangeOrderStatus`, `ConfirmDeliveryFee`, `OrderController`, `OrderDetailView.vue` | `orders.view` / `orders.manage` |
| Order tracking (number + phone) | `Store\OrderController::show`, `StoreOrderResource` | public |
| Dashboard | `app/Domain/Support/Dashboard.php`, `DashboardController`, `DashboardView.vue`, `RevenueChart.vue` | `dashboard.view`; each section needs its own (`orders.view`, `products.view`, `wholesale.view`, `customers.view`) |
| Sidebar badges (30 s) | `OrderController::pendingCount`, `WholesaleController::newCount`, `AdminLayout.vue` | `orders.view`, `wholesale.view` |
| Wholesale requests | `app/Domain/Wholesale`, `Store\WholesaleController`, `Admin\WholesaleController`, `WholesaleView.vue` | `wholesale.view` / `.manage` (developer: off, switchable) |
| Order e-mails and owner alerts | `app/Mail`, `resources/views/mail`, `app/Domain/Notification/Notifier.php`, `NotificationsPanel.vue` (events table: customer e-mail, owner alert, push per event) | `settings.notifications` |
| Setting tiers (client / advanced) | `Settings::access()`, `ADVANCED_KEYS` / `ADVANCED_GROUPS`, `SettingController::mayUse()`, `SettingField.vue` (Developer tag) | tab permission **and** `settings.advanced` for advanced fields ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)) |
| Notification status, test push, queue worker state | `QueueHealth`, `PushLog`, `SendTestPush`, `NotificationStatusCard.vue`, `shop:queue-heartbeat`, [PUSH_TEST.md](PUSH_TEST.md) | `settings.notifications` / `orders.view` or `wholesale.view` |
| Alert chime and mute | `utils/chime.js`, `AdminLayout.vue`, `public/admin-sw.js` | `orders.view` or `wholesale.view` |
| Store logo in the panel | `BrandLogo.vue`, `stores/site.js` (favicon, document title) | public settings |
| Queue | `QUEUE_CONNECTION=database`, `jobs` / `failed_jobs`, worker or cron ([NEW_CLIENT.md](NEW_CLIENT.md)) | counts in `GET /admin/notifications/status` |
| Admin push | `SendPushNotification`, `WebPushSender`, `PushController`, `PushToggle.vue`, `public/admin-sw.js`, `php artisan shop:vapid` | `orders.view` or `wholesale.view` (recipients checked when the job runs) |
| WhatsApp buttons | built in the panel: `whatsappUrl()` in `format.js` (order, wholesale, abandoned carts) | follows the screen |
| CSV exports | `app/Support/Csv.php`; orders `OrderController::export`, wholesale, customers, abandoned carts | `orders.export` (never developer), `wholesale.manage`, `customers.export` |
| Customers, abandoned carts | `app/Domain/Customer`, `app/Domain/Cart/AbandonedCarts.php`, `CustomerController`, `CustomersView.vue` | `customers.view` / `customers.export` (developer: off, switchable) |
| Low stock | dashboard widget (`Dashboard::stock`), products filter `?stock=low`, alert level per option | `products.view` |
| Rate limits, honeypot | `AppServiceProvider`, `config/shop.php`, `RejectHoneypot` | none |
| API docs | `docs/openapi.json` (scramble), `docs/postman/`, [STOREFRONT-API.md](STOREFRONT-API.md) | none |

## Add a new client

1. Copy the repository (fork or template), name the database, set `.env` (`APP_NAME`, `APP_URL`, `APP_TIMEZONE`, `DB_*`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS`, `SEED_*`, `MAIL_*`, `VAPID_*`).
2. `composer install --no-dev`, `php artisan migrate --seed`, `php artisan storage:link`, `cd admin-frontend && npm install && npm run build`.
3. Cron `* * * * * php artisan schedule:run`; a queue worker (`queue:work --tries=3`) or the cron `queue:work --stop-when-empty --max-time=55`.
4. Raise PHP `upload_max_filesize` / `post_max_size` for product videos; keep `storage/app/private` out of the web root.
5. Log in as the developer, fill the Settings tabs in the order of [NEW_CLIENT.md](NEW_CLIENT.md), set Access Control for the client, add the catalogue, give the storefront developer the API docs.
6. Go through the go-live checklist at the end of NEW_CLIENT.md. Never run `migrate:fresh` on a live site.

## Add a setting, a tab, a permission

- **Setting**: one line in `Settings::DEFINITIONS` (group, type, default, label, help); a rule override in `Settings::rules()` if the type is not enough; its tier (`client` unless listed in `Settings::ADVANCED_KEYS` or its group in `ADVANCED_GROUPS`) and a row in the table of `docs/ACCESS_CONTROL.md`; it shows up in the panel by itself. Public settings are listed in `Store\SettingController`.
- **Settings tab**: an entry in `SettingController::TABS` (+ `SECTIONS`), a `settings.<tab>` line in `AdminAccess::GROUPS`, the tab's keys in `DEFINITIONS`, and the permission in the `admin.can:` list of the two settings routes in `routes/api_admin.php`. Custom content below the fields = a component like `DeliveryZonesPanel.vue`.
- **Permission**: a line in `AdminAccess::GROUPS`, the default in `ADMIN_DEFAULTS` (and `DEVELOPER_OPTIONAL` / `forbidden()` for the hard rules), `admin.can:<permission>` on the route (a test fails when a route has none), the matrix row in `tests/Feature/AccessControlTest.php`, a line in [ACCESS_CONTROL.md](ACCESS_CONTROL.md). Anything about orders is `orders.*`, so the developer is blocked by the hard rule.
- **Every feature** ships with tests, OpenAPI / Postman / STOREFRONT-API updates and a CHANGELOG line.

True gaps: no captcha (honeypot and rate limits only), no online card payments, no customer accounts.
