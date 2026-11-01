# Shop Admin (Vue 3)

Admin panel for the neutral e-commerce backend in the parent folder (Vue 3, Vite, Pinia, Vue Router, plain CSS).
It manages the catalogue (categories, products), orders and the store settings. The panel carries the
name and logo of the store it manages: they come from the store settings, nothing is hard-coded.

```bash
npm install
npm run dev        # http://localhost:5190   (the backend must run on http://localhost:8000)
npm run build      # -> ../public/admin-assets, served by Laravel at http://localhost:8000/admin
```

## Sign in

Use the admin that the backend seeds from its `.env` (`SEED_ADMIN_*`, see the backend `.env.example`; run the seeder
once with `php artisan db:seed`). Authentication is a cookie session (HttpOnly) plus CSRF; there are no tokens in the browser.

## How it talks to the API

* Every call goes to `/api/v1` on the same origin. In dev the Vite server proxies `/api`, `/sanctum` and `/storage` to
  `BACKEND_URL` (see `.env.example`), so the session cookie works without CORS tricks. In production Laravel serves the
  built app at `/admin`.
* `src/api/client.js` is the only place that calls `fetch` (CSRF handling, JSON errors, multipart uploads).
* At start the app reads the public `GET /api/v1/store/settings` (no login needed) to show the store name and logo and to
  know the currency. After login `GET admin/products/options` provides the currency and the feature switches
  (`features.rewards`), which the navigation and the pages react to.
* Money is formatted from the currency symbol and decimals of the store settings with the browser's locale
  (`src/utils/format.js`). Nothing is hard-coded to a country or currency.
* Backend requirements: `SANCTUM_STATEFUL_DOMAINS` must include the admin host (`localhost:5190` and `localhost:8000` locally).
  Production: HTTPS, `SESSION_SECURE_COOKIE=true`, the real admin domain in `SANCTUM_STATEFUL_DOMAINS` and a new admin password.

## Pages

Dashboard, Categories (two levels: category and sub-category), Banners (per placement, drag to reorder), Users & roles (super admin only), Products (list, form with options, prices, stock,
tags, pictures, content tabs), Orders (list, detail, status changes, printable invoice and packing slip) and Settings.

## Features that depend on settings

* **Reward points** - the "Reward points" field of a product option, the points rows of the order screens and the
  "Reward points" settings group appear only while reward points are enabled.
* **Tax** - a product can have its own tax rate; empty means the store default. Orders show the tax line with the store's
  tax label, either included in the prices or added to the total, as configured.
* **Settings** - the Settings page is generated from the tabs, sections and fields the API returns (General, Branding, Footer & Social, Currency & Tax, Orders & Delivery,
  Features, SEO): switches, numbers, text, picture uploads (saved at once) and the ordered links list (drag to reorder). Only the changed keys are saved.
  The store name, logo, favicon, currency and features are refreshed after saving.
* **Roles** - `GET admin/auth/me` returns the admin's role and permissions (`auth.can('catalog.manage')`). Menu items, pages and action buttons the role does not allow
  are hidden or read-only; a page without permission shows "no access". The API enforces the same rules (403).
* **Invoices** - the printed invoice and packing slip use the store name, logo, address, phone and e-mail from the settings.
