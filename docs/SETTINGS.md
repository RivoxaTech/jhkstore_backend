# Settings reference

Store settings live in the key/value `settings` table and are read through one service, `App\Domain\Support\Settings`. A missing row falls back to the
default below, so a fresh install works without any setup; `SettingsSeeder` writes the defaults (and never overwrites a value an admin changed).

* **Read**: `app(Settings::class)->get('delivery_fee')` – typed, cached (one cache entry plus a per-request copy).
* **Write**: `app(Settings::class)->set(['delivery_fee' => 5])` – clears the cache. (The service does not validate; the admin API validates by type.)
* **Admin panel**: *Settings*, built from the list of definitions, so a new setting appears automatically. Eleven tabs:
  **General**, **Branding**, **Banners**, **Footer & Social**, **Currency & Tax**, **Orders & Delivery**, **Delivery**, **Payments**, **Store**, **Theme**, **SEO** (the tab → section map is `SettingController::TABS`). **Banners** has no settings: it holds the banner manager (`/admin/banners` endpoints). **Delivery** has its settings and the delivery zone manager (`/admin/delivery-zones` endpoints). **Theme** is the developer's: the admin never gets it. Every tab has its own permission `settings.<tab>` (`settings.general`, `settings.branding`, `settings.banners`, `settings.footer`, `settings.currency_tax`, `settings.orders_delivery`, `settings.delivery`, `settings.payments`, `settings.store`, `settings.theme`, `settings.seo`): the developer switches them for the admin in Access Control ([ACCESS_CONTROL.md](ACCESS_CONTROL.md)), the API lists only the tabs the admin may open and answers 403 for the others. `settings.theme` can never be given to the admin.
  API: `GET/PUT /api/v1/admin/settings`; pictures: `POST/DELETE /api/v1/admin/settings/{logo|favicon|share-image}`.
* **Storefront**: `GET /api/v1/store/settings` – the public subset (store profile and branding, open / closed, checkout notice, footer, social links and agency credit, SEO defaults, currency, tax, delivery, payment methods, order limits, theme). See [STOREFRONT-API.md](STOREFRONT-API.md).
* **Add a setting**: add one line to `Settings::DEFINITIONS` (group, type, default, label, help) and regenerate the tables below.
* **Add a tab**: add the entry to `SettingController::TABS` (and a section label in `SECTIONS`), the settings with that group to `Settings::DEFINITIONS`, and a `settings.<tab>` permission to `AdminAccess::GROUPS`; the admin panel renders the tab from the API; nothing else changes. (The Delivery, Payments, Store and Theme tabs were added this way.)

**Types**: `string`, `text`, `bool`, `int`, `money` / `percent` (numbers with 2 decimals), `file` (a picture, uploaded through its own endpoint), `links` (a JSON list), `accounts` (a JSON list of bank accounts), `color` (`#RRGGBB`) and `choice` (one value of a fixed list; the API sends the list as `options`).

* **`file`** – the picture is stored with spatie medialibrary: the setting's row owns one file (single-file collection, disk `public`) and the setting value is the media id.
  A new upload replaces (and deletes) the old file. `Settings::fileUrl($key)` returns the absolute URL (cached with the settings). The file settings cannot be written through `PUT /admin/settings` (422).
  Accepted: logo PNG/JPG/WEBP ≤ 2 MB and ≤ 4000 px; favicon PNG/ICO/JPG/WEBP ≤ 512 KB; share image PNG/JPG/WEBP ≤ 4 MB and ≤ 6000 px. **SVG is rejected** on purpose (it can carry scripts).
* **`links`** – a list of `{platform, label, url, active}`; **the order of the list is the display order** (the admin panel has drag-to-reorder, move buttons, add and remove).
  `platform` is one of `facebook, instagram, linkedin, x, youtube, tiktok, whatsapp, pinterest, website, custom`; `label` is optional (the storefront falls back to the platform name);
  `url` must be a valid `http://` or `https://` address (≤ 500 characters – `javascript:` and other schemes are refused); `active` hides a link without deleting it.
  There is no fixed number of entries. The service casts the value by type (`Settings::get` always returns a clean list) and `Settings::rules()` holds the validation rules by type, used by the admin API.

* **`accounts`** – a list of `{bank, title, number, iban, active}`, **in display order**, any number of entries. `bank` is required (≤ 80), `title` ≤ 120, `number` and `iban` ≤ 40 and **at least one of them is required**; `active` hides an account without deleting it. Rows without a bank or without a number and IBAN are dropped by the service. Public: only the active accounts (`bank`, `title`, `number`, `iban`).
* **`color`** – `#RRGGBB` (case is kept as typed). **`choice`** – the value must be one of `Settings::CHOICES`: `delivery_method`, `delivery_unmatched`, `theme_font_heading` / `theme_font_body` (fonts: Inter, Poppins, Playfair Display, Plus Jakarta Sans, Roboto, Lato, system-ui), `theme_radius` (`0px`, `4px`, `8px`, `12px`, `16px`, `999px`), `theme_shadow` (`none`, `soft`, `medium`).
* **Rules over more than one key** (checked on save with the stored values merged in): at least one of cash on delivery and bank transfer stays on (`pay_cod_enabled` / `pay_bank_enabled` → 422), and bank transfer needs at least one active account (turning it on, or removing the last active account while it is on, → 422).

**`0` means off** for `min_order_amount`, `cod_max_amount`, `delivery_fee` (free delivery on every order) and `free_delivery_min_cart` (delivery never free).

**Delivery method** (`delivery_method`): `flat` – `delivery_fee` on every order; `free` – always 0; `free_above` (**the default**, the behaviour of earlier versions) – `delivery_fee` unless the items total after discounts reaches `free_delivery_min_cart`; `zones` – the fee of the first active zone (by `sort_order`) that matches the address, free above the zone's own amount, the zone's minimum order replaces `min_order_amount`; no address yet = fee `null` and `delivery_needs_address`; an address outside every zone follows `delivery_unmatched` (`block` = refuse, `owner_confirms`, `flat`); `owner_confirms` – the order is placed with the fee unknown and the admin sets it later. A free-delivery coupon sets the fee to 0 in every method.

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
| `agency_name` | string | `(empty)` | Agency credit shown in the footer (≤ 80 characters). Empty = no credit |
| `agency_url` | string | `(empty)` | The agency's website, `https://` only. Public as `footer.agency { name, url }` |

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

### Orders (Orders & Delivery tab)

| Key | Type | Default | What it does |
|---|---|---|---|
| `order_number_prefix` | string | `ORD` | Order number prefix. Order numbers look like PREFIX-2026-000123 |
| `min_order_amount` | money | `0` | Minimum order amount. 0 = no minimum |
| `cod_max_amount` | money | `0` | Cash on delivery limit. 0 = no limit |

### Delivery (Delivery tab; the three money / days keys used to be in Orders & Delivery, their keys are unchanged)

| Key | Type | Default | What it does |
|---|---|---|---|
| `delivery_method` | choice | `free_above` | `flat`, `free`, `free_above`, `zones` or `owner_confirms` (see above) |
| `delivery_fee` | money | `0` | Delivery charge of the flat and free-above methods, and of unmatched addresses set to `flat`. 0 = free delivery |
| `free_delivery_min_cart` | money | `0` | Free delivery from (free-above method). Items total after discounts. 0 = never free |
| `delivery_days` | int | `5` | Delivery time (days). Shown to customers as the delivery estimate |
| `delivery_unmatched` | choice | `block` | Zones method, address outside every zone: `block` (422 `delivery_not_available`), `owner_confirms` (fee pending) or `flat` (`delivery_fee`) |

The zones themselves are rows of `delivery_zones`, managed in this tab (`/admin/delivery-zones`): name, what they match (`city`, `area` or `postcode`; case-insensitive, a postcode may end with `*` for a prefix, spaces ignored), the values, fee, optional free-above amount, optional minimum order, optional ETA text, order, active. Zone ETA text replaces `delivery_eta_text`.

### Store (Store tab)

| Key | Type | Default | What it does |
|---|---|---|---|
| `store_open` | bool | `on` | Off: customers can browse and get a quote, `POST /orders` answers 422 `store_closed` |
| `store_closed_message` | text | `(empty)` | Shown instead of the checkout while closed (≤ 300 characters) |
| `checkout_notice` | text | `(empty)` | A short notice at checkout (≤ 300 characters) |
| `delivery_eta_text` | string | `(empty)` | e.g. `2 to 4 days`, used when no zone has an ETA of its own (≤ 120 characters) |

### Payments (Payments tab)

| Key | Type | Default | What it does |
|---|---|---|---|
| `pay_cod_enabled` | bool | `on` | Cash on delivery (the `cod_max_amount` limit applies to it only) |
| `pay_bank_enabled` | bool | `off` | Bank transfer: needs an active account |
| `pay_card_visible` | bool | `off` | Shows a "card payment, coming soon" label; card can never be chosen |
| `pay_bank_instructions` | text | `(empty)` | Shown to the customer next to the accounts (≤ 1000 characters) |
| `pay_bank_accounts` | accounts | `[]` | The accounts the customer may pay into, in display order |

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
