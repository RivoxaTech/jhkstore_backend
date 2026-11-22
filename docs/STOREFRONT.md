# The storefront (`frontend/`)

The customer website of JHK Store: a **Nuxt 4** app (Vue 3, TypeScript, Tailwind CSS 4) that is **generated into plain static files** (`nuxt generate`). What search engines read (products, categories, texts, the theme, titles, canonical links, Open Graph, JSON-LD) is fetched from the Laravel API at **build time** and baked into the HTML. What must be current (price, stock, the cart, coupons, checkout, order tracking, search) is fetched from the API **in the browser**. The brief it follows is [STOREFRONT_BRIEF.md](STOREFRONT_BRIEF.md); the API it talks to is [STOREFRONT-API.md](STOREFRONT-API.md).

There are **no Nuxt server routes, no server middleware, no API routes, no server actions and nothing host-specific**, so the same `.output/public` folder runs on Cloudflare Pages, a cPanel folder, Netlify or Vercel.

## Folder structure

```
frontend/
  nuxt.config.ts          static preset, modules, fonts and prerender routes (read from the API at build time), routeRules
  .env.example            NUXT_PUBLIC_API_BASE, NUXT_PUBLIC_SITE_URL
  public/                 _redirects, _headers (Cloudflare Pages / Netlify), .htaccess.example (cPanel). No pictures: logo, banners and the agency logo come from the admin panel
  scripts/serve-static.mjs   a tiny static server that behaves like the hosts (files, then the app shell for /product/*, then 404.html)
  tests/e2e.mjs           browser tests of the generated site; tests/shots.mjs takes the screenshots
  app/
    app.vue, error.vue    the theme as CSS variables, the layout; the 404 page
    config/site.ts        menu, the trust bar, footer link lists, home headings: only what the admin panel does NOT control. EDIT PER CLIENT
    content/*.ts          the static pages (about, shipping, returns, terms, privacy): EDIT PER CLIENT
    assets/css/main.css   the design tokens (CSS variables), buttons, form controls
    layouts/default.vue   header, footer, cart drawer, toasts
    pages/                index, shop, category/[slug], category/[slug]/[sub], product/[slug], cart, checkout, order/[number], track, wholesale (the old /contact redirects to it), about, ...
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
| the static pages, wholesale (details, product list of the wholesale form), `sitemap.xml`, `robots.txt` | the wholesale enquiry, the cart, the quote, coupons, orders, the payment proof, order tracking |

Client-only pages (`/cart`, `/checkout`, `/track`, `/order/*`) are a prerendered **empty shell**, `noindex`, that the browser fills in.

A product or category that is created **after** the last build has no HTML file. The hosts' fallback (`_redirects`, `.htaccess`) serves the app shell (`200.html`) for `/product/*`, `/category/*` and `/order/*`, and the page loads the product from the API in the browser. It shows up in search engines after the next rebuild (1 to 3 minutes: see *the rebuild hook* below).

## Editing a client's texts

* **`app/config/site.ts`**: the menu, the label of the hero button while the admin panel has no hero banner, the four trust-bar items (Safe & Non-Toxic, Fast Delivery, Premium Goods, Certified Item), the footer link lists, the "Our Collection" heading (`{count}` = products shown; `*words*` = accent colour) and how many products the home page shows (`homeLimit`, 12).
* **`app/content/*.ts`**: the five static pages. Plain TypeScript objects (title, description, blocks: headings, paragraphs, lists, a note, features, a call to action). `{store}`, `{email}`, `{phone}`, `{address}` and `{hours}` are filled from the store settings. Sections that clients usually change are marked `EDIT PER CLIENT`; the policies are **generic templates, not legal advice**: the client must review them.
* **No analytics, advertising or tracking script of any kind** is in the site; do not add one without updating the privacy policy.

## How the admin panel maps to the design

| In the admin panel | On the website |
|---|---|
| Settings → Theme: primary, primary contrast, accent, background, surface, text, muted, border, fonts, corner radius, shadow | CSS variables `--c-primary`, `--c-accent`, ... (`app.vue`); the dark footer is the primary colour darkened; buttons get a readable shade automatically (see below) |
| Settings → Branding: logo, favicon | header and footer logo (the footer shows it in white; no logo = the store name as text), browser tab icon |
| Settings → General: tagline | the eyebrow above the hero title (`Play. Learn. Grow.` becomes `PLAY • LEARN • GROW`) and the line under the footer logo. Empty tagline = neither is shown (nothing is fixed in the storefront) |
| Banners → `home_hero` | the hero (title, subtitle, button, link, picture; a mobile picture on phones). **Several banners = a slider** (autoplay, dots, swipe), one = static. **Words between `*asterisks*` in the title are shown in the accent colour** (the two-colour heading) |
| Banners → `home_secondary` | the gift strip at the bottom of the home page (first banner; same rules). No banner = no strip |
| Products: name, short description, badge text, tags, featured, sold out, options, bulk prices, videos, SEO fields | cards, product pages, the Featured section, "Sold out", bulk price hints, the video in the gallery, titles and descriptions |
| Settings → Footer & Social | the footer social icons, footer text and copyright, and the **"Developed by [logo]"** credit at the very bottom (agency name, link and logo; default **Rivoxa Tech**, https://rivoxa.tech; developer only). The same credit is at the bottom of the admin panel's sidebar and on its login page |
| Settings → Payments, Delivery, Store | the payment methods and bank accounts at checkout, delivery quotes, the closed-store banner |

**Contrast.** White text on the brand colour must be readable. `themeCss()` checks the contrast of the theme's primary colour (and accent) with its text colour and, when it is below 4.5:1 (WCAG AA), darkens it in small steps for **buttons, pills and badges only** (`--c-primary-btn`, `--c-accent-btn`); large headings keep the brand colour. A client with a lighter brand colour therefore still gets readable buttons.

**No hero banner?** The hero then shows the store name, the tagline and a *Shop now* button on a soft tint of the theme colour, without a picture. Put a banner in the admin panel to get the real hero.

## The theme (Settings → Theme, not in the code)

The colours, fonts, corner radius and shadow are **only** read from the admin panel; the storefront has neutral defaults (the ones Settings → Theme starts with) and no brand colour of its own. The admin panel itself wears the same primary colour (darkened when white text on it would be hard to read), so both match the logo.

The values below were taken from the logo `design/logo.png` (teal gradient `#29B3BB` to `#1399A0`, the wordmark `#16242A`) and entered in the **dev database**. Enter the same values for the client:

| Setting | Value |
|---|---|
| Primary colour (`theme_primary`) | `#3BBFCC` (buttons get a darker readable shade automatically) |
| Text on the primary colour (`theme_primary_contrast`) | `#FFFFFF` |
| Accent colour (`theme_accent`, the second colour of two-colour headings) | `#1A93A0` |
| Page background (`theme_bg`) | `#F5FBFC` |
| Card background (`theme_surface`) | `#FFFFFF` |
| Text colour (`theme_text`) | `#16242A` |
| Muted text colour (`theme_muted`) | `#4F6469` |
| Border colour (`theme_border`) | `#D9EDF0` |
| Heading font / body font | `Poppins` / `Poppins` (the design uses a rounded geometric font) |
| Corner radius | `16px` |
| Shadow | `soft` |

The dark footer is `#024850` in the design; it is **derived** (the primary colour mixed with 48 % black = `#04494C`), so it follows any theme. The design's soft gift-banner colour is `#CFEBEE`, the "Our Collection" pill is the primary colour.

## Framework version and support

The storefront runs on **Nuxt 4** (4.6.x, Vue 3.5, `@nuxtjs/sitemap` 9, `@nuxtjs/robots` 7, `zod` 4 as the modules' peer). Nuxt 3 reached its end of life on 31 July 2026 and the current SEO modules require Nuxt 4.6 or newer, so the site was moved to Nuxt 4 on 2026-11-08. The `app/` folder layout is the default of Nuxt 4 (no `future.compatibilityVersion` flag any more). It still is a **pure static build**: `nuxt generate` writes `.output/public` and nothing else (no `server/` folder, no Nitro server output). Node 22.22.3+ or 24.15+ is required by the modules.

Harmless messages of `npm run generate`: *"url ... should not be localhost"* (set `NUXT_PUBLIC_SITE_URL` for a real build), a Rollup note about an unresolved Nitro cache driver that a static build never uses, and an annotation comment warning. A Nuxt 4 page that does not exist (for example `/no-such-page`) logs a bare `[NUXT_E1005]` in the browser console while the friendly 404 page is shown; that is Nuxt's own message and the browser tests expect it for that one case.

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
2. **Production branch**: the branch you deploy (for example `main`). **Root directory**: empty (the storefront is the repository `jhkstore_frontend`, its `package.json` is at the root). **Framework preset**: Nuxt.js (or None). **Build command**: `npm run generate`. **Build output directory**: `.output/public`.
3. **Environment variables** (Production and Preview): `NUXT_PUBLIC_API_BASE` = `https://api.example.com/api/v1/store`, `NUXT_PUBLIC_SITE_URL` = `https://www.example.com`, and `NODE_VERSION` = `24` (Nuxt 4 and its modules need Node 22.22.3 or newer, or 24.15 or newer).
4. Save and deploy. The first build must reach the API from Cloudflare's build machines (the API domain has to be public; behind Cloudflare's proxy it is fine).
5. **Custom domain**: *Custom domains* → add `www.example.com` (and redirect the bare domain to it). Then update `NUXT_PUBLIC_SITE_URL` and rebuild once.
6. **The API behind Cloudflare**: give the Laravel app its own sub-domain, for example `api.example.com`, proxied (orange cloud) through Cloudflare. The catalogue endpoints already send `Cache-Control: public, s-maxage=300` and an `ETag`; everything else (cart, quote, orders, admin) is `no-store`, so the proxy can sit in front safely.
7. **CORS** in `backend/.env`: `FRONTEND_URLS=https://www.example.com` (comma separated; add `http://localhost:3000` for local work) and, for the preview sites of Cloudflare Pages, `FRONTEND_URL_PATTERNS=#^https://[a-z0-9-]+\.your-project\.pages\.dev$#`. Run `php artisan config:cache` after changing `.env`.
8. **The deploy hook for the admin panel**: Pages project → *Settings* → *Builds & deployments* → *Deploy hooks* → *Add deploy hook* (name: `admin-panel`, branch: production). Copy the URL, then in the admin panel (as the developer) *Settings → Website* paste it as the **rebuild hook address**, switch on *Rebuild the website automatically* and press *Rebuild website now* (it must say OK). Details and the queue worker requirement: [NEW_CLIENT.md](NEW_CLIENT.md) section 6b.
9. **Check `_redirects`**: `frontend/public/_redirects` makes `/product/*`, `/category/*` and `/order/*` fall back to the app shell (`200.html`). After the first deploy open a product address that does not exist yet (or create a product and open it before the rebuild finishes) and make sure it shows the product, not a 404. If the host serves your 404 page instead, remove the rules, delete `404.html` from the output, or ask the host to treat the build as a single-page app: the page then renders client side.

`_headers` adds the cache rules (hashed files for a year, HTML always revalidated) and basic security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`). It deliberately has **no Content-Security-Policy**: a wrong one would break the checkout; add one once the final hosts are known.

## Deploy on Cloudflare **Workers** (static assets): what `wrangler.jsonc` and `worker/index.js` are for

A Cloudflare project made as a **Worker** (address `<name>.<account>.workers.dev`, "Workers Builds") is not Cloudflare *Pages*, and one thing differs: **the `_redirects` rewrite rules (`/product/* /200.html 200`) do not work there**: the Worker turns them into a redirect to `/200`, or answers `404` with an empty page. The visible symptom: a toy added after the last build, and the order page after a reload (`/order/ORD-...`), end on "This page can't be found (HTTP ERROR 404)".

The repository therefore carries the setting itself:

* `wrangler.jsonc`: the Worker's name (`jhkstore-frontend`, **it must be the name of the Worker that already exists**, or a second one is made), the static files (`./.output/public`, served first) and the small script `worker/index.js`.
* `worker/index.js`: runs only for an address that is not a file of the build. `/product/<slug>`, `/category/<slug>[/<sub>]` and `/order/<number>` get the **app shell** (`200.html`) **with their own address and the status 200**: the shell then loads the page from the API. Any other unknown address gets the friendly 404 page with the status 404. (Serving the home page instead does not work: the app then goes back to `/`.) Tested with Cloudflare's own local runner (`npx wrangler dev`) and a real browser.
* The `_redirects` file of the repository was renamed `public/_redirects.example`: **Cloudflare Pages and Netlify** need it as `_redirects` (copy it back), a Worker must not have it.

In the Cloudflare dashboard (the Worker → Settings → **Build**): Root directory empty (`/`), **Build command `npm run generate`**, **Deploy command `npx wrangler deploy`** (nothing added to it: no `--assets`), Build variables `NUXT_PUBLIC_API_BASE`, `NUXT_PUBLIC_SITE_URL`, `NODE_VERSION=24`. Check the build log of the first build after a change of these settings: "Uploaded N files" and "Deployed".

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

## SEO: what every page tells the search engines (2026-11-21)

Everything below is written into the HTML at build time (`useSeo`, `app/utils/schema.ts`), so a crawler sees it without running any script. Nothing is invented: a field is only written when the shop has the value. The builders have unit tests (`node tests/seo-schema.mjs`) and the generated site is checked by `tests/e2e.mjs` (S14 and S14b).

| Where | What |
|---|---|
| every page | own title and description, canonical address, `hreflang` (en and x-default), Open Graph and Twitter tags with the picture and its description, `og:locale`, `robots` with `max-image-preview:large, max-snippet:-1, max-video-preview:-1`; a filtered, searched, cart, checkout, tracking or order view is `noindex` |
| home | `Organization` (logo, contact point, address and `sameAs` links to the social profiles, when set in the settings) and `WebSite` with the search box |
| shop and category pages | `CollectionPage` with an `ItemList` of the first 24 toys, and the breadcrumb |
| product page | `Product` (url, sku, pictures, category), an `Offer` (or `AggregateOffer` for several options) with price, currency, availability and seller, a `VideoObject` for every **uploaded** video that has a cover and a date, the breadcrumb, and the price and stock as `product:*` tags for Facebook, Pinterest and WhatsApp |
| information pages | `WebPage` (`AboutPage` for About) and the breadcrumb |
| `sitemap.xml` | every product (with its picture and date), category, sub-category and the text pages; the home and shop pages carry the date of the newest change; no `/contact` (it redirects) |
| `robots.txt` | blocks the cart, checkout, tracking, order pages and the app shell `/200` and `/404`; names the sitemap |
| `build.json` | `noindex` through the headers file; the admin panel reads it |

**Shipping and return data for Google Shopping** (`OfferShippingDetails`, `MerchantReturnPolicy`) is added to the offer only when the shop has the facts: a **flat** delivery fee, an ISO currency code (MUR, not "Rs") and the **store country as a two-letter code** (Settings → General → Country, for example `MU`). Without the country they are left out, on purpose: they cannot be stated correctly.

**Not code, to do once per shop:** add the domain to Google Search Console (a Domain property, verified with a DNS TXT record) and send `https://<domain>/sitemap.xml`; the same in Bing Webmaster Tools; switch on "Always Use HTTPS" in Cloudflare; upload a default share picture (1200 x 630) in Settings → SEO; replace test products and categories before launch; real product text (not a copy of the same sentence) and a picture on every product do more for ranking than any tag.
