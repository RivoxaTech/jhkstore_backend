# JHK Store

A neutral, reusable e-commerce base: **products, categories and orders**, plus the admin settings that control the website. **One deployment per client**: the code is the same for every client, and everything specific to a client (store name, logo, currency, tax, delivery, payment methods, theme, banners, footer) lives in `backend/.env` and the `settings` table. No customer accounts: guests order with a name and a phone number.

## The three folders

| Folder | What it is | Deployed to |
|---|---|---|
| [`backend/`](backend) | The Laravel 13 / PHP 8.3 / MySQL 8 JSON API: `/api/v1/store/*` for the storefront, `/api/v1/admin/*` for the admin panel. Also serves the admin panel at `/admin` from `backend/public/admin-assets` | **cPanel** (PKWebHost): the app in a folder, the document root pointing at `backend/public` |
| [`admin-frontend/`](admin-frontend) | The Vue 3 admin panel (developer and client accounts). Its production build goes into `backend/public/admin-assets` | with the backend (built locally and uploaded, or built on the server when Node exists) |
| [`frontend/`](frontend) | The customer storefront: Nuxt, prerendered as a **static site**; price, stock, cart and checkout are live from the API | **Cloudflare Pages** (the same build also runs on cPanel, Netlify or Vercel) |

Also at the root: [`docs/`](docs) (the guides, start with [HANDOVER.md](docs/HANDOVER.md)), [`scripts/`](scripts) (the real-HTTP flow check), [`design/`](design) (the reference screenshot, logo and banner backgrounds of the storefront design) and [CHANGELOG.md](CHANGELOG.md).

## Run it locally

Requirements: PHP 8.3 with the usual Laravel extensions (plus `gd`), Composer, MySQL 8, Node 22.22.3+ or 24.15+ (the Nuxt 4 storefront needs it). Port 8000 may belong to another project on your machine, so these commands use **8002**.

```bash
# 1. backend
cd backend
composer install
cp .env.example .env                    # then set DB_USERNAME / DB_PASSWORD if needed
php artisan key:generate
# create two empty MySQL databases: jhkstore_db (the app) and jhkstore_test (the tests)
php artisan migrate --seed              # tables + default settings + the two admin accounts
php artisan db:seed --class=DemoSeeder  # optional: a toy catalogue with pictures for the storefront
php artisan storage:link
php artisan serve --port=8002           # API: http://127.0.0.1:8002/api/v1/store/settings
# Windows, much faster while developing (OPcache on, 4 workers; see backend/serve-fast.cmd):   serve-fast.cmd

# 2. admin panel (built into backend/public/admin-assets, served at http://127.0.0.1:8002/admin)
cd ../admin-frontend
npm install
npm run build                           # or: npm run dev  (http://localhost:5190, proxies to the API on 8002)

# 3. storefront
cd ../frontend
cp .env.example .env                    # NUXT_PUBLIC_API_BASE=http://127.0.0.1:8002/api/v1/store
npm install
npm run dev                             # http://localhost:3000
npm run generate                        # the static site in .output/public (needs the API running)
```

Accounts (from `backend/.env`, defaults for local development only): developer `developer` / `developer`, admin `admin` / `admin`. The developer never sees orders; the admin is the client and sees what the developer allows ([docs/ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md)). In production the seeder refuses weak passwords.

## Common commands

| Where | Command | What it does |
|---|---|---|
| `backend/` | `php artisan test` (or `vendor/bin/phpunit`) | the PHPUnit suite (needs the empty database `jhkstore_test`) |
| `backend/` | `php artisan migrate:fresh --seed` | rebuilds the development database (base seeder only; never on a live site) |
| `backend/` | `php artisan db:seed --class=DemoSeeder` | optional sample catalogue |
| `backend/` | `php artisan queue:work --tries=3` | the queue worker (e-mails, push, website rebuild); cron variant in [NEW_CLIENT.md](docs/NEW_CLIENT.md) |
| `backend/` | `php artisan schedule:run` (cron, every minute) | token and cart cleanup, the queue heartbeat |
| `backend/` | `php artisan shop:rebuild` | ask the hosting to rebuild the static storefront now |
| `backend/` | `php artisan shop:vapid` | new push keys (on Windows PHP may need `OPENSSL_CONF`) |
| `admin-frontend/` | `npm run build` · `npm run e2e` · `npm run flow-ui` | build the panel · browser smoke test · the same flows screen by screen |
| repo root | `node scripts/flow-check.mjs` | the full flow check over real HTTP (two servers; see [QA_REPORT.md](docs/QA_REPORT.md)) |
| `frontend/` | `npm run generate` · `npm run test:e2e` | the static build · the browser tests over the generated site |

The server's `APP_URL` must match the port it serves, and the port must be in `SANCTUM_STATEFUL_DOMAINS` (admin login). `backend/.env` `FRONTEND_URLS` lists the origins allowed by CORS (add `http://localhost:3000`).

## Where each part is deployed

* **Backend and admin panel on cPanel (PKWebHost):** upload `backend/` (with its `vendor/` and the built `public/admin-assets`) into a folder outside the web root, point the domain's document root (for example `api.example.com`) at `backend/public`, create the database, `php artisan migrate --force --seed`, `php artisan storage:link`, add the cron entries. Step by step: [docs/NEW_CLIENT.md](docs/NEW_CLIENT.md).
* **Storefront on Cloudflare Pages:** root directory `frontend`, build command `npm run generate`, output `.output/public`; the API sits behind `api.example.com`. Step by step, the deploy hook for the *Website* tab and the cPanel variant: [docs/STOREFRONT.md](docs/STOREFRONT.md).

## Documentation

| Doc | What is in it |
|---|---|
| [docs/HANDOVER.md](docs/HANDOVER.md) | **One page: every feature, where it lives in the code, which permission controls it, how to add a client** |
| [docs/NEW_CLIENT.md](docs/NEW_CLIENT.md) | **How to create a new client project** (mail, queue worker, push, upload limits, the rebuild hook, cPanel layout, go-live checklist) |
| [docs/STOREFRONT.md](docs/STOREFRONT.md) | The Nuxt storefront: structure, environment, build-time vs live data, Cloudflare Pages, cPanel, launch checklist |
| [docs/STOREFRONT_BRIEF.md](docs/STOREFRONT_BRIEF.md) | The brief the storefront follows: endpoints per page, rules, SEO, performance |
| [docs/STOREFRONT-API.md](docs/STOREFRONT-API.md) | The public API: settings and theme, catalogue, sitemap, cart, quote, coupons, orders, bank transfer proof, tracking, wholesale |
| [docs/ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md) | The two accounts, the permission list, the setting tiers, the hard rules |
| [docs/SETTINGS.md](docs/SETTINGS.md) | Every setting, its default and what it does; how to add a tab |
| [docs/HELP_AND_APP.md](docs/HELP_AND_APP.md) | The Help section (how-to videos, how to make them again) and the admin panel as an installable app |
| [docs/FEATURES.md](docs/FEATURES.md) | What is built, what was removed |
| [docs/QA_REPORT.md](docs/QA_REPORT.md) | The checks run on the whole product and their results |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) · [docs/DATABASE.md](docs/DATABASE.md) | How the code is organised · the ERD and every table |
| [docs/openapi.json](docs/openapi.json) · [docs/postman/](docs/postman) | OpenAPI 3.1 reference (live at `/docs/api`) · Postman collection. Export with neutral values: `cd backend && APP_NAME="Store API" APP_URL=https://shop.example.com php artisan scramble:export --path=../docs/openapi.json` |
| [CHANGELOG.md](CHANGELOG.md) | What changed |

Paths in the docs start at the repository root (`backend/app/...`, `admin-frontend/src/...`). Commands for PHP, Composer and Artisan run inside `backend/`.
