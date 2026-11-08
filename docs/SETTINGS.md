# Settings reference

Store settings live in the key/value `settings` table and are read through one service, `App\Domain\Support\Settings`. A missing row falls back to the
default below, so a fresh install works without any setup; `SettingsSeeder` writes the defaults (and never overwrites a value an admin changed).

* **Read**: `app(Settings::class)->get('delivery_fee')` – typed, cached (one cache entry plus a per-request copy).
* **Write**: `app(Settings::class)->set(['delivery_fee' => 5])` – clears the cache. (The service does not validate; the admin API validates by type.)
* **Admin panel**: *Settings*, built from the list of definitions, so a new setting appears automatically. Thirteen tabs:
  **General**, **Branding**, **Banners**, **Footer & Social**, **Currency & Tax**, **Orders & Delivery**, **Delivery**, **Payments**, **Store**, **Notifications**, **Theme**, **SEO**, **Website** (the tab → section map is `SettingController::TABS`). **Banners** has no settings: it holds the banner manager (`/admin/banners` endpoints). **Delivery** has its settings and the delivery zone manager (`/admin/delivery-zones` endpoints). **Notifications** holds the e-mail and push switches and, below them, the mail / queue status (counts only) and a "send test e-mail" button. **Theme** is the developer's: the admin never gets it. Every tab has its own permission `settings.<tab>` (`settings.general`, `settings.branding`, `settings.banners`, `settings.footer`, `settings.currency_tax`, `settings.orders_delivery`, `settings.delivery`, `settings.payments`, `settings.store`, `settings.notifications`, `settings.theme`, `settings.seo`, `settings.website`): the developer switches them for the admin in Access Control ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)), the API lists only the tabs the admin may open and answers 403 for the others. `settings.theme` can never be given to the admin.
  **Tiers (Phase C)**: every setting is `client` or `advanced` (technical); an advanced field also needs the permission `settings.advanced` (developer: always; admin: off until the developer switches it on). A field is visible and writable only with the permission of its tab **and** its tier; a tab with no visible field is hidden. The full table (setting, tab, tier, permission) is in [ACCESS_CONTROL.md](ACCESS_CONTROL.md). The panel shows a small "Developer" tag next to advanced fields.
  API: `GET/PUT /api/v1/admin/settings`; pictures: `POST/DELETE /api/v1/admin/settings/{logo|favicon|share-image|agency-logo}`.
* **Time zone**: not a setting but `APP_TIMEZONE` in `backend/.env` (default `UTC`, set it before the first order). Dates are stored as shop time, the API sends them with the shop's offset, and the panel gets the zone name as `timezone` in `GET /admin/auth/me`. Every date typed in a settings form (banner, discount and coupon windows) is shop time.
* **Storefront**: `GET /api/v1/store/settings` – the public subset (store profile and branding, open / closed, checkout notice, footer, social links and agency credit, SEO defaults, currency, tax, delivery, payment methods, order limits, theme). See [STOREFRONT-API.md](STOREFRONT-API.md).
* **Add a setting**: add one line to `Settings::DEFINITIONS` (group, type, default, label, help), decide its tier (`Settings::ADVANCED_KEYS` / `ADVANCED_GROUPS`; the default is `client`), add its row to the table in [ACCESS_CONTROL.md](ACCESS_CONTROL.md) (a test fails without it) and regenerate the tables below.
* **Add a tab**: add the entry to `SettingController::TABS` (and a section label in `SECTIONS`), the settings with that group to `Settings::DEFINITIONS`, and a `settings.<tab>` permission to `AdminAccess::GROUPS`; the admin panel renders the tab from the API; nothing else changes. (The Delivery, Payments, Store, Notifications and Theme tabs were added this way.)

**Types**: `string`, `text`, `bool`, `int`, `money` / `percent` (numbers with 2 decimals), `file` (a picture, uploaded through its own endpoint), `links` (a JSON list), `accounts` (a JSON list of bank accounts), `color` (`#RRGGBB`) and `choice` (one value of a fixed list; the API sends the list as `options`) and `secret` (see below).

* **`file`** – the picture is stored with spatie medialibrary: the setting's row owns one file (single-file collection, disk `public`) and the setting value is the media id.
  A new upload replaces (and deletes) the old file. `Settings::fileUrl($key)` returns the absolute URL (cached with the settings). The file settings cannot be written through `PUT /admin/settings` (422).
  Accepted: logo PNG/JPG/WEBP ≤ 2 MB and ≤ 4000 px; favicon PNG/ICO/JPG/WEBP ≤ 512 KB; share image PNG/JPG/WEBP ≤ 4 MB and ≤ 6000 px. **SVG is rejected** on purpose (it can carry scripts).
* **`links`** – a list of `{platform, label, url, active}`; **the order of the list is the display order** (the admin panel has drag-to-reorder, move buttons, add and remove).
  `platform` is one of `facebook, instagram, linkedin, x, youtube, tiktok, whatsapp, pinterest, website, custom`; `label` is optional (the storefront falls back to the platform name);
  `url` must be a valid `http://` or `https://` address (≤ 500 characters – `javascript:` and other schemes are refused); `active` hides a link without deleting it.
  There is no fixed number of entries. The service casts the value by type (`Settings::get` always returns a clean list) and `Settings::rules()` holds the validation rules by type, used by the admin API.

* **`accounts`** – a list of `{bank, title, number, iban, active}`, **in display order**, any number of entries. `bank` is required (≤ 80), `title` ≤ 120, `number` and `iban` ≤ 40 and **at least one of them is required**; `active` hides an account without deleting it. Rows without a bank or without a number and IBAN are dropped by the service. Public: only the active accounts (`bank`, `title`, `number`, `iban`).
* **`secret`** – a value stored **encrypted** (`Crypt`, the `APP_KEY`) that no API ever returns: `Settings::all()` leaves it out, the admin settings answer sends `value: null` and `is_set: true / false` for it, the public settings and the audit log never hold it. `Settings::get()` decrypts it for server-side use only; `isSet()` says whether it exists. Writing an empty value clears it; a value that cannot be decrypted (the `APP_KEY` changed) counts as not set.
* **`color`** – `#RRGGBB` (case is kept as typed). **`choice`** – the value must be one of `Settings::CHOICES`: `delivery_method`, `delivery_unmatched`, `theme_font_heading` / `theme_font_body` (fonts: Inter, Poppins, Playfair Display, Plus Jakarta Sans, Roboto, Lato, system-ui), `theme_radius` (`0px`, `4px`, `8px`, `12px`, `16px`, `999px`), `theme_shadow` (`none`, `soft`, `medium`).
* **Rules over more than one key** (checked on save with the stored values merged in): at least one of cash on delivery and bank transfer stays on (`pay_cod_enabled` / `pay_bank_enabled` → 422), and bank transfer needs at least one active account (turning it on, or removing the last active account while it is on, → 422).

**`0` means off** for `min_order_amount`, `cod_max_amount`, `delivery_fee` (free delivery on every order) and `free_delivery_min_cart` (delivery never free).

**Delivery method** (`delivery_method`): `flat` – `delivery_fee` on every order; `free` – always 0; `free_above` (**the default**, the behaviour of earlier versions) – `delivery_fee` unless the items total after discounts reaches `free_delivery_min_cart`; `zones` – the fee of the first active zone (by `sort_order`) that matches the address, free above the zone's own amount, the zone's minimum order replaces `min_order_amount`; no address yet = fee `null` and `delivery_needs_address`; an address outside every zone follows `delivery_unmatched` (`block` = refuse, `owner_confirms`, `flat`); `owner_confirms` – the order is placed with the fee unknown and the admin sets it later. A free-delivery coupon sets the fee to 0 in every method.

**Tax rules**: a product's own `tax_rate` wins, otherwise `tax_rate` here. With *Prices include tax* on, the tax is part of the price (shown for
information); off, it is added to the cart total.

## Keys and defaults

### Store profile

Tier: contact e-mail, phone, address, support hours and WhatsApp are `client`; store name, tagline, country and the phone country code are `advanced`.

| Key | Type | Default | What it does |
|---|---|---|---|
| `store_name` | string | `JHK Store` | Store name |
| `store_tagline` | string | `(empty)` | Tagline |
| `store_email` | string | `(empty)` | Contact email |
| `store_phone` | string | `(empty)` | Contact phone |
| `store_address` | text | `(empty)` | Address |
| `store_country` | string | `(empty)` | Country. Used as the default country of addresses |
| `support_hours` | string | `(empty)` | Support hours (contact detail: `client`; it used to be in Footer & Social, the key is unchanged) |
| `whatsapp_number` | string | `(empty)` | WhatsApp number with country code (digits, `+`, spaces, `()`, `-`); the public API also gives a `https://wa.me/…` link (`client`, moved from Footer & Social) |
| `default_phone_country_code` | string | `(empty)` | Default phone country code, e.g. `+92` (`+` and 1–4 digits). A customer phone starting with a single `0` and no country code gets this prefix (the `0` is dropped), so `0300 1234567`, `+92 300 1234567` and `0092 300 1234567` are one customer. Empty = numbers are kept as typed. Applies to orders placed after the change; customers already stored keep their number |

### Branding (every setting `advanced`)

| Key | Type | Default | What it does |
|---|---|---|---|
| `store_logo` | file | `(none)` | Logo (admin panel, store, invoices). Upload: `POST /admin/settings/logo`, field `logo` |
| `store_favicon` | file | `(none)` | Favicon. Upload: `POST /admin/settings/favicon`, field `favicon` |

### Footer & Social (every setting `advanced`, including the agency credit)

| Key | Type | Default | What it does |
|---|---|---|---|
| `social_links` | links | `[]` | Social and footer links, in display order |
| `footer_text` | text | `(empty)` | Footer text (≤ 500 characters) |
| `footer_copyright` | string | `(empty)` | Copyright text |
| `agency_name` | string | `Rivoxa Tech` | The "Developed by" credit at the very bottom of the storefront and in the admin panel (≤ 80 characters). Empty = no credit |
| `agency_url` | string | `https://rivoxa.tech` | The agency's website, `https://` only. Public as `footer.agency { name, url, logo_url }` |
| `agency_logo` | file | `(none)` | The agency logo shown next to "Developed by" (the name is shown without it). Upload: `POST /admin/settings/agency-logo`, field `agency_logo`, PNG / JPG / WEBP up to 1 MB. The logo of Rivoxa Tech is in `design/rivoxa-logo.png` |

### SEO (every setting `advanced`)

| Key | Type | Default | What it does |
|---|---|---|---|
| `seo_meta_title` | string | `(empty)` | Default meta title (≤ 70 characters) |
| `seo_meta_description` | text | `(empty)` | Default meta description (≤ 300 characters) |
| `seo_share_image` | file | `(none)` | Default share image. Upload: `POST /admin/settings/share-image`, field `share_image` |

### Currency (every setting `advanced`)

| Key | Type | Default | What it does |
|---|---|---|---|
| `currency_code` | string | `USD` | Currency code. ISO code, e.g. USD, EUR, GBP |
| `currency_symbol` | string | `$` | Currency symbol |
| `currency_decimals` | int | `2` | Decimals. Decimal places shown and calculated (0, 1 or 2) |

### Tax (every setting `advanced`)

| Key | Type | Default | What it does |
|---|---|---|---|
| `tax_rate` | percent | `0` | Default tax rate (%). Used for products without their own rate. 0 = no tax |
| `tax_inclusive` | bool | `on` | Prices include tax. On: tax is already inside the prices. Off: tax is added at the cart |
| `tax_label` | string | `Tax` | Tax label. Shown to customers, e.g. VAT, Sales tax |

### Orders (Orders & Delivery tab, both `advanced`)

| Key | Type | Default | What it does |
|---|---|---|---|
| `order_number_prefix` | string | `ORD` | Order number prefix. Order numbers look like PREFIX-2026-000123 |
| `cod_max_amount` | money | `0` | Cash on delivery limit. 0 = no limit |

### Delivery (Delivery tab; the three money / days keys used to be in Orders & Delivery, their keys are unchanged)

| Key | Type | Default | What it does |
|---|---|---|---|
| `delivery_method` | choice | `free_above` | `flat`, `free`, `free_above`, `zones` or `owner_confirms` (see above) |
| `delivery_fee` | money | `0` | Delivery charge of the flat and free-above methods, and of unmatched addresses set to `flat`. 0 = free delivery |
| `free_delivery_min_cart` | money | `0` | Free delivery from (free-above method). Items total after discounts. 0 = never free |
| `delivery_days` | int | `5` | Delivery time (days). Shown to customers as the delivery estimate. `advanced` |
| `delivery_unmatched` | choice | `block` | Zones method, address outside every zone: `block` (422 `delivery_not_available`), `owner_confirms` (fee pending) or `flat` (`delivery_fee`). `advanced` |
| `min_order_amount` | money | `0` | Minimum order amount. 0 = no minimum (moved here from Orders & Delivery; the key is unchanged) |
| `delivery_eta_text` | string | `(empty)` | e.g. `2 to 4 days`, used when no zone has an ETA of its own (≤ 120 characters; moved here from Store) |

The zones themselves are rows of `delivery_zones`, managed in this tab (`/admin/delivery-zones`): name, what they match (`city`, `area` or `postcode`; case-insensitive, a postcode may end with `*` for a prefix, spaces ignored), the values, fee, optional free-above amount, optional minimum order, optional ETA text, order, active. Zone ETA text replaces `delivery_eta_text`.

### Store (Store tab)

| Key | Type | Default | What it does |
|---|---|---|---|
| `store_open` | bool | `on` | Off: customers can browse and get a quote, `POST /orders` answers 422 `store_closed` |
| `store_closed_message` | text | `(empty)` | Shown instead of the checkout while closed (≤ 300 characters) |
| `checkout_notice` | text | `(empty)` | A short notice at checkout (≤ 300 characters) |

### Payments (Payments tab)

| Key | Type | Default | What it does |
|---|---|---|---|
| `pay_cod_enabled` | bool | `on` | Cash on delivery (the `cod_max_amount` limit applies to it only) |
| `pay_bank_enabled` | bool | `off` | Bank transfer: needs an active account |
| `pay_card_visible` | bool | `off` | Shows a "card payment, coming soon" label; card can never be chosen |
| `pay_bank_instructions` | text | `(empty)` | Shown to the customer next to the accounts (≤ 1000 characters) |
| `pay_bank_accounts` | accounts | `[]` | The accounts the customer may pay into, in display order |

### Notifications (Notifications tab)

E-mails are queued: a queue worker (or the cron variant) must run, see [NEW_CLIENT.md](NEW_CLIENT.md). The mail server itself (`MAIL_*`) is in `backend/.env`, the switches are here. These settings are not public.

| Key | Type | Default | What it does |
|---|---|---|---|
| `mail_enabled` | bool | `off` | Master switch: off = no e-mail at all, also no owner alerts |
| `mail_from_name` | string | `(empty)` | Sender name customers see (empty = the store name) |
| `mail_reply_to` | string | `(empty)` | Reply-to address (empty = the store e-mail) |
| `notify_admin_email` | string | `(empty)` | The owner's address: gets an alert for every new order, payment proof and wholesale request. Not an admin account. Empty = no alerts |
| `mail_on_placed` | bool | `on` | Customer: order received (also the "delivery fee confirmed" mail) |
| `mail_on_confirmed` | bool | `on` | Customer: order confirmed |
| `mail_on_shipped` | bool | `on` | Customer: shipped, with courier and tracking number |
| `mail_on_delivered` | bool | `on` | Customer: delivered |
| `mail_on_cancelled` | bool | `on` | Customer: cancelled or rejected, with the reason |
| `mail_on_payment_proof_rejected` | bool | `on` | Customer: the payment screenshot was not accepted, upload a new one |
| `mail_on_fee_confirmed` | bool | `on` | Customer: the owner set the delivery fee (before Phase C this followed `mail_on_placed`) |
| `owner_mail_on_placed` | bool | `on` | Owner e-mail alert for a new order |
| `owner_mail_on_proof` | bool | `on` | Owner e-mail alert when a customer uploads a payment proof |
| `owner_mail_on_wholesale` | bool | `on` | Owner e-mail alert for a new wholesale request |
| `push_on_placed` | bool | `on` | Admin push for a new order (title "New order") |
| `push_on_proof` | bool | `on` | Admin push for a payment proof to check |
| `push_on_wholesale` | bool | `on` | Admin push for a new wholesale request |
| `storefront_track_url` | string | `(empty)` | The storefront's tracking page (`http(s)://`); e-mails link to it as `?order=NUMBER`, the customer is asked for the phone number. No token in the link |
| `push_enabled` | bool | `off` | Admin browser push (needs the three `VAPID_*` values in `backend/.env`: `php artisan shop:vapid`) |

**The tab (Phase C)** is one compact page: a table of events (`Settings::EVENTS`) with a switch per channel (customer e-mail, owner e-mail alert, admin push; `-` where the channel does not exist for the event), a sender card (`mail_from_name`, `mail_reply_to`, `notify_admin_email`, `storefront_track_url`: `advanced`, so the client does not see it), a test e-mail, a status line (queue worker running / not running / idle, failed jobs, with a "view" list for `settings.advanced`) and the **Notification status** card for push (browser support, permission, subscription, VAPID, switch, queue worker, last push, and a *Send test notification* button). A message goes only when the master switch (`mail_enabled`, `push_enabled`) **and** the event's switch are on; every switch is `client`. Nine events: order placed, confirmed, shipped, delivered, cancelled or rejected, payment proof uploaded (owner and push only), payment proof rejected, delivery fee confirmed, new wholesale request (owner and push only).

A customer mail goes only when the order has an e-mail address. No e-mail contains an image, a script or a tracking pixel. Endpoints (all `settings.notifications`): `GET /admin/notifications/status` (`queue.state` = `running` / `stopped` / `idle` / `sync`, `failed_jobs`, `mail_enabled`, `push { enabled, configured, devices, last }`; with `settings.advanced` also `queue_connection`, `mailer`, `from_address`, `pending_jobs`), `GET /admin/notifications/failed-jobs` (`settings.advanced`: job name, queue, error type, time; never the content) and `POST /admin/notifications/test-email { to? }` (sent at once; default recipient = the owner address). Push: `POST /admin/push/test { endpoint? }` queues a real test push to the caller's own device(s) and `GET /admin/push/test/{id}` reports `queued` / `sent` / `partial` / `failed` / `no_device` with the push service's own error text (see [PUSH_TEST.md](PUSH_TEST.md)).

### Theme (Theme tab, developer only)

Public in `GET /store/settings` as `theme` (`primary`, `primary_contrast`, `accent`, `bg`, `surface`, `text`, `muted`, `border`, `font_heading`, `font_body`, `radius`, `shadow`). The admin panel's own look does not change.

| Key | Type | Default |
|---|---|---|
| `theme_primary` | color | `#2563EB` |
| `theme_primary_contrast` | color | `#FFFFFF` |
| `theme_accent` | color | `#F59E0B` |
| `theme_bg` | color | `#FFFFFF` |
| `theme_surface` | color | `#F8FAFC` |
| `theme_text` | color | `#0F172A` |
| `theme_muted` | color | `#64748B` |
| `theme_border` | color | `#E2E8F0` |
| `theme_font_heading` | choice | `Inter` |
| `theme_font_body` | choice | `Inter` |
| `theme_radius` | choice | `8px` |
| `theme_shadow` | choice | `soft` |


### Website (Website tab: the rebuild of the static storefront)

The storefront is a static site; the hosting rebuilds it when asked by a **deploy hook** (an empty POST: Cloudflare Pages, Netlify and Vercel all have one). These settings are never public. How it works and how to create the hook: [NEW_CLIENT.md](NEW_CLIENT.md). Permission `settings.website` (admin: on by default); the technical fields also need `settings.advanced`.

| Key | Type | Default | Tier | What it does |
|---|---|---|---|---|
| `rebuild_enabled` | bool | `off` | client | Automatic rebuild: a change customers can see (products, options, prices, stock set in the panel, pictures, tags, categories, sub-categories, banners, discounts, public settings, theme, logo / favicon / share picture) asks the hosting to rebuild. Orders, customers, carts, wholesale requests, coupons, delivery zones, notification settings and the stock a sale takes never do |
| `rebuild_hook_url` | string | `(empty)` | advanced | The deploy hook address. **https only** (a plain `http://` address is refused). The address carries a token: the audit log keeps only its host, and an error message never repeats it |
| `rebuild_hook_secret` | secret | `(empty)` | advanced | Optional, sent as the header `X-Rebuild-Secret` (for a hook of your own, e.g. a small relay). Stored encrypted, never returned: the panel shows only "Set / Not set" |
| `rebuild_debounce_seconds` | int | `120` | advanced | 10 to 3600. The first change opens a window of this length; every further change inside it only raises the counter; when it ends **one** queued job calls the hook (10 second timeout, 3 attempts with a 30 and 120 second pause). 20 quick edits = 1 call |

State (changes waiting, when the hook was last called, the last result and a short error) is kept in the cache, not in a table. The tab shows it, with a warning when the last result failed and a **Rebuild website now** button (`POST /admin/website/rebuild`, `GET /admin/website/status`; also `php artisan shop:rebuild`). The button works whenever a hook is set, even when the automatic rebuild is off. A failing hook never fails the admin action that caused it. Manual rebuilds and a changed hook address are audited (`website.rebuild_requested`, `settings.updated`); the secret never is.