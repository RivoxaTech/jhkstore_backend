# The storefront (`frontend/`)

The customer website of JHK Store: a **Nuxt 3** app (Vue 3, TypeScript, Tailwind CSS 4) that is **generated into plain static files** (`nuxt generate`). What search engines read (products, categories, texts, the theme, titles, canonical links, Open Graph, JSON-LD) is fetched from the Laravel API at **build time** and baked into the HTML. What must be current (price, stock, the cart, coupons, checkout, order tracking, search) is fetched from the API **in the browser**. The brief it follows is [STOREFRONT_BRIEF.md](STOREFRONT_BRIEF.md); the API it talks to is [STOREFRONT-API.md](STOREFRONT-API.md).

There are **no Nuxt server routes, no server middleware, no API routes, no server actions and nothing host-specific**, so the same `.output/public` folder runs on Cloudflare Pages, a cPanel folder, Netlify or Vercel.

## Folder structure

```
frontend/
  nuxt.config.ts          static preset, modules, fonts and prerender routes (read from the API at build time), routeRules
  .env.example            NUXT_PUBLIC_API_BASE, NUXT_PUBLIC_SITE_URL
  public/                 _redirects, _headers (Cloudflare Pages / Netlify), .htaccess.example (cPanel), images/ (logo, hero and gift fallbacks)
  scripts/serve-static.mjs   a tiny static server that behaves like the hosts (files, then the app shell for /product/*, then 404.html)
  tests/e2e.mjs           browser tests of the generated site; tests/shots.mjs takes the screenshots
  app/
    app.vue, error.vue    the theme as CSS variables, the layout; the 404 page
    config/site.ts        menu, hero / gift fallbacks, the trust bar, footer texts: EDIT PER CLIENT
    content/*.ts          the static pages (about, shipping, returns, terms, privacy): EDIT PER CLIENT
    assets/css/main.css   the design tokens (CSS variables), buttons, form controls
    layouts/default.vue   header, footer, cart drawer, toasts
    pages/                index, shop, category/[slug], category/[slug]/[sub], product/[slug], cart, checkout, order/[number], track, contact, about, ...
    components/           header, footer, hero, trust bar, product card and grid, gallery, cart drawer, order view, forms
    composables/          useApi, useSettings (+ theme), useCart, useSeo, useMoney, useGrid, useToast, useOrderMemory
    plugins/settings.ts   loads GET /settings before the first page renders
```

## Environment variables

| Variable | Meaning |
|---|---|
| `NUXT_PUBLIC_API_BASE` | The storefront API of the Laravel backend, for example `https://api.example.com/api/v1/store` (local: `http://127.0.0.1:8002/api/v1/store`). Used **at build time** (the pages are baked from it) and **in the browser** |
| `NUXT_PUBLIC_SITE_URL` | The public address of this website (canonical links, Open Graph, `sitemap.xml`, `robots.txt`). No trailing slash |

`nuxt generate` **stops with an error when the API cannot be reached**: an empty site must never be published by accident.

## Build time and live data

| Baked into the HTML at build time | Fetched in the browser |
|---|---|
| store settings, theme (as CSS variables), logo, footer, social links | `GET /settings` again after the page is shown (store open or closed, payment methods) |
| home: banners, every product (most popular first), featured products, categories | the product cards ask for fresh prices once the page is shown |
| shop and category pages (first 24 products), the category tree | filters, sort, price range, in-stock, search `?q=`, "Load more" |
| every product page: name, description, options, pictures with sizes, videos, tabs, related products, `seo`, JSON-LD | the product is read again: price, stock, delivery date, a video added after the build |
| the static pages, contact (details, product list of the wholesale form), `sitemap.xml`, `robots.txt` | the wholesale enquiry, the cart, the quote, coupons, orders, the payment proof, order tracking |

Client-only pages (`/cart`, `/checkout`, `/track`, `/order/*`) are a prerendered **empty shell**, `noindex`, that the browser fills in.

A product or category that is created **after** the last build has no HTML file. The hosts' fallback (`_redirects`, `.htaccess`) serves the app shell (`200.html`) for `/product/*`, `/category/*` and `/order/*`, and the page loads the product from the API in the browser. It shows up in search engines after the next rebuild (1 to 3 minutes: see *the rebuild hook* below).

## Editing a client's texts

* **`app/config/site.ts`**: the menu, the hero and gift-banner fallback texts (used while the admin panel has no banner), the four trust-bar items (Safe & Non-Toxic, Fast Delivery, 24/7 Support, Easy Returns), the footer links and tagline (right side), the "Our Collection" heading (`{count}` = products shown; `*words*` = accent colour) and how many products the home page shows (`homeLimit`, 12).
* **`app/content/*.ts`**: the five static pages. Plain TypeScript objects (title, description, blocks: headings, paragraphs, lists, a note, features, a call to action). `{store}`, `{email}`, `{phone}`, `{address}` and `{hours}` are filled from the store settings. Sections that clients usually change are marked `EDIT PER CLIENT`; the policies are **generic templates, not legal advice**: the client must review them.
* **No analytics, advertising or tracking script of any kind** is in the site; do not add one without updating the privacy policy.

## How the admin panel maps to the design

| In the admin panel | On the website |
|---|---|
| Settings → Theme: primary, primary contrast, accent, background, surface, text, muted, border, fonts, corner radius, shadow | CSS variables `--c-primary`, `--c-accent`, ... (`app.vue`); the dark footer is the primary colour darkened; buttons get a readable shade automatically (see below) |
| Settings → Branding: logo, favicon | header and footer logo (the footer shows it in white), browser tab icon |
| Settings → General: tagline | the eyebrow above the hero title (`Play. Learn. Grow.` becomes `PLAY • LEARN • GROW`) and the line under the footer logo |
| Banners → `home_hero` | the hero (title, subtitle, button, link, picture; a mobile picture on phones). **Several banners = a slider** (autoplay, dots, swipe), one = static. **Words between `*asterisks*` in the title are shown in the accent colour** (the two-colour heading) |
| Banners → `home_secondary` | the "Perfect Gift" strip at the bottom of the home page (first banner; same rules) |
| Products: name, short description, badge text, tags, featured, sold out, options, bulk prices, videos, SEO fields | cards, product pages, the Featured section, "Sold out", bulk price hints, the video in the gallery, titles and descriptions |
| Settings → Footer & Social | the footer social icons and copyright |
| Settings → Payments, Delivery, Store | the payment methods and bank accounts at checkout, delivery quotes, the closed-store banner |

**Contrast.** White text on the brand colour must be readable. `themeCss()` checks the contrast of the theme's primary colour (and accent) with its text colour and, when it is below 4.5:1 (WCAG AA), darkens it in small steps for **buttons, pills and badges only** (`--c-primary-btn`, `--c-accent-btn`); large headings keep the brand colour. A client with a lighter brand colour therefore still gets readable buttons.

## The theme values extracted from the reference design

Read from `design/refrence ui.jpeg` and entered in the **dev database** (Settings → Theme). Enter the same values for the client:

| Setting | Value |
|---|---|
| Primary colour (`theme_primary`) | `#09989E` |
| Text on the primary colour (`theme_primary_contrast`) | `#FFFFFF` |
| Accent colour (`theme_accent`, the second colour of two-colour headings) | `#0A9697` |
| Page background (`theme_bg`) | `#F5FBFB` |
| Card background (`theme_surface`) | `#FFFFFF` |
| Text colour (`theme_text`) | `#15414A` |
| Muted text colour (`theme_muted`) | `#4B6B71` (the design's `#5B7A80` is 4.1:1 on the light teal tint, so it is darkened to pass AA) |
| Border colour (`theme_border`) | `#DCEBEE` |
| Heading font / body font | `Poppins` / `Poppins` (the design uses a rounded geometric font) |
| Corner radius | `16px` |
| Shadow | `soft` |

The dark footer is `#024850` in the design; it is **derived** (the primary colour mixed with 48 % black = `#04494C`), so it follows any theme. The design's soft gift-banner colour is `#CFEBEE`, the "Our Collection" pill is the primary colour.

## Run it locally

```bash
# the API with the demo catalogue (backend/): php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder && php artisan serve --port=8002
cd frontend
cp .env.example .env
npm install
npm run dev          # http://localhost:3000, live reload (needs the API)
npm run generate     # the static site in .output/public
npm run preview      # serves .output/public on http://127.0.0.1:3000 the way the hosts do (200.html fallback, 404.html)
npm run test:e2e     # the browser tests (the API on 8002 with the demo data and a generated site)
```

`http://localhost:3000` is in `FRONTEND_URLS` of `backend/.env.example`, so the browser may call the API from it. Another port needs adding to `FRONTEND_URLS`.

## Deploy on Cloudflare Pages

1. Cloudflare dashboard → *Workers & Pages* → *Create* → *Pages* → connect the Git repository.
2. **Production branch**: the branch you deploy (for example `main`). **Root directory**: `frontend`. **Framework preset**: Nuxt.js (or None). **Build command**: `npm run generate`. **Build output directory**: `.output/public`.
3. **Environment variables** (Production and Preview): `NUXT_PUBLIC_API_BASE` = `https://api.example.com/api/v1/store`, `NUXT_PUBLIC_SITE_URL` = `https://www.example.com`, and `NODE_VERSION` = `22` (or `24`).
4. Save and deploy. The first build must reach the API from Cloudflare's build machines (the API domain has to be public; behind Cloudflare's proxy it is fine).
5. **Custom domain**: *Custom domains* → add `www.example.com` (and redirect the bare domain to it). Then update `NUXT_PUBLIC_SITE_URL` and rebuild once.
6. **The API behind Cloudflare**: give the Laravel app its own sub-domain, for example `api.example.com`, proxied (orange cloud) through Cloudflare. The catalogue endpoints already send `Cache-Control: public, s-maxage=300` and an `ETag`; everything else (cart, quote, orders, admin) is `no-store`, so the proxy can sit in front safely.
7. **CORS** in `backend/.env`: `FRONTEND_URLS=https://www.example.com` (comma separated; add `http://localhost:3000` for local work) and, for the preview sites of Cloudflare Pages, `FRONTEND_URL_PATTERNS=#^https://[a-z0-9-]+\.your-project\.pages\.dev$#`. Run `php artisan config:cache` after changing `.env`.
8. **The deploy hook for the admin panel**: Pages project → *Settings* → *Builds & deployments* → *Deploy hooks* → *Add deploy hook* (name: `admin-panel`, branch: production). Copy the URL, then in the admin panel (as the developer) *Settings → Website* paste it as the **rebuild hook address**, switch on *Rebuild the website automatically* and press *Rebuild website now* (it must say OK). Details and the queue worker requirement: [NEW_CLIENT.md](NEW_CLIENT.md) section 6b.
9. **Check `_redirects`**: `frontend/public/_redirects` makes `/product/*`, `/category/*` and `/order/*` fall back to the app shell (`200.html`). After the first deploy open a product address that does not exist yet (or create a product and open it before the rebuild finishes) and make sure it shows the product, not a 404. If the host serves your 404 page instead, remove the rules, delete `404.html` from the output, or ask the host to treat the build as a single-page app: the page then renders client side.

`_headers` adds the cache rules (hashed files for a year, HTML always revalidated) and basic security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`). It deliberately has **no Content-Security-Policy**: a wrong one would break the checkout; add one once the final hosts are known.

## Host the same build on cPanel

1. Build locally (`npm run generate` with the production `.env`) or on the server if Node exists.
2. Upload the **contents** of `.output/public` to the folder of the website (for example `public_html`).
3. Copy `public/.htaccess.example` to `.htaccess` in that folder: it serves `200.html` for product, category and order addresses that were created after the build, sets the cache and security headers and uses `404.html` for unknown pages.
4. After a change in the admin panel the site must be rebuilt and uploaded again (the deploy hook is a Cloudflare / Netlify / Vercel feature; on cPanel use `shop:rebuild` only if a hook of your own re-runs your build). Netlify and Vercel use the same `_redirects` / `_headers` files or their own equivalents.

## Launch checklist

- [ ] The API is live on its own domain with HTTPS; `backend/.env` has `APP_URL`, `FRONTEND_URLS` (and `FRONTEND_URL_PATTERNS` if previews are used) and `SESSION_SECURE_COOKIE=true`; the queue worker and the cron `schedule:run` run
- [ ] Store settings filled in: name, tagline, logo, favicon, contact details, footer links, currency, delivery, payments (bank accounts), SEO defaults and share picture, **Theme** (values above), banners (`home_hero`, `home_secondary`)
- [ ] The catalogue has pictures for every product; products have short descriptions (the card shows two lines of it)
- [ ] `app/config/site.ts` and the five pages in `app/content/` reviewed by the client (delivery times, return days, legal name; **the policies are templates and need a legal review**)
- [ ] `NUXT_PUBLIC_API_BASE` and `NUXT_PUBLIC_SITE_URL` set on the host; `npm run generate` succeeds; `sitemap.xml` and `robots.txt` open; a product page's HTML (View source) holds the name, the price and `application/ld+json`
- [ ] The deploy hook is pasted in Settings → Website and *Rebuild website now* says OK
- [ ] A test order with cash on delivery, a test order with bank transfer and a screenshot upload, tracking by order number and phone, a coupon, the wholesale form: all done on the live site, then the test orders cancelled
- [ ] The e-mail setting *Order tracking page* (`storefront_track_url`) is `https://www.example.com/track`
- [ ] Search Console: the sitemap `https://www.example.com/sitemap.xml` is submitted
