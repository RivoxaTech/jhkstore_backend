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
| `SEED_ADMIN_USERNAME`, `SEED_ADMIN_PASSWORD` | the first admin (the seeder refuses a weak password in production) |
| `MAIL_*`, `FILESYSTEM_DISK` | the client's mail / storage |
| `SESSION_SECURE_COOKIE=true` | in production (HTTPS) |

## 3. Create the database and install

```bash
# create the empty database (and the test database) in MySQL first
composer install --no-dev --optimize-autoloader
php artisan migrate --force --seed       # tables, default settings, the first admin
php artisan storage:link
cd admin-frontend && npm install && npm run build && cd ..     # admin panel -> public/admin-assets
```

Add the cron entry `* * * * * php artisan schedule:run` (cart clean-up and points expiry).

## 4. Configure the store (admin panel → Settings)

Log in at `/admin` and set, in this order (see [SETTINGS.md](SETTINGS.md) for every field):

1. **Store profile** – name, tagline, logo, contact email / phone / address, country.
2. **Currency** – code, symbol and decimals (e.g. `EUR`, `€`, 2).
3. **Tax** – default rate, whether prices include tax, and the label ("VAT", "GST", …). A product can override the rate.
4. **Orders and delivery** – order number prefix, delivery charge, free-delivery limit, delivery days, minimum order, cash-on-delivery limit.
5. **Features** – *Enable reward points* (default off). With points on, also set the earn rate, redeem rate and limits.

Then change the admin password (create a new admin or update the seeded one) and remove the demo data if you loaded it.

## 5. Add the catalogue

Admin panel → **Categories** (two levels), **Tags**, **Products** (options, prices, bulk prices, stock, pictures).
To try the shop first, load the neutral sample data: `php artisan db:seed --class=DemoSeeder` (never on a production site; it also creates a demo customer).

## 6. Connect the storefront

Give the storefront developer [STOREFRONT-API.md](STOREFRONT-API.md) and the Postman collection in `docs/postman/`. The first call a storefront should make is
`GET /api/v1/store/settings` – it returns the store name, logo, currency, tax rules and which features are on, so one storefront build adapts to each client.

## 7. Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, `SESSION_SECURE_COOKIE=true`
- [ ] strong `SEED_ADMIN_PASSWORD` used, default admin replaced
- [ ] `APP_URL`, `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS` match the real domains
- [ ] cron entry added; a queue worker is **not** required yet (nothing is queued)
- [ ] `php artisan config:cache route:cache` after the final `.env`
- [ ] backups of the database and `storage/app/public`
- [ ] demo data removed

## Keeping clients up to date

Pull upstream changes into the client repository and run `php artisan migrate --force`. Migrations only ever add or alter; client-specific values live in `settings`, so an
upgrade never overwrites them. Client-specific code (a custom module, a payment provider) should live in its own `app/Domain/<Module>` folder to keep merges simple.
