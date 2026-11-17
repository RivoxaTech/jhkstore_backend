# Performance

What was slow, what was only slow on a developer machine, what was changed, and how to measure it again. All numbers are from one Windows laptop with Laragon
(PHP 8.3, MySQL 8.4), `php artisan serve` with OPcache, headless Chrome, the scratch database with about 380 orders and the demo catalogue (40 products). They move by
about +-15 % from run to run (the machine was also running other things): compare the order of magnitude, not single digits.

## Real slowness and dev-only slowness

| Cause | Real (also on a server) or dev only | What was done |
|---|---|---|
| Every screen showed a skeleton while it asked the server again, on every visit (dashboard, customers, discounts, coupons, categories, settings, order / product / request pages) | **real** (the user waits for the answer every time) | the last answer is shown at once and replaced by the fresh one (`api.peek`, below) |
| Two calls every 5 seconds for the badges and the "something changed" check | **real** | one call (`GET /admin/badges`): 132 ms of server time per poll -> 69 ms |
| Dashboard figures read every wide order row: 483 ms with 30 000 orders | **real** (grows with the shop) | covering index `orders (created_at, status, grand_total)` + 6 queries fewer: 140 ms |
| A new MySQL connection for every request (~15 ms) | dev only (`php artisan serve` starts PHP fresh for every request; PHP-FPM can keep connections) | `PDO::ATTR_PERSISTENT` when `APP_ENV=local` (`DB_PERSISTENT` to switch on elsewhere): -20 to -25 % per request |
| ~25 ms of framework boot per request, one request at a time | dev only (single-threaded server; production has OPcache + several workers) | nothing to do; see `Server-Timing` below |
| 6 to 10 database queries per request for sessions, the rate limiter and the permission cache (`CACHE_STORE=database`, `SESSION_DRIVER=database`) | real but small (about 10 ms) | recommendation only (`file` on one server, redis on several): `.env.example` |
| `nuxt dev` rendered `_payload.json` per click (3 to 6 s) | dev only | fixed in round E (`payloadExtraction=false`); the generated site has plain files |
| The product form asked for both option lists on every visit | real, small | only the categories (one call) |

There was **no N+1** in the backend: every list and detail endpoint has a fixed number of queries (tested: `AdminOrderTest`, `ProductAdminTest`, `PerformanceTest`) and the
indexes of `2026_11_10_000002` and `2026_11_11_000001` cover the filters and the dashboard.

## Numbers

Server time of one call in panel mode (session cookie + CSRF, like the browser), median of 20, milliseconds:

| Call | Before | After |
|---|---|---|
| `auth/me` | 94 | 61 |
| orders badge + wholesale badge (two calls) -> `badges` | 104 + 92 | 69 |
| `orders/options` | 78 | 63 |
| orders list (20 rows) | 115 | 80 |
| order detail | 124 | 80 |
| customers | 103 | 68 |
| discounts / coupons | 99 / 89 | 67 / 67 |
| settings | 102 | 77 |
| dashboard (380 orders) | 151 | 119 |
| dashboard (30 000 orders, in-process) | 483 | 140 |

What the person sees in the panel (click on the sidebar until the screen has its heading and no skeleton; first visit / second visit):

| Screen | Before | After |
|---|---|---|
| Orders | 437 / 0 | 170 / 0 |
| Order detail | 440 / 221 | 207 / 0 |
| Customers | 210 / 177 | 88 / 0 |
| Wholesale | 285 / 151 | 246 / 0 |
| Discounts | 182 / 463 | 95 / 0 |
| Coupons | 290 / 80 | 118 / 0 |
| Settings | 431 / 205 | 177 / 0 |
| Dashboard | - / 280 | - / 0 |

(`0` = the last answer is on screen in the same frame; the fresh answer follows 90 to 260 ms later and replaces it quietly.) Hovering a sidebar item or a row before the click
(`utils/prefetch.js`) takes away most of the first-visit time as well; the script clicks without hovering.

The generated website (`frontend/tests/perf.mjs`, cold cache, no compression on the local static server): home 326 ms to DOMContentLoaded, product page 270 ms, 300 KB of
script (about 110 KB compressed), 140 to 195 ms for a click from page to page, 60 to 100 ms from "Add to Cart" to the open drawer (the server call finishes in the background).
Pictures are 10 to 33 KB, lazily loaded. Nothing to change there.

## How the panel stays fast

* `admin-frontend/src/api/client.js`: every successful GET is remembered for the visit (120 answers). `api.peek(path, query)` returns the last answer (a copy) so a screen can
  draw at once; `api.get` always asks again. Identical GETs that are in flight share one request (so a prefetch and the screen that opens a moment later cost one call). A write
  drops the remembered answers of its own resource (`PUT admin/discounts/5` forgets `admin/discounts...`) and the requests in flight; logout and a 401 forget everything.
* A screen that can be edited (Settings, Access Control, the product / coupon / discount form, the order note) never replaces what the person is already typing with the
  fresh answer.
* `utils/prefetch.js`: `pointerenter` / `focus` / `touchstart` on a sidebar item or a list row loads the screen's code and its data; the busiest screens' code loads when the
  browser is idle after the first screen.
* The order screens follow `ui.ordersVersion` (moved by the 5-second `badges` poll): a new order, a status change somewhere else, a payment proof - the list and the open order
  update by themselves.

## Measuring again

* Local API: every answer has `Server-Timing: total;dur=84, boot;dur=31, db;dur=9;desc="7 queries"` (only with `APP_ENV=local`; browser > Network > Timing).
* `admin-frontend/e2e/perf-nav.mjs` (the panel, `E2E_VERBOSE=1` lists the calls of every screen), `frontend/tests/perf.mjs [--mobile]` (the website).
* The in-process profiler idea: boot the app in a PHP script, `DB::listen`, call the kernel for each endpoint and print time and queries (the numbers above came from that and from HTTP).
* Scale: clone the orders with an `INSERT ... SELECT` into a scratch database and look at the dashboard (`docs/QA_REPORT.md`, round F).

## Known limits

* The 5-second `version` of the order list is the count, the highest id and the newest `updated_at` (to the second): two changes inside the same second to the same order
  can show up with the next change instead.
* The panel's polling keeps a single-threaded `php artisan serve` busy for about 70 ms every 5 seconds; with several admin tabs open on a developer machine, pages wait for each
  other. A real server answers in parallel.

## The customer side (speed session, 2026-11-16)

Goal: add to cart, moving between pages and placing an order should feel instant. The storefront is a static site on one address and the API is on another, so **every cart, quote and order call is a cross-origin request**.

### How it was measured

`frontend/tests/perf-actions.mjs` drives headless Chrome through the real generated storefront against the real API on **another origin** (like Cloudflare Pages + `api.example.com`), in a new browser profile per action. It records every call to the API, which of them are CORS preflights (`OPTIONS`), and the time the customer waits. `frontend/tests/delay-proxy.mjs` stands in front of the API and holds every request and answer for half of the round trip, so **a 150 ms round trip** (a customer far from the server) is added to every call, preflights included (Chrome's own throttling did not delay these calls reliably). `fast4g` also limits the speed to 1.6 / 0.75 Mbit/s. The API ran production-like: `APP_ENV=production`, OPcache, 4 workers, the scratch database `jhkstore_shots` (40 toys); not `php artisan serve` and not `nuxt dev`. Median of 3 rounds after one round that is not counted.

```
# the API on 8013, the delay proxy on 8012 (the site was generated for 8012), the generated site on 3020
node tests/delay-proxy.mjs 8012 8013 150
SITE=http://127.0.0.1:3020 API=http://127.0.0.1:8012/api/v1/store node tests/perf-actions.mjs --net rtt150 --rounds 3
```

Server time of one call on that API (curl, milliseconds): add to cart 106 to 188 (the first call creates the cart), cart read 56 to 109, quote 41 to 75, place order 67 to 98. **The server is not the problem: the time goes in round trips.**

### Before and after (milliseconds the customer waits, 150 ms added to every round trip)

| Action | Before | After | What changed |
|---|---|---|---|
| Add to cart: the drawer opens | 49 | 49 | already instant (the server call finishes in the background) |
| Add to cart: the cart line is confirmed, first add of a visit | 658 | 670 | nothing: the first call to an address still needs its preflight |
| Add to cart: the third add of a visit | 575 (1 preflight) | **423** (no preflight) | the browser remembers the preflight (`max_age` 0 to 7200) |
| Address changed, the checkout asks for a new quote | 1202 (1 preflight) | **987** (no preflight) | same; the page itself waits 600 ms on purpose before asking |
| Checkout opens until "Place order" is on | 1195 (4 calls, 3 preflights) | 1205 (3 calls, 2 preflights) | the cart was read twice at the same moment: one call now (time unchanged: the quote decides it) |
| **Place order until the order page is there** | 1401 (3 calls, 2 preflights) | **840** (1 call, 1 preflight) | the page no longer waits for a cart read after the order, and no longer re-reads the order it just received |
| Page change shop to product | 105 (1 call, no preflight) | 106 | nothing to fix: static page, one background price call |

The same on `fast4g`: place order 1546 to 1063, third add to cart 700 to 476, new quote 1233 to 1019. With no added delay (`none`): place order 717 to 526, third add 255 to 246, new quote 873 to 811.

### What was changed and why

1. **`backend/config/cors.php`: `max_age` 0 to 7200** (`CORS_MAX_AGE` in `.env`). With 0 the browser sent an `OPTIONS` request before every cart, quote and order call and could not remember the answer: every action cost two round trips. Chrome keeps the answer 2 hours per address (Firefox up to 24 hours). Two things to know: the first call to each address in a visit still asks once, and the first call that carries the cart token asks once more (the remembered answer of the first call did not list that header).
2. **`useCart().afterOrder()` no longer calls the server.** The order already emptied the cart in its own transaction (`PlaceOrder`); the checkout awaited a full cart read (plus its preflight) before moving to the order page, which was about 40 % of the wait. The cart is now shown empty at once; a cart read that was still on its way when the order was placed is thrown away (`emptied` counter) so it cannot bring old lines back.
3. **One cart read when two places ask together** (the checkout and the layout, the page and the drawer): the second asker shares the first one's call.
4. **The order page does not re-read an order that was remembered less than 15 seconds ago** (it was just placed: the answer in hand is the server's own). A reload later, or any other way in, still reads it.

### Looked at and left alone

* The cart add is already optimistic (the drawer opens before the call returns); prices, stock and totals stay the server's.
* The order request does no slow work: mail and push already go after the response (queue, or after the response when no worker runs). On the measurement API the server time of the order is under 100 ms.
* The quote on every keystroke: it already waits 600 ms after the last key and is one call.
* Page changes: static files, one price call in the background. 105 ms with the 150 ms added is the browser alone.
* Avoiding the preflight altogether would mean sending the cart token in the address or as plain text; that was not done (a token must not be in a URL, and the API takes JSON).

### What only the hosting can do

Cloudflare in front of the API, HTTP/2 and 3, Brotli, no redirect in front of the API address, OPcache and the distance of the server: steps are in [GO_LIVE.md](GO_LIVE.md) (section 7). These were **not measured** here (no live hosting).
