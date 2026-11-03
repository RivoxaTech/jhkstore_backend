# How to create a new client project

The platform is **one deployment per client**: a separate copy of the code, its own database and its own `.env`. Nothing is shared between clients at runtime.

## 1. Create the project

1. Create a new repository from this one (fork, or "use as template") and name it after the client. Keep this repo as the upstream you pull fixes from.
2. Pick the names you will use for the client and write them down once:

   | Item | Example |
   |---|---|
   | Database | `acme_db` (tests: `acme_test`) |
   | App name | `Acme Store` |
   | Public API URL | `https://api.acme.example` |
   | Admin panel URL | same host, `/admin` |

## 2. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

| Key | Set it to |
|---|---|
| `APP_NAME` | the client's store name (also used as the default mail sender name) |
| `APP_URL` | the public API URL (image URLs are built from it) |
| `APP_TIMEZONE` | the client's time zone, e.g. `Europe/Berlin` – set it **before the first order** |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | the new database |
| `FRONTEND_URLS` | the storefront origin(s) allowed by CORS (comma separated) |
| `SANCTUM_STATEFUL_DOMAINS` | the admin panel host(s), e.g. `api.acme.example` |
| `SEED_DEVELOPER_USERNAME`, `SEED_DEVELOPER_PASSWORD` | the **developer** account: builds and runs the site, never sees orders (12+ characters in production) |
| `SEED_ADMIN_USERNAME`, `SEED_ADMIN_PASSWORD` | the **admin** account: the client (12+ characters in production; the seeder refuses weak passwords and identical usernames) |
| `ADMIN_TOKEN_MINUTES` | how long an admin API token (scripts) lives (default 720 = 12 hours; 0 = never) |
| `SHOP_MAX_ITEMS_PER_ORDER`, `SHOP_MAX_QTY_PER_LINE`, `SHOP_HONEYPOT_FIELD`, `CHECKOUT_PER_MINUTE`, … | optional: the limits of the public checkout (defaults in `.env.example` and `config/shop.php`) |
| `SESSION_LIFETIME` | minutes an admin panel session stays valid without activity (default 120) |
| `MAIL_*`, `FILESYSTEM_DISK` | the client's mail / storage |
| `SESSION_SECURE_COOKIE=true` | in production (HTTPS) |

## 3. Create the database and install

```bash
# create the empty database (and the test database) in MySQL first
composer install --no-dev --optimize-autoloader
php artisan migrate --force --seed       # tables, default settings, the two admin accounts
php artisan storage:link
cd admin-frontend && npm install && npm run build && cd ..     # admin panel -> public/admin-assets
```

Add the cron entry `* * * * * php artisan schedule:run` (removal of expired admin API tokens).

## 4. Configure the store (admin panel → Settings)

Log in at `/admin` as the **developer** and set, in this order (see [SETTINGS.md](SETTINGS.md) for every field):

1. **General** – name, tagline, contact email / phone / address, country, and the **default phone country code** (e.g. `+92`: customers who type `0300…` and `+92 300…` become one customer; set it **before the first order**). **Branding** – logo and favicon.
2. **Currency & Tax** – code, symbol and decimals (e.g. `EUR`, `€`, 2); default tax rate, whether prices include tax, and the label ("VAT", "Sales tax", …). A product can override the rate.
3. **Orders & Delivery** – order number prefix, delivery charge, free-delivery limit, delivery days, minimum order, cash-on-delivery limit.
4. **Footer & Social**, **SEO**, and the **Banners** tab (home hero, secondary banners, promo strip).

Then open **Access Control** (developer only) and switch on what the client's `admin` account may see: by default the admin has the dashboard, orders, the customers list and the General, Footer & Social and Orders & Delivery tabs; products, categories and the other tabs are off until you switch them on. Set a real password for both accounts (`SEED_*_PASSWORD` before the first seed). The developer never sees orders. See [ACCESS_CONTROL.md](ACCESS_CONTROL.md).

## 5. Add the catalogue

Admin panel → **Categories** (two levels), **Tags**, **Products** (options, prices, bulk prices, stock, pictures).
To try the shop first, load the neutral sample data: `php artisan db:seed --class=DemoSeeder` (never on a production site).

## 6. Connect the storefront

Give the storefront developer [STOREFRONT-API.md](STOREFRONT-API.md), `docs/openapi.json` and the Postman collection in `docs/postman/`. The first call a storefront should make is
`GET /api/v1/store/settings` – it returns the store name, logo, currency and tax rules, so one storefront build adapts to each client. There is no customer login: the storefront keeps the cart, calls `POST /checkout/quote` and `POST /orders`.

## 7. Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` (it is forced off in production anyway), HTTPS, `SESSION_SECURE_COOKIE=true` (the default in production)
- [ ] `ADMIN_TOKEN_MINUTES` and the checkout rate limits (`CHECKOUT_PER_*`, `QUOTE_PER_MINUTE`, `ORDER_LOOKUP_PER_*`) reviewed
- [ ] strong `SEED_DEVELOPER_PASSWORD` and `SEED_ADMIN_PASSWORD` used (12+ characters)
- [ ] the storefront has the hidden honeypot field (`website`) in its checkout form
- [ ] `APP_URL`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS` match the real domains
- [ ] cron entry added; a queue worker is **not** required (nothing is queued)
- [ ] `php artisan config:cache route:cache` after the final `.env`
- [ ] backups of the database and `storage/app/public`
- [ ] demo data removed

## Keeping clients up to date

Pull upstream changes into the client repository and run `php artisan migrate --force`. After the lean cut migrations only add or alter (a database from before it must be rebuilt with `migrate:fresh --seed`); client-specific values live in `settings`, so an
upgrade never overwrites them. Client-specific code (a custom module, a payment provider) should live in its own `app/Domain/<Module>` folder to keep merges simple.
