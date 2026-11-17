# JHK Store: backend (this repository)

The Laravel 13 / PHP 8.3 / MySQL 8 JSON API of a neutral, reusable e-commerce base: **products, categories and orders**, plus the admin settings that control the website. **One deployment per client**: the code is the same for every client, and everything specific to a client (store name, logo, currency, tax, delivery, payment methods, theme, banners, footer) lives in `.env` and the `settings` table. No customer accounts: guests order with a name and a phone number.

`/api/v1/store/*` is for the storefront, `/api/v1/admin/*` for the admin panel. The API also serves the admin panel at `/admin` from `public/admin-assets` (the panel's build, which is not part of this repository). All the guides are in [`docs/`](docs), the history of changes is [CHANGELOG.md](CHANGELOG.md).

## Three repositories

The root of this repository **is** the Laravel app (`app/`, `composer.json`, `artisan`, ...). The project is three repositories:

| Repository | What it is | Deployed to |
|---|---|---|
| **[jhkstore_backend](https://github.com/RivoxaTech/jhkstore_backend)** (this one) | The API, the guides (`docs/`), the check scripts (`scripts/`), the CHANGELOG | **cPanel** (PKWebHost): in a folder outside the web root, the document root of the API domain pointing at `public/` |
| **[jhkstore_admin-frontend](https://github.com/RivoxaTech/jhkstore_admin-frontend)** | The Vue 3 admin panel (developer and client accounts) and the Help video maker. Its production build is written into `public/admin-assets` of this repository's app | built by CI or on your computer and uploaded to `public/admin-assets` on the server |
| **[jhkstore_frontend](https://github.com/RivoxaTech/jhkstore_frontend)** | The customer storefront: Nuxt 4, prerendered as a **static site**; price, stock, cart and checkout are live from the API | a static host (cPanel folder, or Cloudflare Pages) |

For development keep the three **side by side** in one ordinary folder (not a repository), with exactly these folder names (the admin build and the tests that use two apps find the others through them):

```
jhkstore/
  backend/          a clone of jhkstore_backend
  admin-frontend/   a clone of jhkstore_admin-frontend
  frontend/         a clone of jhkstore_frontend
```

```bash
mkdir jhkstore && cd jhkstore
git clone https://github.com/RivoxaTech/jhkstore_backend.git backend
git clone https://github.com/RivoxaTech/jhkstore_admin-frontend.git admin-frontend
git clone https://github.com/RivoxaTech/jhkstore_frontend.git frontend
```

## Run it locally

Requirements: PHP 8.3 with the usual Laravel extensions (plus `gd`), Composer, MySQL 8, Node 22.22.3+ or 24.15+ (the Nuxt 4 storefront needs it). Port 8000 may belong to another project on your machine, so these commands use **8002**.

```bash
# 1. this repository (the API)
cd backend
composer install
cp .env.example .env                    # then set DB_USERNAME / DB_PASSWORD if needed
php artisan key:generate
# create two empty MySQL databases: jhkstore_db (the app) and jhkstore_test (the tests)
php artisan migrate --seed              # tables + default settings + the two admin accounts
php artisan db:seed --class=DemoSeeder  # optional: a toy catalogue with pictures for the storefront
php artisan storage:link
php artisan serve --port=8002           # API: http://127.0.0.1:8002/api/v1/store/settings
# Windows, much faster while developing (OPcache on, 4 workers; see serve-fast.cmd):   serve-fast.cmd

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

Accounts (from `.env`, defaults for local development only): developer `developer` / `developer`, admin `admin` / `admin`. The developer never sees orders; the admin is the client and sees what the developer allows ([ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md)). In production the seeder refuses weak passwords.

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
| `backend/` | `node scripts/flow-check.mjs` · `node scripts/adversarial.mjs` | the full flow check and the adversarial check over real HTTP (see [QA_REPORT.md](docs/QA_REPORT.md)) |
| `admin-frontend/` | `npm run build` · `npm run e2e` · `npm run flow-ui` | build the panel · browser smoke test · the same flows screen by screen |
| `frontend/` | `npm run generate` · `npm run test:e2e` | the static build · the browser tests over the generated site |

The server's `APP_URL` must match the port it serves, and the port must be in `SANCTUM_STATEFUL_DOMAINS` (admin login). `.env` `FRONTEND_URLS` lists the origins allowed by CORS (add `http://localhost:3000`).

## Where each part is deployed

* **API and admin panel on cPanel (PKWebHost):** put this repository's files (with `vendor/`) in a folder outside the web root, point the domain's document root (for example `api.example.com`) at its `public/` folder, create the database, `php artisan migrate --force --seed`, `php artisan storage:link`, add the cron entries, and put the admin panel's build into `public/admin-assets`. Step by step: [docs/NEW_CLIENT.md](docs/NEW_CLIENT.md).
* **Storefront:** repository `jhkstore_frontend`; build command `npm run generate`, output `.output/public`; the API sits behind `api.example.com`. Cloudflare Pages (repository root = project root, no sub-folder) or a static cPanel folder: [docs/STOREFRONT.md](docs/STOREFRONT.md).

## Documentation

All guides are in [`docs/`](docs). Paths inside them start at the folder that holds the three repositories (`backend/app/...`, `backend/docs/...`, `admin-frontend/src/...`, `frontend/app/...`): inside this repository drop the leading `backend/`. Commands for PHP, Composer and Artisan run in this repository's root.

| Doc | What is in it |
|---|---|
| [HANDOVER.md](docs/HANDOVER.md) | **One page: every feature, where it lives in the code, which permission controls it, how to add a client** |
| [NEW_CLIENT.md](docs/NEW_CLIENT.md) | **How to create a new client project** (mail, queue worker, push, upload limits, the rebuild hook, cPanel layout, go-live checklist) |
| [STOREFRONT.md](docs/STOREFRONT.md) | The Nuxt storefront: structure, environment, build-time vs live data, Cloudflare Pages, cPanel, launch checklist |
| [STOREFRONT_BRIEF.md](docs/STOREFRONT_BRIEF.md) | The brief the storefront follows: endpoints per page, rules, SEO, performance |
| [STOREFRONT-API.md](docs/STOREFRONT-API.md) | The public API: settings and theme, catalogue, sitemap, cart, quote, coupons, orders, bank transfer proof, tracking, wholesale |
| [ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md) | The two accounts, the permission list, the setting tiers, the hard rules |
| [SETTINGS.md](docs/SETTINGS.md) | Every setting, its default and what it does; how to add a tab |
| [DEPLOYDESK.md](docs/DEPLOYDESK.md) | **Deploying with DeployDesk:** the one-time setup (import, pull requests, server, targets), the routine for every change, rollback, what to check on the first deploy |
| [GO_LIVE.md](docs/GO_LIVE.md) | **Going live:** what goes where, what to leave out, the three repositories, the checklist for the day, speed after launch, how often to rebuild |
| [NEXT_CHAT_PROMPTS.md](docs/NEXT_CHAT_PROMPTS.md) | Ready prompts for a new AI chat: the project so far and its rules, and a focused speed session |
| [HELP_AND_APP.md](docs/HELP_AND_APP.md) | The Help section (how-to videos, how to make them again) and the admin panel as an installable app |
| [FEATURES.md](docs/FEATURES.md) | What is built, what was removed |
| [QA_REPORT.md](docs/QA_REPORT.md) | The checks run on the whole product and their results |
| [PERFORMANCE.md](docs/PERFORMANCE.md) | What was slow, what was changed, the numbers, and how to measure again |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) · [DATABASE.md](docs/DATABASE.md) | How the code is organised · the ERD and every table |
| [openapi.json](docs/openapi.json) · [postman/](docs/postman) | OpenAPI 3.1 reference (live at `/docs/api`) · Postman collection. Export with neutral values: `APP_NAME="Store API" APP_URL=https://shop.example.com php artisan scramble:export --path=docs/openapi.json` |
