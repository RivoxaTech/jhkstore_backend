# Storefront brief

For whoever builds (or changes) the customer website of this store. Read it together with [STOREFRONT-API.md](STOREFRONT-API.md) (every endpoint and field) and [openapi.json](openapi.json). The Nuxt project in `frontend/` follows this brief; its own guide is [STOREFRONT.md](STOREFRONT.md).

**The product in one paragraph.** A neutral e-commerce base, one deployment per client. The website is a **static site** (prerendered HTML on a CDN) that talks to a Laravel API in the browser for everything that must be live. There are **no customer accounts** (guest checkout) and **no CMS pages**: About, Privacy, Terms, Shipping and Returns are ordinary static pages inside the storefront code.

## 1. Which endpoint feeds which page

`{API}` = `NUXT_PUBLIC_API_BASE`, e.g. `https://api.example.com/api/v1/store`.

| Page | Prerendered? | Build time (baked into the HTML) | In the browser (live) |
|---|---|---|---|
| Every page: header, footer, theme | yes | `GET {API}/settings` (store name, logo, tagline, contact, social links, SEO defaults, **theme**, currency) | the same call again to pick up a change (`store.open`) |
| Home `/` | yes | `settings`, `banners?placement=home_hero`, `banners?placement=home_secondary`, `products?sort=popularity` (all pages), `products?featured=1`, `categories` | prices and stock refresh from `products` |
| Shop `/shop`, category `/category/{slug}`, sub-category | yes (the first page of each) | `products` (+ `category` / `sub_category` filters), `categories` | filters, sort, price range, in-stock, search `?q=`, load more: all `GET /products?...` |
| Product `/product/{slug}` | yes, one per product in `sitemap` | `products/{slug}` (name, description, `seo`, `breadcrumbs`, images with sizes, `videos`, options, tabs, related) | `products/{slug}` again for current price and stock; the quantity stepper and delivery date |
| Static pages `/about`, `/shipping-policy`, `/return-policy`, `/terms-and-conditions`, `/privacy-policy` | yes | `settings` (store name, e-mail, phone, address in the text) | nothing |
| Contact `/contact` | yes (the page) | `settings` (phone, e-mail, address, support hours, WhatsApp, social) | the **wholesale form** posts to `POST /wholesale-requests` |
| Cart (drawer and `/cart`) | **no, client only** | nothing | `/cart` endpoints with the `X-Cart-Token` |
| Checkout `/checkout` | **no, client only** | nothing | `cart`, `checkout/quote`, `coupons/validate`, `orders`, `orders/{n}/payment-proof` |
| Order success `/order/{number}`, track `/track` | **no, client only** (`noindex`) | nothing | `GET /orders/{number}?phone=` and the proof upload |
| `sitemap.xml`, `robots.txt` | yes | `GET {API}/sitemap` (+ the static pages by hand) | |
| 404 | yes | | a slug that exists in the API but was created after the last build is loaded in the browser |

**Prerender everything from `GET /sitemap`**: every `products[].slug`, `categories[].slug`, `sub_categories[]` (with `category_slug`). The static host must serve the app shell for an unknown `/product/{slug}` (`_redirects` fallback) so a product created after the last build still opens, client side.

## 2. Rules the storefront must follow

1. **Settings first.** Call `GET /settings` before anything else; the whole site adapts to it (name, logo, currency, delivery, payments, `store.open`, theme).
2. **Theme tokens as CSS variables.** `theme.primary`, `primary_contrast`, `accent`, `bg`, `surface`, `text`, `muted`, `border`, `radius`, `shadow`, `font_heading`, `font_body` become `--color-primary` etc. on `:root`. Nothing is hard-coded: a client changes the look in the admin panel (Settings → Theme). The site must look right with the *default* theme too.
3. **The cart token.** The server cart is bound to a random token. Keep it in `localStorage`, send it as the request header `X-Cart-Token`, read it from `data.token` of a cart answer (or the `X-Cart-Token` response header). **Never put it in a URL.** A request without a token creates a new cart.
4. **Totals come from the server only.** Never calculate a price, a discount, tax, delivery or a total in the browser: show `quote` / `cart` figures. Re-quote when the address (city, area, postcode) or the coupon changes.
5. **`Idempotency-Key` on `POST /orders`**: a fresh UUID created when the checkout page opens and reused for retries of the same order (a double click or a flaky network must not create two orders). Send `expected_total` (the total the customer saw); `409 price_changed` means show the new total and ask again.
6. **Honeypot.** Every public form has a hidden field called `website` (the name is `SHOP_HONEYPOT_FIELD`) that real visitors never fill. Send it as it is (empty). Keep it out of the tab order and hide it with CSS, not `type="hidden"`.
7. **Delivery pending.** When the quote or an order says `delivery_pending` / `delivery_fee_pending`, show "Delivery fee to be confirmed" instead of a number; the owner sets the fee later and the customer is told by e-mail.
8. **Bank transfer.** After an order with `payment_method: "bank_transfer"`, show the accounts and instructions **from the order** (the snapshot taken when it was placed, not the current settings), then a screenshot upload (`POST /orders/{number}/payment-proof`, image up to 4 MB, preview before sending), a "we will verify your payment" state, and an upload again after the shop rejects a proof (the reject reason is in the order).
9. **Tracking by order number + phone.** `GET /orders/{number}?phone=`. A wrong phone is a `404` identical to an unknown order: show one neutral "order not found" message. The page also reads `?order=` (the link in the e-mails; the customer still types the phone). Remember the last order number in `localStorage`.
10. **Handle the error codes**: `price_changed`, `cart_invalid` (lines), `below_min_order`, `cod_not_allowed`, `coupon_invalid`, `delivery_not_available`, `store_closed`, `payment_method_unavailable`, `429` (with `Retry-After`) and `422` field errors. Never show a raw error.
11. **A closed store** (`store.open = false`) still browses and shows prices; show `store.closed_message` in a site-wide banner and disable ordering.
12. **No star ratings, no review counts, no wishlist, no customer login, no analytics or tracking scripts.**

## 3. SEO requirements (they must hold in the generated HTML, not only after JavaScript)

* A unique `<title>` and meta description on every page: the product's own `seo.title` / `seo.description` (the API already falls back to the product name, short description and the store defaults), categories use their `meta_title` / `meta_description`, other pages use `settings.seo.*`.
* `<link rel="canonical">` built from the site's public URL (`NUXT_PUBLIC_SITE_URL`), Open Graph and Twitter card tags with an image (`seo.image_url`, else `settings.seo.share_image_url`), `lang`, a favicon from `settings.store.favicon_url`.
* **Product JSON-LD** (`name`, `image`, `description`, `sku`, `offers` with `price`, `priceCurrency`, `availability` from `availability`), **BreadcrumbList** from `breadcrumbs`, **Organization** and **WebSite with a SearchAction** on the home page.
* `sitemap.xml` (from `GET /sitemap` plus the static pages) and `robots.txt` pointing at it. `noindex` on the cart, checkout, track and order pages.
* Real breadcrumbs and plain `<a href>` links between pages; one `<h1>` per page; every image has `alt`, `width` and `height`.

## 4. Performance

* No layout shift: images carry `width` / `height` (the API sends `image_width` / `image_height`, and `width` / `height` / `medium_*` for gallery pictures); skeletons have the size of what they replace.
* Lazy-load everything below the fold; preload the hero image; fonts self-hosted with `font-display: swap` (the theme names one of `Inter, Poppins, Playfair Display, Plus Jakarta Sans, Roboto, Lato, system-ui`).
* Minimal JavaScript: the cart drawer, gallery, checkout and search are client-only chunks; content pages need almost none. Respect `prefers-reduced-motion`.
* The catalogue endpoints are cached by Cloudflare for up to 5 minutes (`s-maxage=300`) and send an `ETag`; the cart, quote, orders and everything with `X-Cart-Token` are never cached. Do not add cache-busting query strings to catalogue calls.
* Mobile first, from 360 px wide.

## 5. No host-specific features

The same build must run on **Cloudflare Pages, a cPanel folder, Netlify or Vercel**: plain static files from `nuxt generate`. So: no server routes, no server middleware, no API routes, no server actions, no edge functions, no host-specific rewrites in code. Everything the host may need (fallback for unknown slugs, cache and security headers) goes in small static files (`_redirects`, `_headers`) that are simply ignored by hosts that do not know them, and the cPanel variant gets an `.htaccess` ([STOREFRONT.md](STOREFRONT.md)).

## 6. How the website stays up to date

The API calls a *deploy hook* of the hosting when something customers can see changes (Settings → Website; [NEW_CLIENT.md](NEW_CLIENT.md), section 6b). A rebuild takes 1 to 3 minutes. New products and pictures show after it; **price, stock, the cart and checkout are live from the API**, so the product page must refresh price and stock in the browser and never trust the baked-in values for ordering. The website never calls the admin API.
