# Settings reference

Store settings live in the key/value `settings` table and are read through one service, `App\Domain\Support\Settings`. A missing row falls back to the
default below, so a fresh install works without any setup; `SettingsSeeder` writes the defaults (and never overwrites a value an admin changed).

* **Read**: `app(Settings::class)->get('delivery_fee')` – typed, cached (one cache entry plus a per-request copy).
* **Write**: `app(Settings::class)->set(['delivery_fee' => 5])` – clears the cache. (The service does not validate; the admin API validates by type.)
* **Admin panel**: *Settings*, built from the list of definitions, so a new setting appears automatically. Seven tabs:
  **General**, **Branding**, **Banners**, **Footer & Social**, **Currency & Tax**, **Orders & Delivery**, **SEO** (the tab → section map is `SettingController::TABS`). **Banners** has no settings: it holds the banner manager (`/admin/banners` endpoints). Every tab has its own permission `settings.<tab>` (`settings.general`, `settings.branding`, `settings.banners`, `settings.footer`, `settings.currency_tax`, `settings.orders_delivery`, `settings.seo`): the developer switches them for the admin in Access Control ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)), the API lists only the tabs the admin may open and answers 403 for the others.
  API: `GET/PUT /api/v1/admin/settings`; pictures: `POST/DELETE /api/v1/admin/settings/{logo|favicon|share-image}`.
* **Storefront**: `GET /api/v1/store/settings` – the public subset (store profile and branding, footer and social links, SEO defaults, currency, tax, delivery, order limits). See [STOREFRONT-API.md](STOREFRONT-API.md).
* **Add a setting**: add one line to `Settings::DEFINITIONS` (group, type, default, label, help) and regenerate the tables below.
* **Add a tab** (for example a small *Payment details* tab where the client changes an account number): add the entry to `SettingController::TABS` (and a section label in `SECTIONS`), the settings with that group to `Settings::DEFINITIONS`, and a `settings.payment_details` permission to `AdminAccess::GROUPS`. The admin panel renders the tab from the API; nothing else changes. It is deliberately not built yet.

**Types**: `string`, `text`, `bool`, `int`, `money` / `percent` (numbers with 2 decimals), `file` (a picture, uploaded through its own endpoint) and `links` (a JSON list).

* **`file`** – the picture is stored with spatie medialibrary: the setting's row owns one file (single-file collection, disk `public`) and the setting value is the media id.
  A new upload replaces (and deletes) the old file. `Settings::fileUrl($key)` returns the absolute URL (cached with the settings). The file settings cannot be written through `PUT /admin/settings` (422).
  Accepted: logo PNG/JPG/WEBP ≤ 2 MB and ≤ 4000 px; favicon PNG/ICO/JPG/WEBP ≤ 512 KB; share image PNG/JPG/WEBP ≤ 4 MB and ≤ 6000 px. **SVG is rejected** on purpose (it can carry scripts).
* **`links`** – a list of `{platform, label, url, active}`; **the order of the list is the display order** (the admin panel has drag-to-reorder, move buttons, add and remove).
  `platform` is one of `facebook, instagram, linkedin, x, youtube, tiktok, whatsapp, pinterest, website, custom`; `label` is optional (the storefront falls back to the platform name);
  `url` must be a valid `http://` or `https://` address (≤ 500 characters – `javascript:` and other schemes are refused); `active` hides a link without deleting it.
  There is no fixed number of entries. The service casts the value by type (`Settings::get` always returns a clean list) and `Settings::rules()` holds the validation rules by type, used by the admin API.

**`0` means off** for `min_order_amount`, `cod_max_amount`, `delivery_fee` (free delivery on every order) and `free_delivery_min_cart` (delivery never free).

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
| `default_phone_country_code` | string | `(empty)` | Default phone country code, e.g. `+92` (`+` and 1–4 digits). A customer phone starting with a single `0` and no country code gets this prefix (the `0` is dropped), so `0300 1234567`, `+92 300 1234567` and `0092 300 1234567` are one customer. Empty = numbers are kept as typed. Applies to orders placed after the change; customers already stored keep their number |

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
| `tax_label` | string | `Tax` | Tax label. Shown to customers, e.g. VAT, Sales tax |

### Orders

| Key | Type | Default | What it does |
|---|---|---|---|
| `order_number_prefix` | string | `ORD` | Order number prefix. Order numbers look like PREFIX-2026-000123 |
| `min_order_amount` | money | `0` | Minimum order amount. 0 = no minimum |
| `cod_max_amount` | money | `0` | Cash on delivery limit. 0 = no limit |

### Delivery

| Key | Type | Default | What it does |
|---|---|---|---|
| `delivery_fee` | money | `0` | Delivery charge. One flat charge per order. 0 = free delivery |
| `free_delivery_min_cart` | money | `0` | Free delivery from. Cart value from which delivery is free. 0 = never free |
| `delivery_days` | int | `5` | Delivery time (days). Shown to customers as the delivery estimate |
