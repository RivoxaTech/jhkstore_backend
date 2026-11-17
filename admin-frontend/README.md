# JHK Store: admin panel (Vue 3)

Admin panel for the shop (Vue 3, Vite, Pinia, Vue Router, plain CSS), an installable app (PWA). It is one of three repositories: the Laravel API and **all the guides** (`backend/docs/`) are in [jhkstore_backend](https://github.com/RivoxaTech/jhkstore_backend), the storefront is [jhkstore_frontend](https://github.com/RivoxaTech/jhkstore_frontend). Clone the three side by side in folders named `backend`, `admin-frontend` and `frontend` (see the README of the backend repository): the build writes into `../backend/public/admin-assets`, and the tests (`npm run flow-ui`) use the backend next to it.
It manages products, categories, orders, the customers list and the store settings. The panel carries the
name and logo of the store it manages: they come from the store settings, nothing is hard-coded.

```bash
npm install
npm run dev        # http://localhost:5190   (the backend must run on http://localhost:8002, and `APP_URL` in `backend/.env` must be that address: picture links are built from it)
npm run build      # -> ../backend/public/admin-assets, served by Laravel at http://localhost:8002/admin (ADMIN_BUILD_OUT=/absolute/path puts it somewhere else)
```

The built panel is **not** in any repository: build it on your computer and upload `backend/public/admin-assets` to the server together with the backend. `tools/help-videos/` records the Help videos again from the real panel when it looks different (see `backend/docs/HELP_AND_APP.md`).

## Sign in

There are exactly two accounts, seeded from the backend `backend/.env` (`SEED_DEVELOPER_*` and `SEED_ADMIN_*`, see `backend/.env.example`; run `php artisan db:seed`):
the **developer** (everything except orders) and the **admin** (the client). Authentication is a cookie session (HttpOnly) plus CSRF; there are no tokens in the browser.

## How it talks to the API

* Every call goes to `/api/v1` on the same origin. In dev the Vite server proxies `/api`, `/sanctum` and `/storage` to
  `BACKEND_URL` (see `backend/.env.example`), so the session cookie works without CORS tricks. In production Laravel serves the
  built app at `/admin`.
* `src/api/client.js` is the only place that calls `fetch` (CSRF handling, JSON errors, multipart uploads, file downloads).
* At start the app reads the public `GET /api/v1/store/settings` (no login needed) to show the store name and logo and to
  know the currency. `GET admin/auth/me` returns the admin's role and effective permissions.
* Money is formatted from the currency symbol and decimals of the store settings with the browser's locale
  (`src/utils/format.js`). Nothing is hard-coded to a country or currency.
* Backend requirements: `SANCTUM_STATEFUL_DOMAINS` must include the admin host (`localhost:5190` and `localhost:8002` locally).
  Production: HTTPS, `SESSION_SECURE_COOKIE=true`, the real admin domain in `SANCTUM_STATEFUL_DOMAINS` and strong passwords.

## Pages (sidebar)

Dashboard · Orders (list with status tabs and payment filters, detail, reject with a reason, bank-transfer proof check, delivery fee confirmation, printable invoice and packing slip) · Products (list, form with options, prices, stock, tags, pictures, content tabs) ·
Categories (two levels) · Customers (read-only list, search, CSV export) · **Discounts** and **Coupons** (compact lists, searchable product / category picker, usages of a coupon) · Settings · Access Control (developer only).

**Settings** is one page with tabs: General, Branding, **Banners** (the full banner manager: create, edit, delete, reorder, activate, placements, pictures), Footer & Social, Currency & Tax, Orders & Delivery, **Delivery** (method, fees and the delivery zone manager), **Payments** (COD, bank transfer, bank accounts), **Store** (open / closed, notices), **Theme** (developer only, with a live preview card) and SEO.
The tabs and their fields come from the API (`GET admin/settings`), so a new tab appears without panel changes. Field types: string, text, bool, int, money, percent, file, links, accounts (bank accounts), color (`#RRGGBB`, picker plus hex box) and choice (a select of the options the API sends). Only the changed keys are saved.

## Permissions

`auth.can('products.manage')` (and `auth.can('settings.*')` for "any Settings tab") decide what is shown: sidebar items, Settings tabs and action buttons without permission are hidden, a URL without permission shows
"no access". The API enforces the same rules (403). The developer switches the admin's permissions in **Access Control**; the developer never sees orders (no sidebar item, no order figures on the dashboard).
See `../docs/ACCESS_CONTROL.md`.

## Browser smoke test

`npm run e2e` (needs Chrome, the API running on a freshly seeded database with the DemoSeeder, and `npm run build`): checks both accounts (sidebar, tabs, forbidden pages, no order data for the developer), every screen,
the product-edit route, the Settings tabs (Delivery, Payments, Store, and the developer-only Theme tab with its live preview) and links editor, and that the developer's Access Control switches change what the admin sees. It restores the admin's default access afterwards.

`npm run flow-ui` drives the order, discount, coupon, delivery zone and payment screens on the real app (it places bank-transfer orders and uploads a proof through the public API; run it on a freshly seeded database with the DemoSeeder). It puts back the settings it changes.
See the header of `e2e/smoke.mjs` for the environment variables.

## Notes

* **Tax** - a product can have its own tax rate; empty means the store default. Orders show the tax line with the store's tax label, either included in the prices or added to the total, as configured.
* **Invoices** - the printed invoice and packing slip use the store name, logo, address, phone and e-mail from the settings.
