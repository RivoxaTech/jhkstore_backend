# Changelog

## 2026-11-16 (2) – Go-live notes (branch `lean-store`)

### Added
- `docs/GO_LIVE.md`: what runs where, what must not be uploaded to the server, the decision about `design/` (nothing reads it: delete) and `scripts/` (our test tools and the Help video maker: never deployed, keep in the development repository), one repository instead of three and why, how to attach a new GitHub repository (with or without history), the production checklist, and the first things to check if the live shop feels slow (the CORS preflight: `max_age` is 0 in `backend/config/cors.php`).
- `docs/NEXT_CHAT_PROMPTS.md`: a prompt that gives a new AI chat the whole project and its rules, and a prompt for a focused speed session (add to cart, page changes, place order).

## 2026-11-16 – The mail account from the panel, access that now works, Help like a video site (branch `lean-store`)

### Added
- **Settings → E-mail** (the developer's tab): the account that sends every e-mail can be set in the panel instead of `.env`: mail server, port, encryption (TLS, SSL, none), user name, password, sender address, and a switch *Send with this mail account*. The password is stored encrypted and never shown again (only whether it is set). Off = the `MAIL_*` values of the server are used, exactly as before. A quick-setup card fills the server, port and encryption for Gmail, Outlook, Brevo, Mailgun or a cPanel host (with the tip each one needs, e.g. Gmail wants an app password), and *Send test* sends a real test e-mail through the saved account so a wrong password or port shows as an error there. `MailServer` puts the account into Laravel's mail configuration before a mail is sent; a queue worker does it before every job, so a change in the panel is followed without a restart. New permission `settings.mail` (the developer has it; migration `2026_11_15_000001` adds it to existing shops: run `php artisan migrate`). The Notifications status shows whether the panel's account or the server's is in use.
- **Help is laid out like a video site**: a grid, three videos in a row, each with its picture, length, title, description and topic; a search box; a watch page with the player, the written steps **each with its time** (press a step to jump the video to it; the step being shown lights up) and the other videos beside it. A seventh video, *Connect the e-mail account*, recorded against a small local mail server so the test e-mail really goes through. Every account now sees every video; the "Open the screen" button shows only for screens the account may use.
- `/admin/help-media/{video}`: the videos are served by Laravel with byte ranges, because the built-in server of `php artisan serve` cannot seek in a plain file (a step would restart the video at 0).

### Fixed
- **Login failed with "There is no permission named `settings.mail`"** on a database that had not run `php artisan migrate` after the update. A permission that an update adds but the database does not have yet is now added on the spot (the same as the migration does, with its default; what the developer switched on or off stays), so neither the login nor a page can fail because of it. `php artisan migrate` is still the right way to update.
- **Access that did not take effect**: switching on a technical Settings tab (Footer & Social, Currency & Tax, Orders & Delivery, SEO, Branding, Banners) for the admin in Access Control showed nothing, because a second master switch (`settings.advanced`) was also needed. A tab that is technical as a whole now opens with its own switch. A single technical field inside a client tab (the store name in General, the delivery time in Delivery, the mail sender in Notifications, the hook address in Website) still needs `settings.advanced`. The Access Control screen and `docs/ACCESS_CONTROL.md` say so.
- The master mail switch of the E-mail tab takes the whole row; the encryption choice has readable names.

## 2026-11-15 – Help videos, the admin as an installable app, favicons, plain policy pages (branch `lean-store`)

### Added
- **Help** (last item of the admin sidebar, for every signed-in account): short silent how-to videos with the steps written under each one: Add a product, Edit a product, Categories and sub-categories, Add a discount, Add a coupon, Settings. A video is shown only to accounts that may use its screen (the owner's account sees 3, the developer 6). 35 to 65 seconds each, 0.4 to 1 MB, 1280 x 760, step banner across the top, mouse pointer and click pulse. The files are `admin-frontend/public/help/`; `scripts/help-videos` (`make.mjs`, `flows.mjs`, `lib.mjs`) records them again from the real panel in a few minutes (see `docs/HELP_AND_APP.md`); the written steps are the banner texts, so they always match the pictures.
- **The admin panel is an installable app (PWA)**: a manifest made by Laravel at `/admin/manifest.webmanifest` (store name + "Admin", start page /admin/, standalone), the icon set (`favicon.svg`, `favicon.ico`, 96 px png, apple touch icon, 192 and 512 px app icons) in `admin-frontend/public`, the meta tags in `index.html`, an install button in the top bar (Chrome, Edge, Android), the theme colour follows the light / dark theme. The service worker `admin-sw.js` is now registered as soon as the panel opens and shows a small "You are offline" page (`admin-offline.html`) when a page is opened without a connection; it caches nothing else, so no old data is ever shown. Push notifications work as before.
- The storefront uses the same icon set when no favicon is uploaded in Settings (the uploaded one still wins), plus the apple touch icon and `site.webmanifest`.
- Tests: `AdminPwaTest` (manifest, short name, files), `flow-ui` UI-T1 / UI-T2 (Help list by permission, the video loads, steps, manifest, icons, service worker, offline page), a seeder check.

### Changed
- **The four policy pages** (Shipping, Returns, Terms and conditions, Privacy) are plain, short, friendly texts written for a shop in Mauritius (delivery all over Mauritius, Rodrigues a little longer, a 14 day change-of-mind return, refunds back the way you paid, the Data Protection Act 2017, the laws of Mauritius). The "this page is a general template" notes are gone. No long dashes. The shop owner should still read them and change anything that is not how they work (`frontend/app/content/*.ts`).

### Fixed
- The demo seeder made its sample discount with the scope `category` (the panel knows `categories`), so the Discounts list showed "undefined (1)" and the discount never applied. Found while recording the discount video.
- `public/favicon.ico` of the backend was an empty file; it is the real icon now.

## 2026-11-13 – BRN on the wholesale form, copy icons for delivery details, optional IBAN, long figures on the cards (branch `lean-store`)

### Added
- **BRN (business registration number)** on the wholesale form, optional, right after the business name; the form order is now name, phone, e-mail, business name, BRN, quantity, which toy, what are you looking for, message. Backend: `wholesale_requests.brn` (migration `2026_11_13_000001`, run `php artisan migrate`), validated (letters, digits, space, - / ., max 50), shown on the request page and in the list, searchable, in the CSV (new column after Business) and in the owner's alert e-mail. Documented in `STOREFRONT-API.md` / `openapi.json`.
- **Copy icons on the order page**: next to the shipping address name, phone, e-mail and the address (also the billing address), plus a **Copy all** button that copies the delivery block (name, phone, e-mail, address lines) for a courier or a chat; copy icons for the BRN, phone and e-mail on a wholesale request.

### Changed
- **IBAN is optional** in the bank accounts of Settings → Payments (the box says "IBAN (optional)"); it is shown to customers (checkout, order page, e-mails) and in the admin order only when it is filled in. An account still needs an account number or an IBAN.
- **Dashboard cards**: a long figure gets a smaller type in steps (and an ellipsis with the full figure as the tooltip at the very end) instead of breaking over several lines; lighter weight (600).
- **Money in the admin panel always groups thousands with commas** (1,234,567.00), whatever the browser's language (the storefront already did).
- Checked: the store phone, WhatsApp number and e-mail come from Settings → General everywhere (storefront footer, About / policy pages through the {phone} {email} {address} placeholders, the floating WhatsApp button, order page, e-mails, the printed invoice and packing slip); nothing is typed into the code. Storefront pages show a changed value after the next rebuild or, at the latest, when the visitor's browser refreshes the settings.

## 2026-11-12 (2) – A really dark dark theme, own drop-down list and calendar, compact phone screens, copy icons, old price first (branch `lean-store`)

### Fixed
- **The dark theme was half light.** The panel writes the store's primary colour as inline CSS variables, and those light values overrode the dark ones (the active menu item, soft buttons, tints stayed pale), and many components had a hard-coded white (the save bar, switches, chart tooltip, banner / account / link rows, the option table, the order's bank box, the dashboard tiles). The primary colour is now worked out for the light or the dark panel (`utils/theme.js`, redone when the theme is toggled) and the hard-coded colours are theme tokens (`--field`, `--surface-2`, `--hover`, `--soft`, skeleton, switch, scroll bar). A script that walks every screen in the dark theme and lists visible elements with a light background now finds only the intended accent colours (badge, switch "on").
- **Dashboard header on a phone** was pushed to the right (the scoped style beat the phone rule).

### Changed
- **Dashboard cards** carry no explaining text any more: the icon disc, the name, the figure and the change against the last period. The grid has as many columns as there are cards (one card for the developer does not stretch over the whole row).
- **Scroll bars** of the panel: slim, rounded, theme-coloured (Firefox: thin).
- **Export CSV** sits in the same row as the title and the path on a phone (orders, customers, wholesale, products, ...).
- **Orders filter on a phone**: From and To share one row; the selects and dates are 34 px high.
- **Customers** are a table again on a phone (the box scrolls sideways) instead of cards; a **copy icon** beside every phone number and e-mail (customers, abandoned carts, the customer box of an order).
- **Order page on a phone**: the items are compact lines (picture and name, then quantity x price and the line total) that fit the screen; the totals are tighter.
- **Save bar** (settings, forms): one compact row on a phone ("All changes saved", an icon-only Discard, Save changes), dark in the dark theme.
- **Storefront**: the old (struck-through) price is shown **before** the price, on the product card and on the product page (a wide card: one row; a narrow card: two rows); the page headers (Shop, About, ...) and the first section are much tighter on a phone; the site header and the phone menu have rounded bottom corners like the cards.

### Added
- **The panel's own drop-down list and calendar** (`admin-frontend/src/utils/popovers.js`): every `select.select` and every date field opens a list / a calendar drawn by the panel (themed, inside the screen, with a check mark, keyboard: Space / Enter / arrows / Esc) instead of the browser's pop-up. The real element stays in the page and still fires `input` / `change`, so v-model and the tests are unchanged.
- `CopyButton` component (clipboard API with a fallback for http pages).
- Tests: `flow-ui` UI-S1..S4 (the list, the calendar, the copy icon, the cards without text); `journey.mjs` phone checks (Export in the title row, From and To on one row, customers table with copy icons, compact order items, old price before the price, compact page header).

## 2026-11-12 – Plain sidebar, profile and password, dark theme, phone layouts, round-icon dashboard cards (branch `lean-store`)

### Changed
- **Sidebar**: no group headings, one list in the order Dashboard, Orders, Wholesale, Customers, (Products, Categories, Discounts, Coupons), then a thin line and Settings / Access Control. The collapse button is the small round button next to the logo (on the edge of the sidebar when it is collapsed). The signed-in person (name and role, Admin or Developer) sits at the bottom and opens the **Profile** page.
- **Top bar**: the name button is gone; the right side is the alert sound, push and status buttons, the **theme toggle** and a **logout icon**.
- **Dashboard cards** are round "orbs" with the icon, the figure, a soft circle behind the corner and the change against the last period; four across on a desktop, two by two on a phone.
- **Phones**: every list (orders, wholesale, customers, coupons, discounts, products, the dashboard's recent orders) is drawn as cards (orders: number and total on top, customer, date, payment and status below). The tabs scroll sideways, the filters are two to a row. On the order page the print / packing slip / WhatsApp / cancel / reject buttons are icons and the main step keeps a short label.

### Added
- **Profile page** (`/admin/profile`): who you are and **Change password** (current password, new password twice, an eye button on each box; the same eye on the sign-in page). `PUT /admin/auth/password` - the current password is checked, at least 8 characters (12 in production), not the username, not the old one; the account's other API tokens are revoked; 5 tries a minute; the password never reaches the audit log.
- **Light / dark theme** for the panel, kept in the browser and applied before the first paint.
## 2026-11-11 (2) – Sales and Revenue, any later status, cleaner invoice and packing slip, faster product add (branch `lean-store`)

### Changed
- **Dashboard cards: Sales, Revenue, Orders, Wholesale.** *Sales* is what was ordered (cancelled and rejected orders left out). *Revenue* is only money **received**: a bank transfer once the payment is verified **and** the order is confirmed, cash on delivery once the order is delivered. The "Pending" and "Average order" cards are gone; the chart now says Sales. (API: `orders.kpis.sales` and `orders.kpis.revenue`; `revenue` used to mean what `sales` means now. New migration `2026_11_12_000001` widens the dashboard index.) Recent orders use the whole width.
- **Order status: once an order is confirmed it can go straight to packed, shipped or delivered** (and still be cancelled before shipping). A pending order is confirmed (or cancelled / rejected) first. The status list of the orders table and a new status menu on the order page are real menus (coloured dots, a tick on the current one, greyed-out choices say why) instead of the browser's drop-down.
- **Invoice and packing slip redone**: the logo (no name beside it) on the left, phone and e-mail on the right, the title with order / date / payment, the two address boxes, the items, the totals, a thank-you line; A4 with its own margins (the browser's header and footer - date, page title - no longer print). The packing slip now shows the prices and a box "Cash on delivery - Collect Rs X from the customer" (or "Paid - nothing to collect"), so the courier and the customer know what to pay.
- **Product card**: the price and the old price share one line when the card is wide enough, and sit on two lines on a narrow card (a container query, not the screen width).
- **Wholesale form**: the product list is a proper drop-down with a search box, thumbnails and a list that scrolls inside the page (`ProductPicker.vue`); the website's scroll bars are thin and in the theme colour.
- The payment screenshot on the order page shows a placeholder frame while it loads.

### Faster
- **Adding a product**: the pictures were resized by calling the image optimiser's tools (a process per picture on Windows): 3 pictures took 2.5 s, now 1.0 s; the new product opens the list at once and its pictures and video upload in the background (a toast says how it went).

### Added
- **An option's SKU is made for you**: adding an option fills SKU-2, SKU-3 ... from the product SKU (the first free number, never the same twice); the server does the same when an option arrives without one.
## 2026-11-11 – Speed round and an adversarial test of the whole shop (branch `lean-store`)

### Fixed (found by the new tests)
- **E-mails of a status change (confirmed, shipped, delivered, cancelled) were not sent on a host without a queue worker.** The mail of a new order is sent by the web request right after its response when no worker runs; that run was counted as "a queue worker is running", so every later mail was put in the queue, where nobody took it. The after-response run no longer counts (`AppServiceProvider`), test: `WorkerFallbackTest`.
- **Abandoned carts were never captured.** The Customers > Abandoned carts tab and the API existed, but the website never told the server who was buying: the checkout now saves the phone / e-mail / name on the cart a moment after they are typed (`useCart().saveContact`, `PUT /store/cart/contact`). Tests: `frontend/tests/e2e.mjs` S5, `frontend/tests/journey.mjs`.
- **`nuxt generate` failed** (`useSeo.ts` declared the same helper twice) - no static site could be built.
- A wrong file chosen as the payment screenshot was answered with "Please upload the screenshot" instead of "Please choose a JPG, PNG or WEBP picture".
- A double click on *Place Order* could send the request twice (the Idempotency-Key made it one order; now the button is guarded as well).
- The invoice and the order page printed "CouponCODE" without a space.
- The product form reloaded every dropdown on each visit (two calls instead of one) and the panel polled two endpoints every 5 seconds (now one).

### Faster
Measured on a developer machine with `php artisan serve` (numbers and method: [docs/PERFORMANCE.md](docs/PERFORMANCE.md)).
- **Screens show the last answer at once and refresh in the background** - every list, every detail page, Settings, Access Control, the dashboard (`api.peek` in `admin-frontend/src/api/client.js`, replaces `listCache.js`). A write drops the remembered answers of its own resource so a stale list is never shown first.
- **Hover / touch prefetch**: pointing at a sidebar item or an order / product / request row loads the screen's code and its data first (`utils/prefetch.js`); the busiest screens' code loads while the browser is idle. Identical GETs that are already on their way share one request.
- **One badge poll** (`GET /admin/badges`: orders + wholesale, versions included) instead of two calls every 5 seconds; the old endpoints stay.
- **Dashboard**: a covering index on the orders (`2026_11_11_000001_add_dashboard_indexes`) and 6 queries fewer: 483 ms -> 140 ms with 30 000 orders.
- **Local requests are 20-25 % faster**: the MySQL connection is kept open between requests when `APP_ENV=local` (`DB_PERSISTENT`).
- `Server-Timing` header on every answer in local development (total / boot / database time and query count): see it in the browser's Network > Timing tab.

### Added
- `scripts/adversarial.mjs` (customer / admin attacks over HTTP, 165 checks), `frontend/tests/journey.mjs` (customer and owner in one browser session, 84 checks, screenshots), `admin-frontend/e2e/perf-nav.mjs` and `frontend/tests/perf.mjs` (speed), `BadgesTest`, `PerformanceTest`.
- `flow-check` accepts the "no cache" headers of a local server.


## 2026-11-10 (2) – Pages instead of dialogs, no questions on a status change, optional MRP, notifications while another tab is open (branch `lean-store`)

### Changed
- **Discounts, coupons and wholesale requests have pages of their own** (`/discounts/new`, `/discounts/:id/edit`, `/coupons/new`, `/coupons/:id/edit`, `/wholesale/:id`) instead of a dialog. The long forms are split into cards (Details, What it gives, When, Limits).
- **Changing the status of an order asks for nothing**: no courier / AWB form on shipping, no reason on cancelling or rejecting (all optional in the API too: `shipped` makes a shipment with what was given). Cancelling and rejecting only ask "are you sure?".
- **The MRP is optional.** Left empty it is the selling price. While a discount lowers the price, the crossed-out price is the price before the discount (MRP 200, price 100, 20 % off: 100 crossed out, 80 to pay; no MRP and price 200: 200 crossed out, 160 to pay).
- Product cards: on a one-per-row phone layout the price and the old price share a line; with several per row the old price is above the price.
- The dark buttons / active date tab of the admin panel use the theme colour.

### Added
- **"Turn on notifications" banner** in the admin panel (browsers only ask for the permission after a click), and the panel keeps checking for new orders and requests in a tab that is not in front: it chimes and, when the tab is hidden and push is not subscribed, shows a system notification.
- Lists (orders, products, wholesale) show their last answer at once when you come back and refresh it in the background; new indexes for the order badge, the product order and the payment-method filter.

## 2026-11-10 – Real-time panel, working mail / push, fast navigation, arranged products, automatic SEO (branch `lean-store`)

### Fixed
- **"Something looks wrong with this form" on checkout, bank transfer and the wholesale form.** The hidden spam-trap field was called `website`; Chrome auto-filled it and the shop then refused the real customer. It is now `hp_extra` (`SHOP_HONEYPOT_FIELD`), and if a browser still fills it the form empties it and sends once more, silently.
- **No e-mails and no push notifications.** They wait in the queue and nothing was taking jobs. `php artisan serve` (local) now starts a queue listener and the scheduler next to the server, and on any host without a worker the mails and pushes of an order are sent by the web request right after its response (`SHOP_JOBS_WITHOUT_WORKER`). The test notification of Settings → Notifications is sent at once when no worker runs (it waited 25 seconds for one before).
- **A fixed discount on a chosen product ("Rs 50 off this toy") did not lower the price.** It is now taken off every unit of the products / categories it covers and shows on the cards and the product page; the biggest saving wins against a store-wide discount. A fixed amount on the whole store is still taken off the order once.
- **A new category was missing in the product form** until a hard refresh: the product screens read the newest categories on every visit and after every category change.
- **The panel was not real time.** The order and wholesale lists, the dashboard and the badges follow new orders and changes within seconds (a light call every 5 seconds; the lists reload by themselves, without a flicker). Before, only the badge moved, every 30 seconds.

### Faster
- **Pages open in a fraction of a second** instead of 2 to 6 seconds: `nuxt dev` no longer renders a payload file on the server for every click, `php artisan serve` runs with OPcache (0.5 s → 0.1 s per answer), and nothing of the shop is cached in the browser on a developer machine.
- **Add to Cart answers at once** (the cart opens, the server call finishes in the background).

### Added
- **Arrange the products by drag and drop** (Products screen, unfiltered list; `PUT /admin/products/reorder`, `products.sort_order`). The website's default order is now "Recommended" = this order (new products first, then popularity).
- **Automatic SEO**: the page title and description of a product are made from its name, category and descriptions (the admin form fills them while the owner has not typed their own; the website does the same when the fields are empty; `seo_auto` in the admin product).
- **Track your order** link in the footer (the page exists: order number + the phone used).
- The order progress (Pending → Delivered) is joined by a line.

### Changed
- Footer and the announcement bar wear the colour of the logo icon; the announcement is always one line (a long text scrolls like a ticker on a phone).
- Product cards: name, short description, the old price (light, struck through) in its own line and the price to pay under it in a calmer size; more space between the cards.
- Home hero on a phone: smaller button, the picture is shown clear (no veil over it) and the four-feature bar sits half on the picture.
- The video upload starts as soon as the file is chosen (no "Add to product" step); the Website tab is shown to the developer account only; the "Verify the bank transfer payment first" line next to the Confirm button is gone.

### Tests
- PHPUnit: per-unit fixed discounts, arranging products, automatic SEO, the order version, the no-worker fallback.
## 2026-11-09 (4) – Announcement bar from the admin, WhatsApp and back-to-top buttons, the colour `#3BBFCC` everywhere (branch `lean-store`)

### Added
- **Announcement bar** above the header (the thin dark line of the reference site). New settings in Settings → Store: `announcement_enabled` (on / off) and `announcement_text` (up to 160 characters); public settings carry `announcement: { enabled, text }` (shown only when it is on and has a text). Off by default.
- **Round WhatsApp button** in the bottom-right corner (from the WhatsApp number of Settings → General, else the WhatsApp link of Footer & Social) and a **back to the top** button under it that appears after a scroll.

### Changed
- **One colour everywhere.** The primary and accent colours are used exactly as set (`#3BBFCC`) for buttons, tags, pills and the two-colour headings, with the theme's own text colour on them (white). Nothing is darkened any more (only the hover); buttons get a faint text shadow so white stays legible. The admin panel does the same: exact colour for buttons, login picture and active marks, a slightly darker copy only for coloured text on light backgrounds (3:1).
- "Developed by [logo]": the logo is cropped to its artwork, 36 px high, with a small gap after the words.
- The sort menu comes before the one / two per row switch.

### Tests
- PHPUnit: the announcement setting (off by default, text required, 160 characters, public answer). Storefront browser tests: buttons have the exact primary colour, announcement bar on / off, WhatsApp button, back to the top.

## 2026-11-09 (3) – Logo-colour theme, one page layout, tidier shop and Access Control, no Tags screen (branch `lean-store`)

### Changed
- **Theme = the logo icon colour, exactly.** Buttons, tags and the admin panel's marks use the primary colour `#3BBFCC` itself instead of a darkened shade; the text on it is the dark "text on primary" colour (`theme_primary_contrast` `#0B2F35`, 6.9:1) so it stays readable. The admin panel keeps a darkened copy only for coloured *text* on light backgrounds. (If the contrast colour is not readable the storefront and the panel still fall back to a darker shade / white.)
- **Same page header everywhere** (breadcrumb, title, line under it, all on the left): Shop, categories, About and the policy pages, Wholesale, Cart, Checkout, Track, Order.
- **Shop:** the sort is a small quiet "Sort: Most popular" menu (it opens a short list instead of the native select that broke on a phone); on a phone the toy count, the one / two per row switch and the sort share one row.
- **Product card:** Add to Cart is on the right, centred only when two products share a row on a phone.
- **Footer:** on a desktop the copyright is on the left and "Developed by [logo]" on the right (centred and stacked on a phone). It now also shows the phone, e-mail and support hours from Settings, next to the social links.
- **Focus rings:** the hard blue outline inside the search box and on the header buttons is gone (the search box has a soft ring of its own); the global focus rule moved to the base layer so utilities can override it.
- **Admin → Access Control:** one horizontal list per role: a small heading per section, thin lines between sections and rows, the switch always on the right, no boxes.
- **Admin → Settings → Footer:** the agency logo preview sits on a dark tile (the logo is white).

### Removed
- **Tags screen in the admin** (the Tags button, the per-product tag button, the tag filter and the tag picker in the product form): the badge text is the label. Tags a product already has are kept.

### Dev database
- Demo footer data: Instagram, Facebook and WhatsApp links, footer text, phone, e-mail and support hours.

## 2026-11-09 (2) – Phone layouts, simpler shop / product / wholesale pages, theme #3BBFCC (branch `lean-store`)

### Changed
- **Shop:** no second search box, no price range and no in-stock box (the header has the search); the number of toys and the sort are on one line, also on a phone. A link with `?min=&max=&stock=1` still works.
- **Product page:** no fixed bottom bar and no trust points; the quantity has its own row, then **Add to Cart and Buy Now side by side**, the same on a phone and a desktop. The little scroll arrows next to the Description tab are gone.
- **Wholesale:** only the enquiry form, centred (no "Get in touch" card).
- **Home:** no "Shop by category" row (one request less); the hero picture is cropped a little more at the top; the trust bar is compact on a phone (small icons, title and text on one line each).
- **Phone:** smaller logo, the menu opens over the page instead of pushing it down, the product card's Add to Cart is centred, checkout and cart are centred and cannot be pushed sideways (`overflow-x: clip`, grids with `minmax(0, 1fr)`); the coupon box and its button are smaller everywhere.
- **Footer:** the last lines are centred, one under the other: copyright, then "Developed by [logo]".
- Dev database theme: primary `#3BBFCC`, accent `#1A93A0`, background `#F5FBFC`, border `#D9EDF0`.

### Housekeeping
- `backend/storage`: removed the pictures, payment screenshots and logs left behind by test runs (everything not used by the dev database). Test runs now start their media ids at 1000 so they never share a folder with the dev media.

## 2026-11-09 – Product form, bank transfer at checkout, wholesale page, new shop / product / about layouts, a much faster storefront (branch `lean-store`)

### Added
- **Bank transfer at checkout.** Choosing bank transfer now shows the bank details (with copy buttons), the amount to transfer and a screenshot upload right on the checkout page. New admin setting `pay_bank_proof_required` (Settings → Payments, asked only once bank transfer is on, default **on**): when on, the order is not placed without the screenshot (`422 proof`, checked by the server too). The order and the screenshot travel together (`POST /orders` as multipart, `proof` + optional `reference`); public settings carry `payment.bank_transfer.proof_required`.
- **Automatic SKU.** A new product's SKU is made from its name (`SNAKE-TRAIN`) while the admin has not typed one; the server also makes a unique SKU (`SNAKE-TRAIN-2`) when none is sent.
- **Video files before the product exists.** The Video section of a new product takes video files at once (they wait in the form and are uploaded with the save, with a progress figure); no more "save first".
- **Wholesale page** (`/wholesale`) replaces Contact: the enquiry form with the contact details beside it; menu and footer say Wholesale; `/contact` redirects to it.
- `backend/serve-fast.cmd`: starts the API for local development with OPcache and 4 workers (about 8 times faster than `php artisan serve` on Windows: 0.45 s became 0.06 s per request).

### Changed
- **Currency code** is any short code of 1 to 10 letters ("Rs", "PKR"), no longer exactly three (migration widens `payments.currency`). Search-engine data (JSON-LD) only names the currency when it is a three-letter code.
- **Admin:** the Bulk price button and popover are gone from the options table; the "Developed by" credit is gone from the admin panel (sidebar and login); it stays at the bottom of the website. The Rivoxa logo is a transparent PNG now (no black badge).
- **Storefront:** the header is see-through on the home page and the hero runs under it (solid after a scroll); product page has the pictures and one details panel side by side (name, option, price, quantity, buttons, delivery, trust points); the product card shows a one-line description and a warm-coloured corner tag (badge text, else "Featured"); the shop has category chips, a search box and a toolbar with price / stock / sort; About and the policy pages have a header band, headings on the left and text on the right; the cart drawer shows Subtotal, Delivery and Total (no explaining sentence); the Shop now button on the About page was unreadable (the page's link colour overrode the button's) and is fixed.
- **Speed.** The home page fetched the whole catalogue to show 12 products (now one request for 12 and the total), every visit repeated its calls (now a minute of memory: `useFastData`), the cart was read again each time the drawer opened (now skipped within 15 s of the last answer), checkout read the cart and the quote one after the other (now together).

### Tests
- PHPUnit: required / optional screenshot at checkout, automatic SKU, short currency codes. Storefront browser tests: bank panel and required screenshot, wholesale page, see-through header, one-line card descriptions, shop layout. flow-check / flow-ui: currency code, proof setting, no Bulk button, video chosen before saving, automatic SKU.

## 2026-11-08 – Rivoxa Tech credit, everything the admin controls comes from the admin, theme from the logo (branch `lean-store`)

### Added
- **"Developed by [logo]"**: new setting `agency_logo` (Settings → Footer & Social, developer only; upload `POST/DELETE /admin/settings/agency-logo`, PNG / JPG / WEBP up to 1 MB). The agency credit now defaults to **Rivoxa Tech** / **https://rivoxa.tech** (`agency_name`, `agency_url`) and the public settings carry `footer.agency.logo_url`. It is shown at the very bottom of the **storefront** (right of the copyright) and in the **admin panel** (bottom of the sidebar and under the login form). No name = no credit. The Rivoxa logo, trimmed, is `design/rivoxa-logo.png`.
- The admin panel wears the store's **primary colour** (Settings → Theme), darkened until white text on it is readable (4.5:1), so the panel matches the logo and the storefront.
- Tests: agency defaults, logo upload / removal / limits (`SettingsTest`), the access matrix and the docs table for `agency_logo`; storefront browser tests for the admin-driven theme, tagline, logo and credit (124 checks).

### Changed
- **Nothing fixed in the storefront that the admin panel controls.** Removed: the toy hero and gift texts and pictures (no `home_hero` banner = a plain hero with the store name and tagline; no `home_secondary` banner = no strip), the "Play. Learn. Grow." and "Safe Toys. Happy Kids." taglines, the bundled JHK logo (no logo = the store name as text), the bundled favicon (the favicon, else the logo), the fixed Poppins font and the teal colours of the style sheet (neutral defaults = what Settings → Theme starts with), the teal-tinted hero overlay and search box (now theme colours).
- The theme of the admin panel is applied with a more specific rule (`html:root`), because in `nuxt dev` the default `:root` of the style sheet loaded last and overrode it. `nuxt dev` no longer keeps the settings for 5 minutes, so a change in the admin panel shows at once.
- Dev database: theme set from the logo colours (primary `#1AA1A8`, accent `#0E8F96`, text `#16242A`, see STOREFRONT.md), tagline, Rivoxa credit with its logo, two design banners.

### Fixed
- The logo of the Rivoxa credit did not show in the panel after an upload: the cached picture links were keyed by the host of the request, so an upload through one address did not clear the entry read through another. The key now depends on configuration only (`APP_URL`, disk URL).


## 2026-11-08 – Fix: an uploaded logo did not show; the Settings tab strip had two scrollbars (branch `lean-store`)

### Fixed
- **Logo / favicon / share picture not showing after an upload.** Picture links are absolute and were cached forever (`settings.files`) with the address they were built with; a changed `APP_URL` kept serving links to the old host (here `localhost:8000` while the API runs on 8002), so the browser could not load the picture and the panel fell back to the initials. The cache key now includes the site address and the picture disk URL (`Settings::filesCacheKey()`), so a changed `APP_URL` takes effect without clearing the cache. Test: `SettingsTest::test_picture_links_follow_a_changed_app_url`.
- `backend/.env.example` (and the local `.env`): `APP_URL` is `http://localhost:8002` (the port every README command and the admin dev proxy use) and `SANCTUM_STATEFUL_DOMAINS` includes `localhost:8002` / `127.0.0.1:8002`; `admin-frontend/README.md` says 8002 too.
- Admin Settings: the tab row (which scrolls sideways when there are many tabs) showed a vertical **and** a horizontal scrollbar with arrow buttons. It now has a slim horizontal bar only; the tabs no longer overlap the bottom line.

### Note
- A logo, favicon or share picture is saved the moment it is chosen (toast "Logo updated."); the *Save changes* button stays grey because there is nothing left to save. That is by design.

## 2026-11-08 – Storefront moved to Nuxt 4 (branch `lean-store`)

Nuxt 3 reached its end of life on 31 July 2026, and the current `@nuxtjs/sitemap` (9.x) and `@nuxtjs/robots` (7.x) need Nuxt 4.6 or newer. This replaces the "Nuxt 3 with the Nuxt 4 folder layout" decision of the storefront entry below. Documentation: [docs/STOREFRONT.md](docs/STOREFRONT.md), section *Framework version and support*.

### Changed
- `frontend/`: Nuxt 3.21.11 → **4.6.4**, `@nuxtjs/sitemap` 7 → **9.1**, `@nuxtjs/robots` 5 → **7.0**, `vue-router` 4 → 5, `zod` 4 added (peer of both modules). `future.compatibilityVersion` removed (the `app/` layout is the default). Still a static `nuxt generate` build with **no server routes** (`.output` holds only `public/` and `nitro.json`).
- Node 22.22.3+ or 24.15+ is required by the modules (README, Cloudflare `NODE_VERSION` = 24 in STOREFRONT.md).
- Browser tests: Nuxt 4 logs a bare `[NUXT_E1005]` in the console for an unknown page (the friendly 404 page still shows); the test expects it for that one step only.
- Admin `flow-ui` script: a navigation is retried once when Chrome reports "Navigating frame was detached" (a Chrome flake, not an app fault).

### Checked after the upgrade
- `npm run generate`: 138 routes (as before), `sitemap.xml` (65 addresses) and `robots.txt` as before; a product page has its title, canonical link, Open Graph tags, description, `Product` and `BreadcrumbList` JSON-LD in the HTML; browser tests 119 / 119.

## 2026-11-08 – The customer storefront (branch `lean-store`, Stage 3)

A **static Nuxt 3 website** in `frontend/`, built to match the reference design. Documentation: [docs/STOREFRONT.md](docs/STOREFRONT.md).

### Added
- `frontend/` (own `package.json`, `jhk-store-frontend`): Nuxt 3.21 with the `app/` folder layout, Vue 3, TypeScript, Tailwind CSS 4, self-hosted fonts (`@nuxt/fonts`), `@nuxtjs/sitemap` 7 and `@nuxtjs/robots` 5 (the Nuxt 3 lines of these modules), a small icon set (`@lucide/vue`). `nuxt generate` with the static preset: 138 prerendered pages for the demo catalogue (home, shop, every category and sub-category, every product, the static pages, contact), the client-only pages as shells, `200.html` / `404.html`, `_redirects`, `_headers`, `.htaccess.example`. Nothing host-specific, no server routes.
- Pages: home, shop, category and sub-category, product (gallery with video and zoom, options, quantity, bulk hints, delivery date, tabs, related, sticky bar), cart drawer and page, one-page checkout (cash on delivery, bank transfer with the proof upload, every error code), order and tracking pages, About, Shipping, Returns, Terms, Privacy (static content in `app/content`, filled from the settings), contact with the bulk and wholesale form, 404.
- The theme of the API becomes CSS variables (baked into the HTML); buttons, pills and badges get a darker shade automatically when the theme colour is below 4.5:1 with its text colour.
- Admin panel: the banner title help says that *words between asterisks* are shown in the accent colour (the two-colour hero heading).
- `design/logo.png` (redrawn from the reference screenshot: no separate logo file was provided).
- Backend (one small change, tested): product **cards** carry `variant_id` and `min_order_qty`, so "Add to Cart" works straight from a card.
- Tests: `frontend/tests/e2e.mjs` (119 browser checks over the generated site), `frontend/tests/shots.mjs` (screenshots at 390, 768 and 1440 px).

### Decisions
- Nuxt **3** (the brief), with `future.compatibilityVersion: 4` for the `app/` folder layout; the current sitemap / robots modules need Nuxt 4, so their Nuxt 3 lines are used.
- The "1 Option" / "N Options" badge of the API is not shown on cards (it would be on every picture); the shop's `badge_text` and "N Offers" are.
- No heart, star, review or account icon anywhere (the reference has them; the brief forbids them).

## 2026-11-08 – Restructure into three folders and the store is called "JHK Store"

### Changed
- **Layout**: the Laravel app moved into `backend/` (history kept, one dedicated commit), the admin panel stays in `admin-frontend/` and builds into `backend/public/admin-assets`, the customer storefront lives in `frontend/`, `docs/` and `scripts/` stay at the root, the design images are in `design/`. New root `README.md` and `.gitignore`; each folder has its own ignore file.
- Paths fixed everywhere: the admin Vite `outDir` and dev proxy (API on port 8002), the e2e and flow scripts (they run `php artisan` in `backend/`; `flow-db.php` finds `backend/vendor` and `backend/.env`), the tests that read the docs (`../docs`), `composer setup`, every command and path in the docs, `NEW_CLIENT.md` (a cPanel section: document root at `backend/public`).
- **Name**: the default `store_name` (Settings and seeder) is **JHK Store** (it was "My Store"), `APP_NAME="JHK Store"` in `backend/.env` and `.env.example`, package names `jhkstore/backend` (composer) and `jhk-store-admin`. The name in the admin panel, login page, e-mails (sender name, subjects, templates), print views and the storefront always comes from the `store_name` setting; nothing is hard-coded. Database names are unchanged (`jhkstore_db`, `jhkstore_test`). Tests assert the new default.
## 2026-11-08 – Storefront preparation (branch `lean-store`, Stage 1 of the storefront work)

The backend is made ready for a **static** customer storefront (prerendered pages on a CDN such as Cloudflare Pages). No table and no migration was added (46 tables).

### Added
- **`GET /store/sitemap`**: slugs, `updated_at` and one picture of every visible product, category and sub-category; no prices; cached 5 minutes, dropped on every storefront-relevant admin write; a fixed number of queries.
- **Cache headers**: the public catalogue reads (settings, banners, categories, products, tags, sitemap) send `Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=60` and an `ETag` (304 on `If-None-Match`); values in `config/shop.php`. Everything else (cart, quote, coupons, orders, lookup, proof, wholesale, errors, anything with `X-Cart-Token`, all admin routes) is `no-store`. A test walks every store route.
- **Settings → Website** and the permission `settings.website` (admin: on by default): a deploy hook rebuilds the static site after a change customers can see. Settings `rebuild_enabled` (client), `rebuild_hook_url` (https only), `rebuild_hook_secret` (new type `secret`: stored encrypted, never returned), `rebuild_debounce_seconds` (default 120) (all advanced). One debounced queued job (`TriggerRebuild`: empty POST, 10 s timeout, 3 tries with 30 s / 120 s backoff) however many edits; orders, customers, carts, wholesale and sales never trigger it; a failing hook never fails the admin action. The tab shows the changes waiting, last request, last result and a warning, with a **Rebuild website now** button; `php artisan shop:rebuild`; manual rebuilds and hook changes are audited (the secret never, the hook address reduced to its host).
- **SEO fields**: `updated_at`, `availability`, `image_width` / `image_height` on product cards; `seo { title, description, image_url }`, `breadcrumbs` and the size of every picture on the detail; `meta_title`, `meta_description`, `updated_at` on categories and sub-categories.
- **CORS**: `http://localhost:3000` in `FRONTEND_URLS`, `FRONTEND_URL_PATTERNS` for preview sites (`*.pages.dev`), `X-Cart-Token` exposed (a test).
- **DemoSeeder** rewritten for the storefront developer: 6 toy categories (one without sub-categories), 40 products with options, bulk prices, the tags best-seller / new-arrival / featured, generated local pictures (GD), 3 banners, a discount, the coupon `WELCOME10`, two delivery zones, bank transfer on. Still separate from the base seeder.
- Banner title help: *words between asterisks are shown in the accent colour*.
- Docs: `docs/STOREFRONT_BRIEF.md` (new), STOREFRONT-API sections 10 to 12, SETTINGS (Website), ACCESS_CONTROL, ARCHITECTURE, NEW_CLIENT 6b (how to create the deploy hook, the queue, the 1 to 3 minutes), Postman sitemap request.

### Changed
- The Settings page has 13 tabs (Website last); the default admin gets `settings.website`.
- Existing shape tests list the new card and category fields.
## 2026-11-05 – Phase C (branch `lean-store`)

Polish, a clean permission split of the settings and proof that push notifications work.

### Added
- **Setting tiers**: every setting is `client` or `advanced` (`Settings::access()`); the new permission `settings.advanced` (developer on and fixed, admin off) is needed for advanced fields on top of the tab permission. Enforced on `GET` (fields, values and empty tabs are not sent) and `PUT` / picture endpoints (403 for any forbidden key, a mixed request changes nothing); `settings.theme` stays developer only; the banner routes need `settings.banners` **and** `settings.advanced`. Table in `docs/ACCESS_CONTROL.md` (a test checks every setting has a row).
- **Access Control screen**: every permission shows what it covers and a `Client` / `Developer / advanced` tag; settings permissions list the individual settings in a collapsible list (`tier`, `covers`, `fields` in `GET /admin/access-control`).
- **Notifications**: settings per event and channel (`mail_on_fee_confirmed`, `owner_mail_on_placed|proof|wholesale`, `push_on_placed|proof|wholesale`); the tab is an events table, a sender card, a test e-mail and status.
- **Push**: `PushMessage` (title "New order", order number and total, logo icon, tag per order, link), `requireInteraction`, the service worker tells open panel tabs; `POST /admin/push/test` + `GET /admin/push/test/{id}` (real push through the real queue, exact error); `PushLog` (last push); `QueueHealth` (`running` / `stopped` / `idle` / `sync`) and the scheduled `shop:queue-heartbeat`; `GET /admin/notifications/failed-jobs`; the *Notification status* card (tab and header popover). `docs/PUSH_TEST.md`.
- **Panel**: alert chime (bundled `chime.wav`, no external request) with mute toggle and an autoplay hint, toast when the pending count rises; the store logo in the sidebar header and on the login page (favicon on the collapsed sidebar), favicon as tab icon, page title from the store name; dashboard redesign with `orders.comparison` (change against the previous period) and `thumb_url` / `price` of the top sellers; a quieter sidebar with section labels.
- Tests: `SettingsAccessTest`, `NotificationStatusTest`, `DashboardComparisonTest`; flow-check section H33; smoke / flow-ui updated; `admin-frontend/e2e/screenshots.mjs`. Results: 510 PHPUnit tests, flow-check 483 assertions, flow-ui 190 checks, smoke 77 checks, all pass.

### Changed
- `support_hours`, `whatsapp_number` moved to the General group, `min_order_amount` and `delivery_eta_text` to the Delivery group (keys unchanged, so the public API is the same).
- Admin defaults: ON General, Store, Delivery, Payments, Notifications; OFF Footer & Social, Branding, Banners, SEO, Currency & Tax, Orders & Delivery, Advanced, Theme. Existing databases keep what the developer switched (`AdminAccess::sync()` only adds the new permission and gives it to the developer).
- The "delivery fee confirmed" e-mail has its own switch (it followed `mail_on_placed`).
- `GET /admin/notifications/status` is restructured (`queue.state`, `push.last`); technical fields only with `settings.advanced`.
- The Settings page, Access Control, Notifications and the dashboard layouts; colours and fonts are unchanged.

## 2026-11-03 – Phase B (branch `lean-store`)

Dashboard, compact product screens, product video, wholesale requests, order e-mails, admin push and small extras ([docs/FEATURE_SPEC.md](docs/FEATURE_SPEC.md), sections 10 to 17), plus three fixes after the Phase A work. The baseline migration was edited directly again (no live database exists): **a database created from an earlier version must be rebuilt with `php artisan migrate:fresh --seed`**. 46 tables (45 created by the baseline migration plus `migrations`; was 44).

### Fixed (Phase A follow-up)
- **Bank transfer snapshot**: `payments.bank_snapshot` keeps the accounts and instructions the customer was shown when the order was placed; the admin order detail and the customer tracking answer now show that snapshot, not the current settings (a changed account no longer rewrites old orders).
- **Time zone**: `APP_TIMEZONE` is now the shop time zone everywhere. The API sends every date with the shop's offset (it was UTC), `GET /admin/auth/me` has `timezone`, a date typed in the panel is shop time (an offset is converted, `App\Support\ShopTime`), the panel shows, types and compares dates in that zone, discount, coupon and banner windows follow it, and the dashboard ranges are shop days. A discount ending at 23:59 shop time ends then, whatever `APP_TIMEZONE` is (tested in three non-UTC zones).
- **OpenAPI and Postman**: the title comes from `APP_NAME` and the server URL from `APP_URL`; the committed `docs/openapi.json` and the Postman collection contain no client name and no `localhost:8000` (the collection uses the variable `origin`).

### Added
- **Dashboard**: `GET /admin/dashboard?range=today|7d|30d|month|all` (default 30 days, shop days) with KPI cards (revenue, pending amount, orders, average order, pending, cancelled + rejected, awaiting payment proof), a daily (monthly for a long all-time range) series drawn as a plain SVG chart, the 5 top sellers, the 8 newest orders, the low-stock options (8) and product counts, new wholesale requests and new customers. Each section is sent only with its permission (`orders.view`, `products.view`, `wholesale.view`, `customers.view`); the developer account gets the catalogue widgets only.
- **Sidebar badges**: `GET /admin/orders/pending-count` (pending orders + payment proofs to check) and `GET /admin/wholesale/new-count`, polled every 30 seconds.
- **Products**: a dense list (image, name, category, price, stock, status, sold-out and featured switches; filters category, status, stock, search) and a compact form of collapsible sections (open on create, remembered per section), options as an editable table with a bulk-price popover and the low-stock alert level; `PATCH /admin/products/{id}/sold-out` and `/featured` (`products.manage`, audited); the sold-out product stays visible, cannot be ordered (`sold_out` issue) and is skipped by `?in_stock=1`; `GET /store/products?featured=1`; cards carry `is_sold_out` and `is_featured`; the sub-category field is hidden when no sub-category exists; denser category list.
- **Product video**: up to 2 uploaded mp4 / webm files (content checked, `PRODUCT_VIDEO_MAX_KB`, default 50 MB) with an optional poster (2 MB), upload with progress, reorder and delete in the product form, and an optional https YouTube / Vimeo / direct `video_url`; the public product detail has `videos [{url, poster_url, mime, source}]` (no extra queries).
- **Wholesale requests**: table `wholesale_requests`, public `POST /store/wholesale-requests` (honeypot, 5 an hour per IP and per phone, the customer is found or created by phone, the answer is the reference only), admin Wholesale screen (status tabs, search, detail with note and status, CSV export), permissions `wholesale.view` / `wholesale.manage` (admin on, developer off but switchable).
- **Order e-mails**: queued mailables after the commit (order received, confirmed, shipped, delivered, cancelled or rejected, payment proof rejected, delivery fee confirmed) and alerts to the owner address (new order, payment proof, wholesale request); plain templates, no images or tracking pixels; the Notifications tab (`mail_enabled`, sender name, reply-to, owner address, one switch per event, `storefront_track_url`), `POST /admin/notifications/test-email`, `GET /admin/notifications/status` (counts incl. failed jobs). A mail failure never fails an order. `QUEUE_CONNECTION=database` needs a worker or a cron job ([docs/NEW_CLIENT.md](docs/NEW_CLIENT.md)).
- **Admin push** (Web Push, `minishlink/web-push`): `php artisan shop:vapid`, `VAPID_*` in `.env`, table `push_subscriptions`, `POST /admin/push/subscribe`, `POST /admin/push/unsubscribe`, `GET /admin/push/status`, the service worker `public/admin-sw.js` (scope `/admin/`), a bell button in the header, setting `push_enabled`. New orders and payment proofs go to subscribed admins with `orders.view`, wholesale requests to those with `wholesale.view`, checked when the job runs: the developer never gets order notifications. 404 / 410 delete a subscription; a push failure never fails an order.
- **Extras**: WhatsApp buttons (order, wholesale request, abandoned cart; `https://wa.me/<digits>?text=…` built in the panel), orders CSV (`GET /admin/orders/export`, `orders.export`, same filters as the list, audited), the abandoned carts tab of Customers (`GET /admin/customers/abandoned-carts` and `/export`; a saved phone or e-mail, items, 2 hours quiet, no order since), the low-stock dashboard widget.
- **Access control**: permissions `orders.export` (never the developer), `wholesale.view`, `wholesale.manage` (`AdminAccess::DEVELOPER_OPTIONAL` with `customers.*`), `settings.notifications`; the admin starts with all four; of these the developer starts with `settings.notifications` (the others are not given by default: `orders.export` never, `wholesale.*` off but switchable).
- Rate limits: wholesale form 5/hour per IP and per phone; test e-mail 5/min. Config: `PRODUCT_VIDEO_MAX_KB`, `PRODUCT_VIDEO_POSTER_MAX_KB`, `WHOLESALE_PER_HOUR`, `WHOLESALE_PER_PHONE_HOUR`.
- Tests for every rule above (including the developer blocked from order data in the dashboard, e-mail, push, export and wholesale defaults, a mail failure not failing an order, push recipients, wholesale limits and customer upsert, video validation), `scripts/flow-check.mjs` sections G24 to G32 (a full run over real HTTP) and the browser scripts.

### Changed
- `DashboardController` answers `catalog` only to `products.view` and the order sections under `orders.kpis`, `orders.series`, `orders.top_products`, `orders.recent` (`orders.today`, `orders.revenue_today` and `orders.pending` stay).
- Product list rows have `is_sold_out`, `is_featured` and `low_stock`; the admin product list takes `?stock=in|low|out|sold_out` and `?featured=1`.
- The Customers screen has two tabs (customers, abandoned carts); the orders screen has an Export CSV button.

### Notes (where the spec left a choice)
- The sidebar count is pending orders **plus** payment proofs waiting to be checked (an order with a proof counts twice: it needs two looks).
- Every KPI card follows the chosen range; "awaiting payment proof" means a pending bank transfer order whose customer has not uploaded an accepted screenshot yet.
- "Running low" in the stock filter and the widget means available units at or below the option's alert level, so options with nothing left are included.
- E-mails carry no logo (no external image, not even the store's own); the store name, address and phone are plain text. The "delivery fee confirmed" mail follows `mail_on_placed`.
- Video files can only be uploaded after the product is saved (a link can be typed at once).
- An abandoned cart counts as bought when an order by the same phone or e-mail was placed after the cart's last activity.

### Tests and checks
- PHPUnit: 384 → 476 tests, all pass. `scripts/flow-check.mjs` 457 assertions (A1 to G32, including a full run of the whole shop), `admin-frontend/e2e/flow-ui.mjs` 176 browser checks, `npm run e2e` 68 checks. Details in [docs/QA_REPORT.md](docs/QA_REPORT.md).

## 2026-11-03 – Phase A (branch `lean-store`)

Settings foundation, delivery, payments, discounts and coupons, a persistent cart and order changes ([docs/FEATURE_SPEC.md](docs/FEATURE_SPEC.md), sections 2 to 9). The baseline migration was edited directly (no live database exists): **a database created from an earlier version must be rebuilt with `php artisan migrate:fresh --seed`**. 44 tables (was 36).

### Added
- **Settings**: tabs **Delivery**, **Payments**, **Store** and **Theme** (developer only); new setting types `color`, `choice` and `accounts`; theme tokens (colours, fonts from a fixed list, radius, shadow) public as `theme` in `GET /store/settings`; store open / closed with a closed message, a checkout notice and a delivery estimate text (a closed store still quotes, `POST /orders` answers `422 store_closed`); agency credit (`agency_name`, `agency_url`, https only) as `footer.agency`; public `payment` and `delivery.method`.
- **Delivery**: setting `delivery_method` (`flat`, `free`, `free_above`, `zones`, `owner_confirms`) and `delivery_unmatched` (`block`, `owner_confirms`, `flat`); table `delivery_zones` with the zone manager inside Settings → Delivery (city / area / postcode with `*` prefix, fee, free-above, minimum order, ETA, order, active); the quote takes an `address` and answers `delivery_fee` (null when unknown), `delivery_pending`, `delivery_needs_address`, `delivery_zone`; `delivery_fee_pending` orders with `PUT /admin/orders/{id}/delivery-fee`.
- **Payments**: `payment_method` `cod` or `bank_transfer` (`payment_method_unavailable` when switched off; the cash on delivery limit applies to cod only; bank accounts in Settings; card is only a "coming soon" label); the customer uploads a proof (`POST /store/orders/{number}/payment-proof?phone=`, private disk, wrong phone = the same 404); the admin sees it only through `GET /admin/orders/{id}/payment/proof`, verifies it (`payment/verify`) or rejects it (`payment/reject-proof`); an order cannot be confirmed before (`payment_not_verified`).
- **Discounts and coupons**: automatic discounts and coupons (tables `discounts`, `discount_targets`, `coupons`, `coupon_targets`, `coupon_usages`) calculated in `CartPricing`, admin screens and API (`/admin/discounts`, `/admin/coupons`, `/admin/coupons/{id}/usages`, `/admin/promotion-targets`), `POST /store/coupons/validate`, discounted prices and `discount_label` on product cards and the product page, order snapshots (`coupon_id`, `coupon_code`, `discount_total`, `coupon_discount`, line `discount_amount` / `discount_source`), usage written under a row lock (two orders cannot take the last use) and given back when the order is cancelled or rejected.
- **Persistent cart**: `GET/POST/PUT/DELETE /store/cart…` bound to a random token (`X-Cart-Token`), contact and coupon on the cart, `cart_token` accepted by the quote, the coupon check and `POST /store/orders` (the cart is emptied inside the order transaction), 30 days sliding expiry, `php artisan shop:prune-carts` scheduled daily.
- **Orders**: status `rejected` (only from pending, reason required, gives back stock, customer totals and the coupon use); order list filters `search`, `status`, `payment_method`, `payment_status` and per-status `counts`; the customer tracking answer has `payment {method, status, proof_status, reject_reason, …}`, `delivery_fee_pending`, `reject_reason`, `store_phone` and `whatsapp_url`.
- **Access control**: permissions `settings.theme` (developer only, never the admin), `settings.store`, `settings.delivery`, `settings.payments`, `discounts.view` / `discounts.manage`, `coupons.view` / `coupons.manage`. The admin starts with all of them except `settings.theme`; the developer with all. A permission added by an update is given to the roles whose defaults include it and never overrides what the developer switched.
- Rate limits for the cart (60/min), the coupon check (30/min) and the proof upload (10/min per IP, 10/hour per order number); config `PAYMENT_PROOF_MAX_KB`.
- Tests, `scripts/flow-check.mjs` (section F: Phase A over real HTTP, including the last coupon use across two PHP processes), the browser scripts and the docs, OpenAPI and Postman for all of it.

### Changed
- `delivery_fee`, `free_delivery_min_cart` and `delivery_days` moved from the Orders & Delivery tab to the new Delivery tab (permission `settings.delivery`); the keys are unchanged. The default `delivery_method` is `free_above`, which is the behaviour of earlier versions.
- `orders.shipping_fee` can be null (fee still to be confirmed); the product `price` on cards and detail pages is the price after a live percent discount (`option_price` has the option's own price, `compare_price` and `discount_label` are new).
- The dashboard's revenue and order count leave out rejected orders as well as cancelled ones.
- Settings → Orders & Delivery keeps the order number prefix, the minimum order and the cash on delivery limit.

### Fixed
- `PATCH /admin/orders/{id}/note` without the field `admin_note` answered `200` and **cleared the stored note**; the field is now required (`present`, null or "" clears on purpose) and a missing field is a `422`. Regression test: `OrderChangesTest::test_a_note_request_without_the_field_is_refused_and_does_not_clear_the_note`.

### Notes (where the spec left a choice)
- A **fixed** discount or coupon amount is taken off the eligible lines once, split by line total (remainder to the last lines); product cards and detail only apply **percent** discounts to the shown price, a fixed one only gives `discount_label`.
- `discount_total` is the automatic discounts only, `coupon_discount` is separate; per line the better of discount and coupon applies, never both; `discount_source` is `discount`, `coupon`, `bulk` or null.
- The store's minimum order and a coupon's `min_order` are checked after bulk prices and automatic discounts, before the coupon; free delivery above an amount uses the final subtotal.
- A coupon that is beaten everywhere by an automatic discount answers `no_eligible_items` and is not counted as used; a coupon that takes nothing off and is not free-delivery is not recorded as used.
- `PUT /store/cart/coupon` with a bad code is `422 coupon_invalid` and is not saved; `items` win over `cart_token` when both are sent; an unknown or expired token prices an empty cart in a quote and is `422 cart_invalid` in an order.
- `coupon_usages.released_at` marks a use that was given back; a coupon with any usage row cannot be deleted (deactivate it). The usage list only shows order numbers and phones to an admin who holds `orders.view`.
- Bank transfer: only a pending order takes a proof, one at a time, a new one only after a rejection; enabling bank transfer needs an active account; a verified bank order that is cancelled stays `paid` (refund by hand).
- The quote and the coupon check accept an optional `phone` so the per-customer and first-order rules can be checked; without it those two rules are skipped in the quote (the order always checks them).
- The Phase A permissions were extended in Phase B by `orders.export`, `settings.notifications` and `wholesale.*` (the developer can never hold `orders.export`).
## Unreleased – lean store (branch `lean-store`)

The product is now three things – **products, categories and orders** – plus the admin settings that control the website. Everything else is removed for now. The baseline migration was edited directly (no live database exists): rebuild a development database with `php artisan migrate:fresh --seed`.

### Removed
- **Customer accounts**: registration, login, logout, tokens and refresh, passwords, the customer address book, the `actor:customer` side, every customer-authenticated store endpoint, `CUSTOMER_TOKEN_MINUTES`.
- **Server-side cart**: `carts`, `cart_items`, `X-Cart-Token`, the cart endpoints, cart merging and the `carts:prune` command. The storefront keeps the cart.
- **Loyalty points** (wallets, transactions, redemptions, their ledger class, the on/off setting and every earn / redeem setting, points on options), **customer feedback and ratings** (feedback, rating statistics, the rating filter and sort), **saved-item lists**, **coupons**, **notifications**, **FAQs**, **CMS pages**, homepage sections, collections, **invoices**, **refunds**, **webhook events**, shipment items and events, **pincodes** (and the check in `PlaceOrder`), product questions and feedback, password resets – code, routes, admin UI, settings, tests, seeders and docs. 36 tables remain.
- **Leftover columns**: `is_returnable`, `return_days`, `return_window_days` (setting), `returned_qty`, `expiry_date`, `barcode`, `users.business_name`, `country_of_origin`, `warranty_text`, every `tax_number`, `orders.membership_applied`, `rating` on categories and sub-categories, the refund bank / UPI / IFSC fields. The `warranty` content tab went with them.
- The generic **Users & roles** screen and API (`/admin/users`, `/admin/roles`), the roles `super_admin`, `manager` and `staff`, the Features settings tab and all feature switches, the Banners sidebar item (it is a Settings tab now), customer cancel and reorder.
- Test fixtures were neutralised (USD, `$`, `ORD`, "VAT", neutral country and phone numbers, no business-specific names).

### Added
- **Guest checkout**: `POST /store/checkout/quote` (server-priced totals, bulk prices, tax, delivery fee, warnings; reuses `CartPricing`), `POST /store/orders` with `customer{name, phone, email?}`, `address{…}`, `items[]`, `customer_note?`, `expected_total?` (keeps `Idempotency-Key`, the stock locking and `409 price_changed`), `GET /store/orders/{orderNumber}?phone=` (a wrong phone is a `404`).
- **Customers**: `users` became `customers`, a contact record (name, unique normalised phone, optional e-mail, city, first / last order, orders count, total spent) found or created by phone on every order; `orders.customer_id`. Admin **Customers** list with search and CSV export (formula-safe).
- **Abuse protection** for the public endpoints: per-IP, per-phone and per-order rate limits, a maximum number of items and quantity per line, phone validation, a honeypot field (`config/shop.php`).
- **Two admin accounts and access control**: roles `developer` and `admin`, permissions per section and per Settings tab, a developer-only **Access Control** screen and API, `SEED_DEVELOPER_*` / `SEED_ADMIN_*`. The developer can never hold `orders.*` (tested), sees no orders and no order figures on the dashboard. Permission changes are audited. [docs/ACCESS_CONTROL.md](docs/ACCESS_CONTROL.md).
- Admin panel: sidebar Dashboard, Orders, Products, Categories, Customers, Settings, Access Control; **Settings → Banners** tab with the full banner manager; per-tab Settings permissions.
- `docs/openapi.json`, a rewritten Postman collection and docs; the browser e2e covers both accounts.
- Tests: 270 → 227 (the removed modules' tests are gone; guest checkout, quote, customers, lookup, rate limits, access control added).

### Added (later)
- Setting `default_phone_country_code` (General tab, e.g. `+92`, empty by default): a phone with a single leading 0 and no country code gets this prefix, so `0300…` and `+92300…` are one customer (orders, order lookup, customer search). Empty = unchanged behaviour.

### QA
- Full flow check on the real app: `scripts/flow-check.mjs` (API, 239 assertions) and `admin-frontend/e2e/flow-ui.mjs` / `npm run flow-ui` (browser, 41 checks). Results and how to run them: [docs/QA_REPORT.md](docs/QA_REPORT.md).

### Fixed
- The admin **order search** and **customer search** for a local phone (`0300…`) now find the records stored with the default country code (`+92300…`).
- The demo seeder's bulk prices after the points column was removed were wrong; a test now checks them.

## Unreleased – stabilisation (branch `stabilize`)

Work done on top of the neutral base before any new feature. The baseline migration was edited directly (no live database exists): rebuild a development database with `php artisan migrate:fresh --seed`.

### Removed
- **Manufacturer labels, completely**: two tables, the product and order-line columns, models, actions, controllers, requests, resources, routes (the product filter and the fields in product, cart and order answers, the filter and field in the admin), the admin page and product-form field, the on/off setting and its public flag, seeders, tests and docs. Nothing replaces it.
- `PUT /admin/settings` no longer accepts the logo; `POST|DELETE /admin/settings/logo` stay (now stored with medialibrary).

### Added
- **Settings overhaul**: tabbed admin page (General, Branding, Footer & Social, Currency & Tax, Orders & Delivery, Features, SEO). New settings: favicon, ordered social / footer links (`links` type: platform, label, URL, active; unlimited; drag-to-reorder), footer text, copyright, support hours, WhatsApp number, default meta title / description, default share image. Logo, favicon and share image are uploaded (type, size and pixel limits; SVG refused) and stored with medialibrary. `Settings` gets the `links` and `file` types and `Settings::rules()` (validation by type). The admin payload is now `tabs` / `sections` / `files` instead of `groups` / `logo_url`.
- **Public `GET /store/settings`** now also returns `store.favicon_url`, `social_links[]` (active only, in order), `footer{}` and `seo{}`.
- **Banners**: admin management (create, edit, delete, reorder, activate, start / end dates, desktop + optional mobile picture, placements `home_hero`, `home_secondary`, `promo_strip`) and public `GET /store/banners?placement=` (live banners only, in order, absolute image URLs). `banners` table: `subtitle`, `button_text`, `link_url` replace `link_type` / `link_target`.
- **Product pictures**: `PUT /admin/products/{id}/images/{image}/primary`; uploads are limited to 100–8000 px (the admin already uploaded several pictures per product and per option, with reorder, delete and thumbnails).
- **Admin roles and permissions** (`super_admin`, `manager`, `staff`) enforced on every admin route with `admin.can:<permission>` (403 otherwise; a test walks all routes), `RolesSeeder`, `/auth/me` lists the permissions. **Users & roles** screen and API (`/admin/users`, `/admin/roles`): no self lock-out, the last active super admin stays, a new password or deactivation revokes API tokens.
- **Token expiry**: `CUSTOMER_TOKEN_MINUTES` (default 7 days) and `ADMIN_TOKEN_MINUTES` (default 12 hours); login / register return `expires_at`; new `POST /store/auth/refresh`; expired tokens are pruned daily.
- **Rate limits** for public reads (`PUBLIC_PER_MINUTE`, 240) and the admin API (`ADMIN_PER_MINUTE`, 600); every store and admin route is now limited. `APP_DEBUG` is forced off in production and the session cookie is secure by default in production.
- Admin panel: read-only forms and hidden buttons for roles without permission, "no access" page, `npm run e2e` browser smoke test (puppeteer-core), clearer validation messages (settings, banners, product pictures).
- Tests: 210 → 270 (manufacturer-label tests removed; settings, banners, pictures, permissions, admin users, tokens, security added).

### Fixed
- Admin panel: the product edit page and the order detail page never opened (`\d+` in a JavaScript string became `d+`, so the routes did not match).
- "Add banner" from the page header opened with no placement selected; list pages flashed a skeleton after every save; initials of "Home & Kitchen" were "H&"; the "+" icon of empty-state buttons floated above the label; "Cod pending" now reads "COD pending".


## Unreleased – neutral, multi-client base

The project was cleaned up from a single-shop clone into a neutral, reusable e-commerce base (one deployment per client).

### Removed
- **Unused tables**: memberships, returns, OTP codes, referrals (and `users.referral_code` / `referred_by_user_id`), events, shipping rules, search terms, enquiries – 14 tables. Kept for the next phase: saved-item lists, coupons, customer feedback / Q&A, CMS, notifications, refunds, invoices, webhook events, shipment items/events.
- **Seller onboarding**: seller organisations, vendor types, registration types and countries (tables, models, services, controllers, requests, routes, admin pages, seeders, tests, docs). Products and order lines no longer reference sellers.
- **Sales channels** (standard / express): `sales_channels`, `product_channels`, `variant_channel_stock`, the channel middleware and query parameter, channel filters and switches in the admin panel.
- **Third category level** (sub-sub-categories): `sub_categories.parent_id`, the `children` API field, nested admin UI and queries. Existing products of a sub-sub-category move up to its parent sub-category.
- **Market-specific fields**: tax-number validation, tax-code columns, a professional customer profile (type, speciality, council number, verification status), the professional-only product flag, address type "clinic", regional defaults.
- **Original-shop data and look**: its categories, demo products with hot-linked images, the in-house seller, demo orders, the old order prefix, the old admin look, old Postman collections and docs.

### Added
- **One baseline migration**: all earlier migrations were squashed into `0001_01_01_000000_create_baseline_schema.php` for fresh installs (verified identical to the previous schema: 66 tables, columns, defaults, indexes and foreign keys). Existing development databases must be rebuilt with `migrate:fresh`.
- **Settings**: cached key/value `settings` table, one `Settings` service, admin **Settings** page (generic, built from the definitions), public `GET /store/settings`. Store name, logo, contact, currency (code, symbol, decimals), tax (rate, inclusive/exclusive, label), order number prefix, delivery, minimum order and COD limit are settings ([docs/SETTINGS.md](docs/SETTINGS.md)).
- **Optional manufacturer labels** (a setting, default on) – nullable on products; managed on a top-level admin page.
- **Optional loyalty points** (a setting, default off) with earn rate and redeem rate; all points logic is behind one ledger class.
- **Tax**: per-product `tax_rate` (null = store default), prices tax-inclusive or tax-exclusive.
- Stock now lives on the product option (`stock_qty`, `reserved_qty`, `low_stock_threshold`).
- `DemoSeeder` (optional, neutral sample data); `DatabaseSeeder` now seeds only default settings and the first admin.
- Docs: README, architecture, database, features, settings reference, storefront API, **How to create a new client project**; neutral Postman collection.

### Changed
- Database renamed to `jhkstore_db` (tests: `jhkstore_test`).
- API changes a storefront must know: `GET /store/settings` replaces `GET /store/settings/delivery`; no `?channel=`; cart/order summaries use `tax_total` / `tax_inclusive` / `tax_label` instead of `tax_included`; address field `tax_number` replaces the old tax-number field; product `tax_rate` replaces the old rate field; variant `stock` is a number; points fields exist only while loyalty points are on.
- Admin panel: seller organisations, channels and sub-sub-categories gone; new manufacturer-label and generic Settings pages; store name/logo/currency come from the settings.
- Tests: 231 → 210 (seller, channel and sub-sub-category tests removed; settings, label-toggle, points-toggle, tax and seeder tests added). All pass.

