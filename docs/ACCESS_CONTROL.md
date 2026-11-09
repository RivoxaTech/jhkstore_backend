# Access control

There are exactly **two admin accounts** and **two roles**. Nobody else can log in: customers have no account, and there is no screen to create more admins.

| Role | Who | Starts with |
|---|---|---|
| `developer` | the team that builds and runs the site | everything **except orders** (no orders screen, no order API, no order number, total or customer detail anywhere, dashboard included). The customers list and the wholesale requests are off by default (personal data) but can be switched on |
| `admin` | the client | the dashboard, orders (with the CSV export), the customers list (with export and abandoned carts), wholesale requests, discounts, coupons and five Settings tabs: General (contact details only), Store, Delivery, Payments, Notifications (which e-mails and push messages go out). **No technical settings**: those need `settings.advanced`, which the developer can switch on for a technical client. The developer switches the rest on or off (the Theme tab can never be switched on for the admin) |

Both accounts are created by the seeder from `backend/.env`: `SEED_DEVELOPER_USERNAME` / `SEED_DEVELOPER_PASSWORD` and `SEED_ADMIN_USERNAME` / `SEED_ADMIN_PASSWORD` (optional `_NAME` and `_EMAIL`). In production the seeder refuses a password shorter than 12 characters or equal to the username, and refuses identical usernames. Running the seeder again never changes an existing password or the permissions the developer set.

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
| `settings.general` | Settings → General: contact details (e-mail, phone, address, support hours, WhatsApp). Store name, tagline, country and phone country code need `settings.advanced` | ✔ | ✔ |
| `settings.branding` | Settings → Branding (logo, favicon; technical) | – | ✔ |
| `settings.banners` | Settings → Banners (create, edit, delete, reorder, activate, placements, pictures; technical, also needs `settings.advanced`) | – | ✔ |
| `settings.footer` | Settings → Footer & Social (links, footer texts, agency credit; technical) | – | ✔ |
| `settings.currency_tax` | Settings → Currency & Tax (technical) | – | ✔ |
| `settings.orders_delivery` | Settings → Orders & Delivery (order number prefix, cash on delivery limit; technical) | – | ✔ |
| `settings.seo` | Settings → SEO (technical) | – | ✔ |
| `settings.theme` | Settings → Theme (colours, fonts, corner radius, shadow) | **never** | ✔ (fixed) |
| `settings.store` | Settings → Store (open / closed, closed message, checkout notice) | ✔ | ✔ |
| `settings.delivery` | Settings → Delivery (method, fee, free-delivery limit, minimum order, delivery text, the zones table). Delivery time in days and the unmatched-address rule need `settings.advanced` | ✔ | ✔ |
| `settings.payments` | Settings → Payments (methods, bank accounts) | ✔ | ✔ |
| `settings.notifications` | Settings → Notifications: the events table (customer e-mail, owner e-mail, admin push), test e-mail, notification status. Sender name, reply-to, owner address and tracking link need `settings.advanced` | ✔ | ✔ |
| `settings.website` | Settings → Website: the status of the website rebuild (pending changes, last request, last result), the **Rebuild website now** button and the automatic-rebuild switch. The hook address, hook secret and the wait time are technical and need `settings.advanced` | ✔ | ✔ |
| `settings.advanced` | The technical fields of every tab the user may open, see the map below. Also the failed-jobs list and the queue details of the notification status | **off**, can be switched on | ✔ (fixed) |
| `access.manage` | the Access Control screen | **never** | ✔ (fixed) |

The list lives in `App\Domain\Auth\AdminAccess` (`GROUPS`); the admin's starting list is `ADMIN_DEFAULTS`. Adding a Settings tab later = one entry in `SettingController::TABS`, its setting definitions in `Settings::DEFINITIONS`, and a `settings.<tab>` line in `AdminAccess::GROUPS`; the Access Control screen, the API checks and the tab list of the panel follow from those. When an update adds a permission, `AdminAccess::sync()` (the seeder) creates it and gives it to a role that already exists **only if it is in that role's defaults**; what the developer switched is never overridden.

## Who sees which setting (tiers)

Every setting has a **tier** (`Settings::access()`; the lists are `Settings::ADVANCED_KEYS` and `Settings::ADVANCED_GROUPS`):

* `client`: the admin may get it by default (contact details, store open or closed, delivery method and fees, payment switches, which e-mails and push messages go out).
* `advanced`: technical. Shown in the panel with a small "Developer" tag.

A field is **visible and writable only if the user has the permission of its tab AND (the field is `client` OR the user holds `settings.advanced`)**. The API enforces it on `GET /admin/settings` (the field, its value and its tab are simply not sent) and on `PUT /admin/settings` and the picture endpoints (403 for any forbidden key; a request that mixes allowed and forbidden keys changes nothing). A tab with none of its fields visible is hidden (the Banners tab holds the banner manager, which is technical: its routes need `settings.banners` **and** `settings.advanced`). `settings.theme` stays developer only whatever else is switched on. The developer's `settings.advanced` is fixed on; the admin's starts off. `AdminAccess::sync()` on an existing database creates the new permission, gives it to the developer, and never changes what the developer switched for the admin.

The Access Control screen lists, under every permission, what it covers (screens, tabs, the individual settings in a collapsible list) and a tag: `Client` or `Developer / advanced`. The data comes from `GET /admin/access-control` (`tier`, `covers`, `fields` on every permission), built from the same definitions the API enforces.

### The map: setting → tier → permission

Generated from `Settings::DEFINITIONS` (a test checks that every setting has a row). "Permission" is the tab permission; an `advanced` row also needs `settings.advanced`.

| Setting key | Label | Tab | Tier | Permission |
|---|---|---|---|---|
| `store_name` | Store name | General | advanced | `settings.general` + `settings.advanced` |
| `store_tagline` | Tagline | General | advanced | `settings.general` + `settings.advanced` |
| `store_email` | Contact email | General | client | `settings.general` |
| `store_phone` | Contact phone | General | client | `settings.general` |
| `store_address` | Address | General | client | `settings.general` |
| `store_country` | Country | General | advanced | `settings.general` + `settings.advanced` |
| `default_phone_country_code` | Default phone country code | General | advanced | `settings.general` + `settings.advanced` |
| `support_hours` | Support hours | General | client | `settings.general` |
| `whatsapp_number` | WhatsApp number | General | client | `settings.general` |
| `store_logo` | Logo | Branding | advanced | `settings.branding` + `settings.advanced` |
| `store_favicon` | Favicon | Branding | advanced | `settings.branding` + `settings.advanced` |
| `social_links` | Social and footer links | Footer & Social | advanced | `settings.footer` + `settings.advanced` |
| `footer_text` | Footer text | Footer & Social | advanced | `settings.footer` + `settings.advanced` |
| `footer_copyright` | Copyright text | Footer & Social | advanced | `settings.footer` + `settings.advanced` |
| `agency_name` | Agency name | Footer & Social | advanced | `settings.footer` + `settings.advanced` |
| `agency_url` | Agency link | Footer & Social | advanced | `settings.footer` + `settings.advanced` |
| `agency_logo` | Agency logo | Footer & Social | advanced | `settings.footer` + `settings.advanced` |
| `seo_meta_title` | Default meta title | SEO | advanced | `settings.seo` + `settings.advanced` |
| `seo_meta_description` | Default meta description | SEO | advanced | `settings.seo` + `settings.advanced` |
| `seo_share_image` | Default share image | SEO | advanced | `settings.seo` + `settings.advanced` |
| `currency_code` | Currency code | Currency & Tax | advanced | `settings.currency_tax` + `settings.advanced` |
| `currency_symbol` | Currency symbol | Currency & Tax | advanced | `settings.currency_tax` + `settings.advanced` |
| `currency_decimals` | Decimals | Currency & Tax | advanced | `settings.currency_tax` + `settings.advanced` |
| `tax_rate` | Default tax rate (%) | Currency & Tax | advanced | `settings.currency_tax` + `settings.advanced` |
| `tax_inclusive` | Prices include tax | Currency & Tax | advanced | `settings.currency_tax` + `settings.advanced` |
| `tax_label` | Tax label | Currency & Tax | advanced | `settings.currency_tax` + `settings.advanced` |
| `order_number_prefix` | Order number prefix | Orders & Delivery | advanced | `settings.orders_delivery` + `settings.advanced` |
| `cod_max_amount` | Cash on delivery limit | Orders & Delivery | advanced | `settings.orders_delivery` + `settings.advanced` |
| `store_open` | Store is open | Store | client | `settings.store` |
| `store_closed_message` | Message while the store is closed | Store | client | `settings.store` |
| `checkout_notice` | Checkout notice | Store | client | `settings.store` |
| `announcement_enabled` | Announcement bar | Store | client | `settings.store` |
| `announcement_text` | Announcement text | Store | client | `settings.store` |
| `delivery_method` | Delivery method | Delivery | client | `settings.delivery` |
| `delivery_fee` | Delivery charge | Delivery | client | `settings.delivery` |
| `free_delivery_min_cart` | Free delivery from | Delivery | client | `settings.delivery` |
| `delivery_days` | Delivery time (days) | Delivery | advanced | `settings.delivery` + `settings.advanced` |
| `delivery_unmatched` | Address outside every zone | Delivery | advanced | `settings.delivery` + `settings.advanced` |
| `min_order_amount` | Minimum order amount | Delivery | client | `settings.delivery` |
| `delivery_eta_text` | Delivery estimate text | Delivery | client | `settings.delivery` |
| `pay_cod_enabled` | Cash on delivery | Payments | client | `settings.payments` |
| `pay_bank_enabled` | Bank transfer | Payments | client | `settings.payments` |
| `pay_bank_proof_required` | Require the payment screenshot | Payments | client | `settings.payments` |
| `pay_card_visible` | Show "Card payment, coming soon" | Payments | client | `settings.payments` |
| `pay_bank_instructions` | Bank transfer instructions | Payments | client | `settings.payments` |
| `pay_bank_accounts` | Bank accounts | Payments | client | `settings.payments` |
| `mail_enabled` | Send e-mails | Notifications | client | `settings.notifications` |
| `mail_from_name` | Sender name | Notifications | advanced | `settings.notifications` + `settings.advanced` |
| `mail_reply_to` | Reply-to address | Notifications | advanced | `settings.notifications` + `settings.advanced` |
| `notify_admin_email` | Owner e-mail for alerts | Notifications | advanced | `settings.notifications` + `settings.advanced` |
| `mail_on_placed` | E-mail: order received | Notifications | client | `settings.notifications` |
| `mail_on_confirmed` | E-mail: order confirmed | Notifications | client | `settings.notifications` |
| `mail_on_shipped` | E-mail: order shipped | Notifications | client | `settings.notifications` |
| `mail_on_delivered` | E-mail: order delivered | Notifications | client | `settings.notifications` |
| `mail_on_cancelled` | E-mail: order cancelled or rejected | Notifications | client | `settings.notifications` |
| `mail_on_payment_proof_rejected` | E-mail: payment proof rejected | Notifications | client | `settings.notifications` |
| `mail_on_fee_confirmed` | E-mail: delivery fee confirmed | Notifications | client | `settings.notifications` |
| `owner_mail_on_placed` | Owner e-mail: new order | Notifications | client | `settings.notifications` |
| `owner_mail_on_proof` | Owner e-mail: payment proof uploaded | Notifications | client | `settings.notifications` |
| `owner_mail_on_wholesale` | Owner e-mail: new wholesale request | Notifications | client | `settings.notifications` |
| `push_on_placed` | Push: new order | Notifications | client | `settings.notifications` |
| `push_on_proof` | Push: payment proof uploaded | Notifications | client | `settings.notifications` |
| `push_on_wholesale` | Push: new wholesale request | Notifications | client | `settings.notifications` |
| `storefront_track_url` | Order tracking page | Notifications | advanced | `settings.notifications` + `settings.advanced` |
| `push_enabled` | Admin push notifications | Notifications | client | `settings.notifications` |
| `theme_primary` | Primary colour | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_primary_contrast` | Text on the primary colour | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_accent` | Accent colour | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_bg` | Page background | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_surface` | Card background | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_text` | Text colour | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_muted` | Muted text colour | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_border` | Border colour | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_font_heading` | Heading font | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_font_body` | Body font | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_radius` | Corner radius | Theme | advanced | `settings.theme` + `settings.advanced` |
| `theme_shadow` | Shadow | Theme | advanced | `settings.theme` + `settings.advanced` |
| `rebuild_enabled` | Rebuild the website automatically | Website | client | `settings.website` |
| `rebuild_hook_url` | Rebuild hook address | Website | advanced | `settings.website` + `settings.advanced` |
| `rebuild_hook_secret` | Rebuild hook secret (stored encrypted, never returned, shown only as set / not set) | Website | advanced | `settings.website` + `settings.advanced` |
| `rebuild_debounce_seconds` | Wait after the last change (seconds) | Website | advanced | `settings.website` + `settings.advanced` |


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
* **Push notifications**: the recipients are chosen when the job runs, among subscribed active admins who hold `orders.view` (new order, payment proof) or `wholesale.view` (wholesale request). The developer can never hold `orders.view`, so never gets an order notification; the push endpoints (`push/status`, `push/subscribe`, `push/unsubscribe`, `push/test`) need `orders.view` or `wholesale.view`.
* **E-mails**: order e-mails go to the customer, alerts to the owner address in `notify_admin_email` (not an account). The developer can open the Notifications tab (settings only) but sees no order content; the queue status shows counts only.
* The orders CSV (`orders.export`), the wholesale CSV (`wholesale.manage`) and the abandoned carts CSV (`customers.export`) are audited and refused with 403 before anything is read.

## Enforcement

* **API (403)**: every admin route except login, logout and `me` carries `actor:admin` and `admin.can:<permission>`. The permission is checked **before** the record is looked up, so a forbidden call is 403 whether or not the record exists. A test walks every admin route and fails if one has no permission check, and calls an endpoint of every permission as each role.
* **Settings**: `GET /admin/settings` lists only the tabs the admin may open and only their values; `PUT /admin/settings` and the picture endpoints check the tab of every key they touch (403 if any is not allowed).
* **Panel**: `GET /admin/auth/me` returns the effective `permissions`. The sidebar items, the Settings tabs and the buttons without permission are hidden; opening a URL without permission shows the "no access" page.

## Audit log

`audit_logs` records every admin change: settings, products, categories, tags, banners, order status and notes, and **permission changes** (`access.updated` with the role and the lists of granted and revoked permissions, subject = the role) and customer exports (`customers.exported`). Phase A adds: `order.rejected`, `order.payment_verified`, `order.payment_proof_rejected`, `order.payment_proof_submitted` (by the customer), `order.delivery_fee_set`, `discount.created` / `updated` / `toggled` / `deleted`, `coupon.created` / `updated` / `toggled` / `deleted`, `delivery_zone.created` / `updated` / `toggled` / `reordered` / `deleted`. Phase B adds: `orders.exported`, `wholesale.updated`, `wholesale.exported`, `customers.abandoned_carts_exported`, `notifications.test_email`, `product.sold_out_changed`, `product.featured_changed`, `product.video_added`, `product.video_deleted`, `product.video_poster_changed`, `product.videos_reordered`.
