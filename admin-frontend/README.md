# Shop Admin (Vue 3)

Admin panel for the lean store backend in the parent folder (Vue 3, Vite, Pinia, Vue Router, plain CSS).
It manages products, categories, orders, the customers list and the store settings. The panel carries the
name and logo of the store it manages: they come from the store settings, nothing is hard-coded.

```bash
npm install
npm run dev        # http://localhost:5190   (the backend must run on http://localhost:8000)
npm run build      # -> ../public/admin-assets, served by Laravel at http://localhost:8000/admin
```

## Sign in

There are exactly two accounts, seeded from the backend `.env` (`SEED_DEVELOPER_*` and `SEED_ADMIN_*`, see `.env.example`; run `php artisan db:seed`):
the **developer** (everything except orders) and the **admin** (the client). Authentication is a cookie session (HttpOnly) plus CSRF; there are no tokens in the browser.

## How it talks to the API

* Every call goes to `/api/v1` on the same origin. In dev the Vite server proxies `/api`, `/sanctum` and `/storage` to
  `BACKEND_URL` (see `.env.example`), so the session cookie works without CORS tricks. In production Laravel serves the
  built app at `/admin`.
* `src/api/client.js` is the only place that calls `fetch` (CSRF handling, JSON errors, multipart uploads, file downloads).
* At start the app reads the public `GET /api/v1/store/settings` (no login needed) to show the store name and logo and to
  know the currency. `GET admin/auth/me` returns the admin's role and effective permissions.
* Money is formatted from the currency symbol and decimals of the store settings with the browser's locale
  (`src/utils/format.js`). Nothing is hard-coded to a country or currency.
* Backend requirements: `SANCTUM_STATEFUL_DOMAINS` must include the admin host (`localhost:5190` and `localhost:8000` locally).
  Production: HTTPS, `SESSION_SECURE_COOKIE=true`, the real admin domain in `SANCTUM_STATEFUL_DOMAINS` and strong passwords.

## Pages (sidebar)

Dashboard · Orders (list, detail, status changes, printable invoice and packing slip) · Products (list, form with options, prices, stock, tags, pictures, content tabs) ·
Categories (two levels) · Customers (read-only list, search, CSV export) · Settings · Access Control (developer only).

**Settings** is one page with tabs: General, Branding, **Banners** (the full banner manager: create, edit, delete, reorder, activate, placements, pictures), Footer & Social, Currency & Tax, Orders & Delivery, SEO.
The tabs and their fields come from the API (`GET admin/settings`), so a new tab (for example *Payment details*) appears without panel changes. Only the changed keys are saved.

## Permissions

`auth.can('products.manage')` (and `auth.can('settings.*')` for "any Settings tab") decide what is shown: sidebar items, Settings tabs and action buttons without permission are hidden, a URL without permission shows
"no access". The API enforces the same rules (403). The developer switches the admin's permissions in **Access Control**; the developer never sees orders (no sidebar item, no order figures on the dashboard).
See `../docs/ACCESS_CONTROL.md`.

## Browser smoke test

`npm run e2e` (needs Chrome, the API running on a freshly seeded database with the DemoSeeder, and `npm run build`): checks both accounts (sidebar, tabs, forbidden pages, no order data for the developer), every screen,
the product-edit route, the Settings tabs and links editor, and that the developer's Access Control switches change what the admin sees. It restores the admin's default access afterwards.
See the header of `e2e/smoke.mjs` for the environment variables.

## Notes

* **Tax** - a product can have its own tax rate; empty means the store default. Orders show the tax line with the store's tax label, either included in the prices or added to the total, as configured.
* **Invoices** - the printed invoice and packing slip use the store name, logo, address, phone and e-mail from the settings.
