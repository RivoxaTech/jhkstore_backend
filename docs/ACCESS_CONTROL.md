# Access control

There are exactly **two admin accounts** and **two roles**. Nobody else can log in: customers have no account, and there is no screen to create more admins.

| Role | Who | Starts with |
|---|---|---|
| `developer` | the team that builds and runs the site | everything **except orders** (no orders screen, no order API, no order number, total or customer detail anywhere, dashboard included). The customers list and the wholesale requests are off by default (personal data) but can be switched on |
| `admin` | the client | the dashboard, orders (with the CSV export), the customers list (with export and abandoned carts), wholesale requests, discounts, coupons and seven Settings tabs: General, Footer & Social, Orders & Delivery, Store, Delivery, Payments, Notifications. The developer switches the rest on or off (the Theme tab can never be switched on for the admin) |

Both accounts are created by the seeder from `.env`: `SEED_DEVELOPER_USERNAME` / `SEED_DEVELOPER_PASSWORD` and `SEED_ADMIN_USERNAME` / `SEED_ADMIN_PASSWORD` (optional `_NAME` and `_EMAIL`). In production the seeder refuses a password shorter than 12 characters or equal to the username, and refuses identical usernames. Running the seeder again never changes an existing password or the permissions the developer set.

## Permissions

| Permission | Allows | Admin default | Developer default |
|---|---|---|---|
| `dashboard.view` | the dashboard | ✔ | ✔ |
| `products.view` / `products.manage` | products and tags: see / create, edit, delete, pictures | – | ✔ |
| `categories.view` / `categories.manage` | categories and sub-categories: see / create, edit, delete | – | ✔ |
| `orders.view` / `orders.manage` | orders: see / change status (including reject), notes, bank transfer verification (`payment/verify`, `payment/reject-proof`), delivery fee confirmation. `orders.view` includes the printable invoice and packing slip, the payment proof picture, the sidebar count (`orders/pending-count`), the WhatsApp button, order e-mails to the owner and order push notifications | ✔ | **never** |
| `orders.export` | the orders CSV download (`orders/export`, same filters as the list, audited) | ✔ | **never** |
| `wholesale.view` / `wholesale.manage` | wholesale requests (personal data): see, the sidebar count, dashboard figure and push / change status, write notes, CSV download | ✔ | off, can be switched on |
| `discounts.view` / `discounts.manage` | the Discounts screen: see / create, edit, activate, delete | ✔ | ✔ (fixed) |
| `coupons.view` / `coupons.manage` | the Coupons screen: see, usage list / create, edit, activate, delete | ✔ | ✔ (fixed) |
| `customers.view` / `customers.export` | the customers list and the abandoned carts tab / the CSV downloads of both | ✔ | off, can be switched on |
| `settings.general` | Settings → General (store name, contact details) | ✔ | ✔ |
| `settings.branding` | Settings → Branding (logo, favicon) | – | ✔ |
| `settings.banners` | Settings → Banners (create, edit, delete, reorder, activate, placements, pictures) | – | ✔ |
| `settings.footer` | Settings → Footer & Social | ✔ | ✔ |
| `settings.currency_tax` | Settings → Currency & Tax | – | ✔ |
| `settings.orders_delivery` | Settings → Orders & Delivery | ✔ | ✔ |
| `settings.seo` | Settings → SEO | – | ✔ |
| `settings.theme` | Settings → Theme (colours, fonts, corner radius, shadow) | **never** | ✔ (fixed) |
| `settings.store` | Settings → Store (open / closed, notices) | ✔ | ✔ |
| `settings.delivery` | Settings → Delivery (method, fees, the delivery zone manager) | ✔ | ✔ |
| `settings.payments` | Settings → Payments (methods, bank accounts) | ✔ | ✔ |
| `settings.notifications` | Settings → Notifications (e-mail and push switches, test e-mail, queue status) | ✔ | ✔ |
| `access.manage` | the Access Control screen | **never** | ✔ (fixed) |

The list lives in `App\Domain\Auth\AdminAccess` (`GROUPS`); the admin's starting list is `ADMIN_DEFAULTS`. Adding a Settings tab later = one entry in `SettingController::TABS`, its setting definitions in `Settings::DEFINITIONS`, and a `settings.<tab>` line in `AdminAccess::GROUPS`; the Access Control screen, the API checks and the tab list of the panel follow from those. When an update adds a permission, `AdminAccess::sync()` (the seeder) creates it and gives it to a role that already exists **only if it is in that role's defaults**; what the developer switched is never overridden.

## Access Control screen (developer only)

Sidebar → **Access Control**. One switch per permission for the `admin` role, grouped by section (a group can be switched on or off as a whole), and the developer's own customers-list switches. Saving writes the role in the spatie tables, so the change is effective on the admin's next request (no deployment, no re-login).

API: `GET /api/v1/admin/access-control` (the matrix) and `PUT /api/v1/admin/access-control/{admin|developer}` with `{ "permissions": ["products.view", …] }` (the full list of switches that are on).

## Hard rules

Enforced in the backend, on every write **and** on every check, and covered by tests:

* The developer can never hold `orders.*`. `PUT access-control/developer` with an orders permission answers 422; a permission row inserted by hand into the database is ignored by `AdminAccess::allows()`.
* `access.manage` and `settings.theme` belong to the developer only; asking to give either to the admin answers 422, and a row inserted by hand is ignored on every check (the Theme tab is not listed for the admin, `PUT /settings` with a theme key answers 403).
* The developer's other permissions are fixed on; only the personal data permissions (`customers.view`, `customers.export`, `wholesale.view`, `wholesale.manage`; `AdminAccess::DEVELOPER_OPTIONAL`) can be changed for the developer.
* Every order-related endpoint added later is `orders.*` too, so the developer is blocked (403 before the record is looked up) from the payment proof picture, `payment/verify`, `payment/reject-proof`, `delivery-fee`, rejecting an order and the new order list filters. `GET /admin/coupons/{id}/usages` is a coupon endpoint, but it only sends the order number, order id and phone to an admin who holds `orders.view`; the developer gets dates, amounts and whether the use was given back.
* Revenue figures (dashboard) leave out cancelled and rejected orders.
* The dashboard has one section per permission: `orders.view` (KPI cards, chart, top sellers, newest orders), `products.view` (low stock, product counts), `wholesale.view`, `customers.view`. It sends **no** order figures, revenue or order numbers to an admin without `orders.view`, and its activity list leaves out the order, customer and wholesale entries the admin may not see. The developer account gets the catalogue widgets only.
* **Push notifications**: the recipients are chosen when the job runs, among subscribed active admins who hold `orders.view` (new order, payment proof) or `wholesale.view` (wholesale request). The developer can never hold `orders.view`, so never gets an order notification; the push endpoints (`push/status`, `push/subscribe`, `push/unsubscribe`) need `orders.view` or `wholesale.view`.
* **E-mails**: order e-mails go to the customer, alerts to the owner address in `notify_admin_email` (not an account). The developer can open the Notifications tab (settings only) but sees no order content; the queue status shows counts only.
* The orders CSV (`orders.export`), the wholesale CSV (`wholesale.manage`) and the abandoned carts CSV (`customers.export`) are audited and refused with 403 before anything is read.

## Enforcement

* **API (403)**: every admin route except login, logout and `me` carries `actor:admin` and `admin.can:<permission>`. The permission is checked **before** the record is looked up, so a forbidden call is 403 whether or not the record exists. A test walks every admin route and fails if one has no permission check, and calls an endpoint of every permission as each role.
* **Settings**: `GET /admin/settings` lists only the tabs the admin may open and only their values; `PUT /admin/settings` and the picture endpoints check the tab of every key they touch (403 if any is not allowed).
* **Panel**: `GET /admin/auth/me` returns the effective `permissions`. The sidebar items, the Settings tabs and the buttons without permission are hidden; opening a URL without permission shows the "no access" page.

## Audit log

`audit_logs` records every admin change: settings, products, categories, tags, banners, order status and notes, and **permission changes** (`access.updated` with the role and the lists of granted and revoked permissions, subject = the role) and customer exports (`customers.exported`). Phase A adds: `order.rejected`, `order.payment_verified`, `order.payment_proof_rejected`, `order.payment_proof_submitted` (by the customer), `order.delivery_fee_set`, `discount.created` / `updated` / `toggled` / `deleted`, `coupon.created` / `updated` / `toggled` / `deleted`, `delivery_zone.created` / `updated` / `toggled` / `reordered` / `deleted`. Phase B adds: `orders.exported`, `wholesale.updated`, `wholesale.exported`, `customers.abandoned_carts_exported`, `notifications.test_email`, `product.sold_out_changed`, `product.featured_changed`, `product.video_added`, `product.video_deleted`, `product.video_poster_changed`, `product.videos_reordered`.
