# QA report – full flow on the real app

Every flow of the lean store was driven over **real HTTP against the running application** (MySQL `jhkstore_db`, `php artisan serve`, no test database, no mocks), and the admin screens were driven in **headless Chrome**. Run on the branch `lean-store` on 2026-11-03.

| Script | What it does | Result |
|---|---|---|
| [`scripts/flow-check.mjs`](../scripts/flow-check.mjs) | API flow A1–E16 (Node, plain `fetch`); database reads through [`scripts/flow-db.php`](../scripts/flow-db.php) (SELECT only) | **239 assertions, all PASS** |
| [`admin-frontend/e2e/flow-ui.mjs`](../admin-frontend/e2e/flow-ui.mjs) (`npm run flow-ui`) | the same flows through the admin panel in headless Chrome | **41 checks, all PASS** |

## How to run it again

```bash
php artisan migrate:fresh --seed                 # base seeder only; the script leaves test data behind, reseed afterwards
php artisan serve --port=8000                    # the real server
php artisan serve --port=8001                    # a SECOND server process (needed for the "last unit" race, see below)
node scripts/flow-check.mjs                      # add --fresh to let it run migrate:fresh --seed itself; ~8 minutes (it waits out rate-limit windows)
cd admin-frontend && npm run build && npm run flow-ui
```

Other ports: `FLOW_BASE`, `FLOW_BASE2` (API script) and `E2E_BASE` (browser script); the server's `APP_URL` must match the port it serves (picture URLs are built from it) and the port must be in `SANCTUM_STATEFUL_DOMAINS` for the panel login. Credentials: `FLOW_DEV_*` / `FLOW_ADMIN_*`, `E2E_DEV_*` / `E2E_ADMIN_*` (defaults `developer` / `admin`, password = username).

## Result per check

| # | Check | Result | Evidence (actual answers are printed by the scripts) |
|---|---|---|---|
| A1 | Developer and admin log in; wrong password rejected | PASS | both get a token; wrong password and unknown user → `422 The username or password is incorrect.`, no token; no token → 401 |
| A2 | Developer blocked from orders; dashboard shows no order figures; no Orders in the sidebar | PASS | orders list, options, detail, note, status → `403 You do not have permission to do this.` (also with real orders in the database); dashboard keys `catalog, recent_activity` only, no order numbers or order activity; sidebar `Dashboard, Products, Categories, Settings, Access Control` (browser) |
| A3 | Admin blocked from Access Control; Products/Categories off until the developer enables them; audit log | PASS | admin GET/PUT access-control → 403; products, tags, categories, banners → 403 and only the 3 default Settings tabs; after the developer's switch the admin lists and creates a category without re-login; off again → 403; three `access.updated` audit rows list granted / revoked permissions; the developer cannot be given `orders.*` (422); the same through the Access Control screen (sidebar appears and disappears) |
| B4 | Categories: plain, with two sub-categories, edit, reorder, deactivate, no third level | PASS | `Chairs, Desks` order follows `sort_order`; an inactive sub-category leaves the public tree; `POST …/sub-categories/{id}/sub-categories` → 404; a `parent_id` is ignored; a product with another category's sub-category → 422; also done in the panel forms |
| B5 | Tag; products in the plain category and in a sub-category with every field; pictures; publish | PASS | 2 options, bulk tiers (5→45, 10→40), stock + opening inventory movement, content tabs, SEO, related product, tag; 3 pictures uploaded at once, reordered, primary changed (exactly one), one deleted, an option's own picture, a 50 px picture → 422; draft → active → inactive → active; duplicate SKU and a bulk price that is not cheaper → 422; a product made in the panel form is live on the public API |
| B6 | Public API: categories, products with every filter, product detail; hidden items hidden | PASS | `GET /categories` (+`?top=1`, `/{slug}`), `/products` with `category`, `sub_category`, `tag`, `q`, product SKU, `min_price`/`max_price`, `in_stock`, every `sort`; detail has options, tiers, pictures (an option's picture on its option only), related; draft, archived, inactive-category and inactive-sub-category products give 404 and are not listed |
| C7 | Quote: bulk, tax inclusive and exclusive, delivery fee, free delivery, out of stock, over limit | PASS | 5 × 50 → unit 45, line 225, free delivery; 12 + 5 delivery = 17 (88 to free delivery); 10 % inclusive = 1.09 tax, total unchanged; exclusive = 1.20 tax, total 18.20; `out_of_stock`, `insufficient_stock`, `above_max_qty` block checkout; quantity 100000 and a duplicated option → 422 |
| C8 | First order as a new customer | PASS | `201 JHK-2026-000001`; stock 20 → 15; movement `sale −5, balance 15`; customer created with `+923001234567`; refused orders (bad e-mail, no phone, sold out, over limit) leave no customer and no stock change |
| C9 | Same phone in different formats = one customer | PASS | `+92 300 1234567`, `0300 1234567`, `0092 300 1234567`, `+92-300-1234567` → one customer, `orders_count 4`, `total_spent 288.00`, the stored e-mail was not wiped |
| C10 | Idempotency, `price_changed`, last-unit race, honeypot, rate limits | PASS | replay → `200` + `Idempotency-Replayed: true`, one order row; stale `expected_total` → `409 price_changed` (with `current_total`); honeypot → `422 rejected`; **6 simultaneous orders for the last unit across two PHP processes: one `201`, five `422 cart_invalid`, stock exactly 0, one sale movement, only the winner became a customer**; a second round of 4: one winner; checkout 11th request/min → `429` + `Retry-After`; quote 61st/min → `429`; lookup 16th/hour per order number → `429` |
| C11 | Order lookup with phone | PASS | right phone (and its local `0300…` form) → full order with tracking and timeline; wrong phone and unknown order → byte-identical 404; missing phone → 422 |
| D12 | Admin sees the order in Orders and Dashboard, detail, internal note | PASS | list, search by number / name / phone (`+92…` and `0300…` give the same orders); dashboard `today 7, revenue 355, pending 7`; note saved and **not** visible to the customer; audit `order.note_updated`; the same in the panel |
| D13 | Status flow, COD paid on delivery, history, audit, invalid jumps | PASS | pending→shipped/delivered/packed, back to pending, confirmed→shipped → `422 invalid_status_change`; shipping without courier/AWB → 422; shipped order cannot be cancelled; delivered: payment `cod_pending → cod_collected`, order `paid`; history `null>pending, pending>confirmed, confirmed>packed, packed>shipped, shipped>delivered`; 4 status audit entries; tracking number visible to the customer; delivered/cancelled are final; the same through the order screen |
| D14 | Cancel: stock returns; invoice and packing slip | PASS | cancel needs a reason; stock 47 → 50 with a `cancel +3` movement; customer totals reduced again; cannot be re-opened; **invoice** and **packing slip** print views checked in print media (invoice: number, items, bill-to, totals, payment; slip: items and ship-to, no prices) and rendered to PDF |
| D15 | Customers: search by name, phone, e-mail; CSV export | PASS | name, part of a phone, e-mail, empty result; CSV header + one row per customer, row of the 4-order customer equals the list; the export follows `?q=`; export button in the panel downloads `customers-<date>.csv` identical to the API export; `customers.exported` audited |
| E16 | Every Settings tab → public API; restricted admin cannot open tabs that are off | PASS | General (profile, phone country code, invalid `92` → 422 with a clear message), Branding (logo, favicon, share image served; SVG → 422; removing the favicon empties the URL), Banners (7 banners: live ones only, inactive / future / expired hidden, reorder, edit, mobile picture, bad date window and `javascript:` link → 422, per placement), Footer & Social (4 links, order, inactive hidden, empty label falls back to the platform, `wa.me` link, reorder, `javascript:` → 422), Currency & Tax (PKR, 0 decimals, 17 % GST exclusive reaches the quote), Orders & Delivery (fee 7.5, free from 150, days 3, minimum order 15, COD limit 100 → `below_min_order`, `cod_not_allowed`, new order-number prefix), SEO (title, description, share image, 71 characters → 422); restricted admin: 3 tabs, currency / SEO / mixed request / logo / banners → 403 and a refused mixed request changes nothing; developer opens Branding and Banners for the admin and closes them again |

## Bugs found and fixed

| Commit | Bug | Regression test |
|---|---|---|
| `1273398` | (found while adding the phone country code) the admin **customer search** for a local number (`0300…`) did not find the customer stored as `+92300…` | `CustomerTest::test_local_and_international_numbers_are_one_customer_with_a_default_country_code` |
| `1bee203` | the admin **order search** for a local number (`0300…`) did not find orders stored as `+92300…` | `AdminOrderTest::test_search_by_a_local_phone_finds_orders_stored_with_the_default_country_code` (fails without the fix) |
| `02adf11` | an invalid default phone country code answered the generic "format is invalid" | `SettingsTest::test_values_are_validated` |

## Found, not changed (your decision)

* `PATCH /admin/orders/{id}/note` reads the field `admin_note`. A request without that field (for example `{"note": "…"}`) answers `200` **and clears the stored note**. The panel always sends the field, so only API clients can hit it. Making the field required (`present`) would answer 422 instead; it is a behaviour change, so it was left alone.
* A single-option product's option name starts as a copy of the product name in the product form (by design); the public detail of such a product shows the option name as `variants[0].name` and the parent's as `product_name`.

## Not testable here, and why

* **Real parallel requests on one server.** The PHP development server handles one request at a time (and `PHP_CLI_SERVER_WORKERS` does not exist on Windows). The race therefore runs against **two server processes** on one database, which exercises the same row locks a production process pool uses. A production stack (php-fpm) was not available.
* **The operating system's print dialog.** `window.print()` is replaced by a counter; the invoice and packing slip are verified through Chrome's print media rules and a rendered PDF.
* **Per-IP-per-hour and per-phone-per-hour checkout limits** were not run over HTTP (they would need an hour); `SecurityTest` covers them with lowered limits. The per-minute limits, the quote limit and the per-order lookup limit were run over HTTP.
* **Online payments, e-mail and SMS** do not exist in this product (cash on delivery only, no notifications).
* **Production error handling.** The local `.env` has `APP_DEBUG=true`, so error answers in this run carry a trace; production behaviour (`APP_DEBUG` forced off, no trace) is covered by `SecurityTest`.
* The browser script fills fields with native `input` events instead of key presses: in this environment headless Chrome dropped key events after a page navigation while the machine's window focus was elsewhere. Vue's `v-model` receives the same events either way.
