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
