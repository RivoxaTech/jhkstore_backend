# Settings reference

Store settings live in the key/value `settings` table and are read through one service, `App\Domain\Support\Settings`. A missing row falls back to the
default below, so a fresh install works without any setup; `SettingsSeeder` writes the defaults (and never overwrites a value an admin changed).

* **Read**: `app(Settings::class)->get('delivery_fee')` – typed, cached (one cache entry plus a per-request copy).
* **Write**: `app(Settings::class)->set(['delivery_fee' => 5])` – clears the cache. (The service does not validate; the admin API validates by type.)
* **Admin panel**: *Settings*, built from the list of definitions, so a new setting appears automatically. Seven tabs:
  **General**, **Branding**, **Footer & Social**, **Currency & Tax**, **Orders & Delivery**, **Features**, **SEO** (the tab → section map is `SettingController::TABS`).
  API: `GET/PUT /api/v1/admin/settings`; pictures: `POST/DELETE /api/v1/admin/settings/{logo|favicon|share-image}`.
* **Storefront**: `GET /api/v1/store/settings` – the public subset (store profile and branding, footer and social links, SEO defaults, currency, tax, delivery, enabled features). See [STOREFRONT-API.md](STOREFRONT-API.md).
* **Add a setting**: add one line to `Settings::DEFINITIONS` (group, type, default, label, help) and regenerate the tables below.

**Types**: `string`, `text`, `bool`, `int`, `money` / `percent` (numbers with 2 decimals), `file` (a picture, uploaded through its own endpoint) and `links` (a JSON list).

* **`file`** – the picture is stored with spatie medialibrary: the setting's row owns one file (single-file collection, disk `public`) and the setting value is the media id.
  A new upload replaces (and deletes) the old file. `Settings::fileUrl($key)` returns the absolute URL (cached with the settings). The file settings cannot be written through `PUT /admin/settings` (422).
  Accepted: logo PNG/JPG/WEBP ≤ 2 MB and ≤ 4000 px; favicon PNG/ICO/JPG/WEBP ≤ 512 KB; share image PNG/JPG/WEBP ≤ 4 MB and ≤ 6000 px. **SVG is rejected** on purpose (it can carry scripts).
* **`links`** – a list of `{platform, label, url, active}`; **the order of the list is the display order** (the admin panel has drag-to-reorder, move buttons, add and remove).
  `platform` is one of `facebook, instagram, linkedin, x, youtube, tiktok, whatsapp, pinterest, website, custom`; `label` is optional (the storefront falls back to the platform name);
  `url` must be a valid `http://` or `https://` address (≤ 500 characters – `javascript:` and other schemes are refused); `active` hides a link without deleting it.
  There is no fixed number of entries. The service casts the value by type (`Settings::get` always returns a clean list) and `Settings::rules()` holds the validation rules by type, used by the admin API.

**Feature switch** (`rewards_enabled`) is read in one place: `RewardLedger::enabled()`.
When a feature is off it disappears from the admin panel, the API responses and the checkout; its data is kept, so switching it back on restores everything.

**`0` means off** for `min_order_amount`, `cod_max_amount`, `delivery_fee` (free delivery on every order) and `free_delivery_min_cart` (delivery never free).

**Reward points rules** (when on): a product option earns its own `reward_points` per unit; an option without its own points earns `reward_earn_rate` points
per 1 currency unit of the line total. Points are credited when an order is delivered, spent at checkout (1 point = `reward_redeem_rate` money), refunded
when an order is cancelled, and expire after `reward_expiry_days`. Reverting a delivered-then-returned order is not implemented (there is no returns module yet).

**Tax rules**: a product's own `tax_rate` wins, otherwise `tax_rate` here. With *Prices include tax* on, the tax is part of the price (shown for
information); off, it is added to the cart total.

## Keys and defaults

### Store profile

| Key | Type | Default | What it does |
|---|---|---|---|
| `store_name` | string | `My Store` | Store name |
| `store_tagline` | string | `(empty)` | Tagline |
| `store_email` | string | `(empty)` | Contact email |
| `store_phone` | string | `(empty)` | Contact phone |
| `store_address` | text | `(empty)` | Address |
| `store_country` | string | `(empty)` | Country. Used as the default country of addresses |

### Branding

| Key | Type | Default | What it does |
|---|---|---|---|
| `store_logo` | file | `(none)` | Logo (admin panel, store, invoices). Upload: `POST /admin/settings/logo`, field `logo` |
| `store_favicon` | file | `(none)` | Favicon. Upload: `POST /admin/settings/favicon`, field `favicon` |

### Footer & Social

| Key | Type | Default | What it does |
|---|---|---|---|
| `social_links` | links | `[]` | Social and footer links, in display order |
| `footer_text` | text | `(empty)` | Footer text (≤ 500 characters) |
| `footer_copyright` | string | `(empty)` | Copyright text |
| `support_hours` | string | `(empty)` | Support hours |
| `whatsapp_number` | string | `(empty)` | WhatsApp number with country code (digits, `+`, spaces, `()`, `-`); the public API also gives a `https://wa.me/…` link |

### SEO

| Key | Type | Default | What it does |
|---|---|---|---|
| `seo_meta_title` | string | `(empty)` | Default meta title (≤ 70 characters) |
| `seo_meta_description` | text | `(empty)` | Default meta description (≤ 300 characters) |
| `seo_share_image` | file | `(none)` | Default share image. Upload: `POST /admin/settings/share-image`, field `share_image` |

### Currency

| Key | Type | Default | What it does |
|---|---|---|---|
| `currency_code` | string | `USD` | Currency code. ISO code, e.g. USD, EUR, GBP |
| `currency_symbol` | string | `$` | Currency symbol |
| `currency_decimals` | int | `2` | Decimals. Decimal places shown and calculated (0, 1 or 2) |

### Tax

| Key | Type | Default | What it does |
|---|---|---|---|
| `tax_rate` | percent | `0` | Default tax rate (%). Used for products without their own rate. 0 = no tax |
| `tax_inclusive` | bool | `on` | Prices include tax. On: tax is already inside the prices. Off: tax is added at the cart |
| `tax_label` | string | `Tax` | Tax label. Shown to customers, e.g. VAT, GST, Sales tax |

### Orders

| Key | Type | Default | What it does |
|---|---|---|---|
| `order_number_prefix` | string | `ORD` | Order number prefix. Order numbers look like PREFIX-2026-000123 |
| `min_order_amount` | money | `0` | Minimum order amount. 0 = no minimum |
| `cod_max_amount` | money | `0` | Cash on delivery limit. 0 = no limit |
| `return_window_days` | int | `10` | Return window (days). Default return period of products |

### Delivery

| Key | Type | Default | What it does |
|---|---|---|---|
| `delivery_fee` | money | `0` | Delivery charge. One flat charge per order. 0 = free delivery |
| `free_delivery_min_cart` | money | `0` | Free delivery from. Cart value from which delivery is free. 0 = never free |
| `delivery_days` | int | `5` | Delivery time (days). Shown to customers as the delivery estimate |

### Features

| Key | Type | Default | What it does |
|---|---|---|---|
| `rewards_enabled` | bool | `off` | Enable reward points. Off: no points are earned, shown or redeemed anywhere |

### Reward points

| Key | Type | Default | What it does |
|---|---|---|---|
| `reward_earn_rate` | money | `0` | Earn rate. Points earned per 1 currency unit spent, for products without their own points. 0 = none |
| `reward_redeem_rate` | money | `1` | Redeem rate. Money value of one point at checkout |
| `reward_expiry_days` | int | `365` | Points expire after (days) |
| `max_redeem_coins_per_order` | int | `5000` | Most points per order |
| `redeem_cap_order_percent` | percent | `100` | Most % of an order points may pay. 100 = no extra cap |
| `redeem_cap_balance_percent` | percent | `100` | Most % of the balance one order may use. 100 = no extra cap |
