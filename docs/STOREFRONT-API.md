# Storefront API (for whoever builds the customer site)

Base URL: `{APP_URL}/api/v1/store`. JSON in, JSON out – always send `Accept: application/json`. List endpoints answer `{ data, links, meta }`; single items `{ data }`.
Errors: `422 { message, errors:{field:[…]} }`; checkout errors also carry a stable `code` (e.g. `cart_invalid`, `below_min_order`, `cod_not_allowed`, `price_changed`, `store_closed`, `coupon_invalid`, `delivery_not_available`, `payment_method_unavailable`).
The OpenAPI reference is in [openapi.json](openapi.json) (and generated live at `/docs/api`); a Postman collection is in `docs/postman/`.

**There are no customer accounts.** Nothing in this API needs a login, a token or a cookie: customers never register or sign in. The storefront keeps the cart (variant ids and quantities) itself, for example in `localStorage`, **or** uses the persistent server cart (section 5, a random token instead of a login), asks the server to price it, and sends the whole order in one request.

## 1. Start with the settings

`GET /settings` (public) – call it once on start-up so one storefront build adapts to every client:

```json
{ "store": {"name","tagline","logo_url","favicon_url","email","phone","address","country","open","closed_message"},
  "checkout": {"notice","eta_text"},
  "social_links": [{"platform","label","url"}],
  "footer": {"text","copyright","support_hours","whatsapp_number","whatsapp_url","agency":{"name","url"}},
  "seo": {"meta_title","meta_description","share_image_url"},
  "currency": {"code","symbol","decimals"},
  "tax": {"rate","inclusive","label"},
  "delivery": {"method","fee","free_delivery_min_cart","days"},
  "orders": {"min_order_amount","cod_max_amount"},
  "payment": {"cod":{"enabled"}, "bank_transfer":{"enabled","instructions","accounts":[{"bank","title","number","iban"}]}, "card":{"visible","coming_soon":true}},
  "theme": {"primary","primary_contrast","accent","bg","surface","text","muted","border","font_heading","font_body","radius","shadow"} }
```

`logo_url`, `favicon_url` and `seo.share_image_url` are absolute URLs or `null`. `social_links` holds only the **active** links, **already in the order the store owner set** (render them as they come);
`platform` is one of `facebook, instagram, linkedin, x, youtube, tiktok, whatsapp, pinterest, website, custom` (pick an icon from it), `label` is never empty. `footer.whatsapp_url` is `https://wa.me/<digits>` or `null`.
`footer.agency` is the agency credit (`name` and an `https://` `url`, both empty = show nothing).
The `seo` values are site-wide defaults for pages that have no title / description of their own (products and categories carry their own `meta_title` / `meta_description`).
Prices in all responses are plain numbers in the store currency.

* **Open / closed**: `store.open` = `false` means customers can still browse and get a quote, but `POST /orders` answers `422 { code: "store_closed", message }` (the message is `store.closed_message`, or a default). Show `store.closed_message` instead of the checkout button. `checkout.notice` is a short notice for the checkout page; `checkout.eta_text` (e.g. "2 to 4 days") is the delivery estimate to show when the quote has no zone estimate.
* **Delivery**: `delivery.method` is `flat`, `free`, `free_above`, `zones` or `owner_confirms` (see the quote below); `fee`, `free_delivery_min_cart` (0 = never free) and `days` are the store values.
* **Payment**: offer a method only when it is `enabled`; `accounts` are the active bank accounts in display order, `instructions` the text to show next to them. `card.visible` only means "show a *card payment, coming soon* label": card can never be chosen (`payment_method: "card"` is a `422`).
* **Theme**: paint the storefront with these values. `primary_contrast` is the text colour on the primary colour. Colours are `#RRGGBB`; `font_heading` / `font_body` is one of `Inter, Poppins, Playfair Display, Plus Jakarta Sans, Roboto, Lato, system-ui` (load the font yourself, no font is served); `radius` one of `0px, 4px, 8px, 12px, 16px, 999px`; `shadow` one of `none, soft, medium`.

### Banners

`GET /banners?placement=home_hero` (public) – the banners to show **right now** for one spot of the page: active, inside their start / end dates, **in the order set in the admin panel**.
Placements: `home_hero` (top of the home page), `home_secondary` (between sections), `promo_strip` (thin bar). Without `placement` every live banner is returned (each carries its `placement`); an unknown placement is a `422`.

```json
{ "data": [ { "id", "placement", "title", "subtitle", "button_text", "link_url", "image_url", "mobile_image_url" } ] }
```

A spot can hold **one banner or many**: the API always answers a list and **the storefront decides** – render a single banner when `data` has one entry, a slider when it has several, nothing when it is empty.
`image_url` is the desktop picture and `mobile_image_url` the optional phone picture (`null` = use the desktop one); both are absolute URLs. `link_url` is an `http(s)` address or a path of your store such as `/category/phones` (or `null`); `title`, `subtitle` and `button_text` may be `null`.

## 2. Catalogue

| Call | Notes |
|---|---|
| `GET /categories` (`?top=1`) | tree: `data[]` category `{id,name,slug,description,is_top,icon_url,image_url,sub_categories:[{id,name,slug,icon_url,image_url}]}`. Only active items, already ordered, cached 10 min. **Two levels** |
| `GET /categories/{slug}` | one category (404 when inactive) |
| `GET /products` | filters `tag`, `category`, `sub_category` (slugs; add `category` to disambiguate), `q`, `min_price`, `max_price`, `in_stock=1`, `sort` (`popularity`,`newest`,`price_asc`,`price_desc`,`discount`), `page`, `per_page` ≤ 100. Home page sections = the list with a tag, e.g. `?tag=best-seller&per_page=10` |
| `GET /products/{slug}` | detail: options (`variants[]` with price, stock `qty`, `low_stock`, bulk `price_tiers`, own pictures), images, content tabs, `delivery.arrives_by`, related products |
| `GET /tags` | active tags |

A product is the parent of its **options**; only an option (`variant_id`) can be ordered. A product with one option is shown as that option (`has_single_option`).
Card/detail fields such as `badge_label`, `show_starting_at` and `display_mode` are hints for the layout; images are absolute URLs or `null`.

**Discounted prices.** When an automatic discount is live, cards and options show it: `price` is what one unit costs after a **percent** discount, `compare_price` the price to show struck through (the MRP, or the price before the discount; `null` when there is nothing to compare), `discount_label` e.g. `"25% off"` (or `null`). A **fixed-amount** discount (e.g. "$10 off") is taken off the order, not off a unit, so it only gives `discount_label`; `price` stays the option price and the saving appears in the quote. On a product page `variants[].option_price` is the option's own price before any discount. `mrp` stays the MRP. Bulk prices are not part of these fields (see `price_tiers` and the quote). The `min_price` / `max_price` filters and the `price_*` sorts use the option prices, not the discounted ones.

## 3. Price the cart: `POST /checkout/quote`

```json
POST /checkout/quote
{ "items": [ { "variant_id": 12, "qty": 5 }, { "variant_id": 40, "qty": 1 } ],
  "coupon_code": "SAVE10",                                  // optional
  "address": { "city": "Springfield", "area": "Downtown", "postcode": "12345" },   // optional: what delivery zones match on
  "phone": "+1 555 010 0100" }                              // optional: lets the per-customer and first-order coupon rules be checked
```

Send `cart_token` (the persistent cart, section 5) **instead of** `items`; the quote then uses the cart's items, coupon and saved phone unless the request has its own `coupon_code` / `phone`. If both `items` and `cart_token` are sent, `items` win. An unknown or expired `cart_token` prices an empty cart (`can_checkout: false`).

The server prices the lines from live data (option price, bulk prices, discounts, coupon, tax, delivery, minimum order) and answers `{ data }`:

* `items[]` – per line: `price`, `unit_price` (the bulk price when reached), `bulk_applied`, `next_offer` (hint: "add 5 more to pay 140 each"), `line_total` (what is paid for the line, after discounts), `saving`, `discount_amount` (what a discount or the coupon takes off this line), `discount_source` (`discount`, `coupon`, `bulk` when only a bulk price lowers the line, or `null`), `discount_id` / `coupon_id`, `available_qty`, `can_buy`, `in_totals`, `warnings[]` (`out_of_stock`, `insufficient_stock`, `low_stock`, `below_min_qty`, `above_max_qty`, `unavailable`; `blocking: true` ones stop the order).
* `summary` – `items_total`, `product_discount` (MRP → option price), `bulk_discount`, `discount_total` (automatic discounts only), `coupon_code` (the coupon that was applied, or `null`), `coupon_discount`, `coupon_free_delivery`, `coupon_error { code, message }` or `null`, `subtotal`, `delivery_method`, `delivery_fee` (a number, or **`null` while it is not known**), `delivery_pending`, `delivery_needs_address`, `delivery_zone { id, name, eta_text }` or `null`, `delivery_eta_text`, `delivery_free`, `free_delivery_min_cart`, `amount_for_free_delivery`, `grand_total`, `total_savings` (product + bulk + discounts + coupon), `tax_total`, `tax_inclusive`, `tax_label`, `min_order_amount`.
* `can_checkout` and `issues[]` (blocking problems, including `below_min_order` and `delivery_not_available`).

**How the price is built**, per line and in this order: the option price (or the bulk price when the quantity reaches it) → the best live **automatic discount** (the biggest saving on that line; on a tie the higher `priority`; discounts are never stacked) → the optional **coupon** (for each eligible line the better of discount and coupon applies, never both) → tax on what is paid → delivery. A fixed-amount discount or coupon is taken off the eligible lines **once** (not per unit), split in proportion to the line totals in whole cents, the remainder going to the last lines; a line never costs less than 0. `min_order` of a coupon and the store's minimum order are checked **after bulk prices and automatic discounts, before the coupon**; "free delivery above" uses the final subtotal.

**Coupon refusals do not block the quote.** A coupon that cannot be used leaves the prices as if it had not been sent and is explained in `summary.coupon_error.code`: `not_found`, `inactive`, `expired`, `not_started`, `min_order`, `usage_limit`, `per_customer_limit`, `first_order_only`, `no_eligible_items` (nothing in the cart matches the coupon's scope, or the items already have a better automatic offer). The per-customer and first-order rules need the customer's phone: a quote without `phone` skips them (the order always checks them). Codes are not case-sensitive.

**Delivery** follows the store's `delivery.method`:

| Method | Fee |
|---|---|
| `flat` | `delivery_fee` on every order |
| `free` | always 0 (`delivery_free: true`) |
| `free_above` | `delivery_fee`, free when the items total after discounts reaches `free_delivery_min_cart` (0 = never free); `amount_for_free_delivery` tells how much is missing |
| `zones` | the fee of the first active zone that matches the `address` (`city` / `area` equal ignoring case, `postcode` exact or prefix `54*`); free above the zone's own amount; the zone's minimum order replaces the store's; `delivery_zone` and its ETA are returned. **Without an address** the quote answers `delivery_fee: null` and `delivery_needs_address: true`. An address that matches no zone: `delivery_not_available` issue (default), or `delivery_pending: true`, or the flat fee, as the store chose |
| `owner_confirms` | `delivery_fee: null`, `delivery_pending: true`: the order is placed without a fee and the shop confirms it later |

An unknown fee is **not** part of `grand_total`. A free-delivery coupon makes the fee 0 in every method.

Nothing is stored or reserved. Prices sent by the client are ignored. Limits: at most `SHOP_MAX_ITEMS_PER_ORDER` (30) lines, each quantity at most `SHOP_MAX_QTY_PER_LINE` (500) and at most the product's own `max_order_qty`; the same option listed twice is a `422` (send one line with the total).

## 4. Check a coupon: `POST /coupons/validate`

```json
POST /coupons/validate
{ "code": "save10", "items": [ { "variant_id": 12, "qty": 1 } ], "address": { "city": "Springfield" }, "phone": "+1 555 010 0100" }
```

Same body as the quote plus `code` (or `cart_token` instead of `items`). `200 { data }` is **the quote with the coupon applied** (same engine, `summary.coupon_code` and `coupon_discount`). A coupon that does not work answers `422 { code: "coupon_invalid", message, coupon_error: { code, message }, errors: { code: [message] } }` with the reasons listed above. Limited to 30 a minute per IP (a code cannot be guessed quickly); the honeypot field is accepted.

## 5. The persistent cart: `/cart`

Optional. Customers never log in, so the cart belongs to a random token the browser keeps (for example in `localStorage`) and sends in the header **`X-Cart-Token`**. Without the header, or with an unknown, malformed or expired token, a **new empty cart is created**; its token comes back in `data.token` and in the `X-Cart-Token` response header. Keep that token.

| Call | What it does |
|---|---|
| `GET /cart` (optional `?city=&area=&postcode=` for the fee of a delivery zone) | the priced cart |
| `POST /cart/items { variant_id, qty }` | adds `qty` to the option (a new line, or on top of the existing quantity) |
| `PUT /cart/items/{variantId} { qty }` | sets the quantity of a line (`404 not_in_cart` when the option is not in the cart) |
| `DELETE /cart/items/{variantId}` | removes a line |
| `DELETE /cart` | empties the cart (items and coupon; the contact stays) |
| `PUT /cart/contact { name?, phone?, email? }` | saves the contact (phone normalised, e-mail lower-cased) so the shop can follow up; only the keys that are sent change |
| `PUT /cart/coupon { code }` | applies a coupon; a code that does not work answers `422 coupon_invalid` (as in section 4) and is **not** saved |
| `DELETE /cart/coupon` | removes the coupon |

Every answer is the priced cart: **the same shape as the quote** (`items[]`, `summary`, `can_checkout`, `issues[]`) plus `data.token`, `data.contact { name, phone, email }` and `data.expires_at`. Prices and stock are checked **again on every read**; a line whose option was switched off, sold out or deleted **stays in the cart** with a blocking warning (`unavailable`, `out_of_stock`, `insufficient_stock`) – it is never removed silently, the shopper decides. The cart's coupon is checked again on every read (`summary.coupon_error`).

Limits: at most `SHOP_MAX_ITEMS_PER_ORDER` lines, `SHOP_MAX_QTY_PER_LINE` per line (`422 cart_limit`, or a validation error on `qty`); only an active option of a visible product can be added (`422 unavailable`). A cart expires **30 days after its last activity** (every call renews it; the sliding window); expired carts are deleted by `php artisan shop:prune-carts` (daily). **60 calls a minute per IP**, reads included (a read without a token creates a cart).

Use it by sending **`cart_token`** instead of `items` to `POST /checkout/quote`, `POST /coupons/validate` and `POST /orders`. On a successful order the cart is emptied in the same transaction; a failed order leaves it as it was.

## 6. Place the order: `POST /orders`

```json
POST /orders          Idempotency-Key: 8-64 characters (recommended)
{
  "customer": { "name": "Alex Morgan", "phone": "+1 555 010 0100", "email": "alex@example.com" },   // phone required, email optional
  "address":  { "line1": "12 Example Street", "city": "Springfield",                                  // line1 and city required
                "area": "Downtown", "landmark": "", "state": "", "postcode": "12345", "country": "Testland",
                "name": "", "phone": "" },                                                            // name and phone default to the customer's
  "billing_address": { … },                                                                          // optional, defaults to the delivery address
  "items": [ { "variant_id": 12, "qty": 5 } ],                                                       // or "cart_token": "<40 characters>" instead
  "payment_method": "cod",                                                                           // optional: "cod" (default) or "bank_transfer"
  "coupon_code": "SAVE10",                                                                           // optional (a cart's coupon is used when the body has none)
  "customer_note": "Call before delivery",                                                           // optional, 500 characters
  "expected_total": 1060.00                                                                          // optional: the grand_total the customer saw
}
```

* `201` = a new order, `200` + `Idempotency-Replayed: true` = the same `Idempotency-Key` (from the same phone) was used before: the earlier order is returned and nothing is created twice (also while the store is closed). Send a key (for example a UUID generated when the checkout page opens) so a double tap or a retry is safe.
* `409 price_changed` (`expected_total`, `current_total`) – the total the customer saw is no longer right; show the new total (call the quote again) and let them confirm.
* `422 cart_invalid` with `lines[]` (`variant_id`, `code`, `message`) – out of stock, not enough stock, quantity limits, option no longer sold, **or an empty / unknown / expired cart** (`cart_token`); `422 below_min_order`, `422 cod_not_allowed` (cash on delivery over the limit, or a product that cannot be paid on delivery – bank transfer has neither).
* `422 store_closed` – the store is closed (`message` = the store's closed message).
* `422 payment_method_unavailable` – the chosen method is switched off in the store. `payment_method: "card"` (or anything but `cod` / `bank_transfer`) is a validation error.
* `422 coupon_invalid` with `reason` and `coupon_error { code, message }` – the coupon is refused (reasons as in the quote). **Unlike the quote, a bad coupon blocks the order.** The coupon use is counted under a database lock: of two simultaneous orders for the last use, one wins and the other gets `usage_limit`. A coupon that takes nothing off and is not free-delivery is not counted as used.
* `422 delivery_not_available` – the store delivers by zones and the address is outside every zone.
* The **customer is found or created by normalised phone** (`+49 170 1234567`, `+49-170-1234567` and `0049 170 1234567` are the same person; a number typed without the country code is another number, unless the store has set a default phone country code: then `0170 1234567` is the same person as `+49 170 1234567`). A changed name or a new e-mail updates the contact; an order without e-mail never wipes a stored one. The order keeps its own snapshot of the contact and the addresses.
* The answer is the order: `order_number` (`PREFIX-2026-000123`), `status`, `payment_method`, `payment_status`, `payment {…}` (see section 7), `totals` (`items_total`, `product_discount`, `bulk_discount`, `discount_total`, `coupon_code`, `coupon_discount`, `subtotal`, `delivery_fee` – **`null` when it is still to be confirmed** –, `delivery_fee_pending`, `grand_total`, tax fields, `total_savings`), `items[]` (snapshots, with `discount_amount` and `discount_source`), `shipping_address`, `billing_address`, `timeline[]`, `customer`. Stock is deducted at once.
* An order placed while the delivery fee is unknown has `delivery_fee_pending: true` and a `grand_total` without delivery. Show "delivery fee to be confirmed"; the shop sets the fee and the tracking answer then shows the final total.
* Hidden-field **honeypot**: add an input named `website` (`SHOP_HONEYPOT_FIELD`) to the checkout form, hide it with CSS and leave it empty. A request that fills it is refused with `422 { "code": "rejected" }`. API clients that do not send the field are unaffected. No captcha.

## 7. Bank transfer: the payment proof

With `payment_method: "bank_transfer"` the order starts `payment_status: "awaiting_payment"` and `payment.proof_status: "none"`. Show the customer the accounts and instructions (`payment.accounts`, `payment.instructions` in the order answer, or `payment.bank_transfer` of the settings), tell them to use the order number as the reference, and let them upload a screenshot of the transfer:

```
POST /orders/{orderNumber}/payment-proof?phone=+15550100100        multipart/form-data
  proof      the picture: JPG, PNG or WEBP, at most 4 MB, between 100 and 6000 pixels wide and high (SVG and PDF are refused)
  reference  optional text, at most 120 characters (the bank's transaction id)
```

`200 { data }` is the order (as in the tracking answer) with `payment.proof_status: "submitted"`. The picture is stored on a **private** disk: it has no public URL and the answers never name the file; only the shop's admin can open it. A wrong or missing `phone` answers the **same `404`** as an unknown order, whatever the file is, so order numbers cannot be probed.

Rules: only a **pending** order takes a proof (`422 order_closed` once it is confirmed, cancelled or rejected); one proof at a time (`422 proof_already_submitted` while it is being checked); `422 payment_already_verified` after verification; `422 not_bank_transfer` for cash on delivery orders. The shop either **verifies** it (`payment.status` and `payment_status` become `paid`, then the order is confirmed) or **rejects** it with a reason: `payment.proof_status` becomes `rejected`, `payment.reject_reason` says why, `payment.can_upload_proof` is `true` and the customer uploads a new picture (the old one is replaced). An order cannot be confirmed before the payment is verified. A bank order that is cancelled after verification stays `paid`: the shop refunds it by hand.

Limits: 10 uploads a minute per IP and 10 an hour per order number; the honeypot field is accepted. The PHP `upload_max_filesize` / `post_max_size` of the server must allow 4 MB.

## 8. Look an order up: `GET /orders/{orderNumber}?phone=…`

Order status, items, totals, tracking (courier, AWB, link) and the timeline, for the person who placed it. The `phone` must be the phone number of the order (any way of typing it). **A wrong phone answers `404`, exactly like an order that does not exist** (and a missing phone `422`), so order numbers cannot be probed. There is no customer order list, no cancel and no reorder: the customer calls the shop; the admin changes the status.

Besides the order fields of section 6 the answer has:

```json
{ "status": "pending",                       // pending, confirmed, packed, shipped, delivered, cancelled, rejected
  "reject_reason": null,                     // set when the shop rejected the order (status "rejected"): show it
  "cancel_reason": null,
  "delivery_fee_pending": false,             // true = show "delivery fee to be confirmed"
  "payment": { "method": "bank_transfer", "method_label": "Bank transfer", "status": "awaiting_payment",
               "proof_status": "none",       // none | submitted | verified | rejected (bank transfer)
               "reject_reason": null,        // why the last proof was rejected
               "can_upload_proof": true,
               "instructions": "…", "accounts": [ { "bank", "title", "number", "iban" } ] },   // instructions and accounts only for an unpaid, open bank transfer order
  "store_phone": "+1 555 000 1000",
  "whatsapp_url": "https://wa.me/15550002000" }   // null when the store has no WhatsApp number
```

`payment_method` and `payment_status` stay as flat fields as well. `payment_status` is `cod_pending`, `awaiting_payment`, `paid` or `cancelled`. The timeline also records payment events ("Payment proof uploaded", "Payment verified", "Payment proof rejected: …", "Delivery fee confirmed: 12.50") and a rejection ("Rejected: …"). No tokens in URLs, no tracking pixels.

## Rate limits

Because nobody logs in, the public endpoints are protected by limits (`429` with a `Retry-After` header when exceeded; all can be changed per client in `.env`):

| Endpoint | Limit |
|---|---|
| public reads (settings, banners, categories, products, tags) | 240 / minute per IP (`PUBLIC_PER_MINUTE`) |
| `POST /checkout/quote` | 60 / minute per IP (`QUOTE_PER_MINUTE`) |
| `POST /coupons/validate` | 30 / minute per IP (`COUPON_PER_MINUTE`) |
| every `/cart` call | 60 / minute per IP (`CART_PER_MINUTE`) |
| `POST /orders` | 10 / minute and 60 / hour per IP, and 10 / hour per phone number (`CHECKOUT_PER_MINUTE`, `CHECKOUT_PER_HOUR`, `CHECKOUT_PER_PHONE_HOUR`) |
| `POST /orders/{orderNumber}/payment-proof` | 10 / minute per IP and 10 / hour per order number (`PAYMENT_PROOF_PER_MINUTE`, `PAYMENT_PROOF_PER_ORDER_HOUR`) |
| `GET /orders/{orderNumber}` | 20 / minute per IP and 15 / hour per order number (`ORDER_LOOKUP_PER_MINUTE`, `ORDER_LOOKUP_PER_ORDER_HOUR`) |

Allow the storefront origin in `FRONTEND_URLS` (CORS). Calls from a browser need no credentials; the cart token travels in the `X-Cart-Token` request header (any request header is allowed by CORS). From another origin a browser can only read the response headers listed in `exposed_headers` (`Idempotency-Replayed`, `X-Cart-Token`), so the token can be read from `data.token` or from the `X-Cart-Token` response header.
