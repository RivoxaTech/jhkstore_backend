# Storefront API (for whoever builds the customer site)

Base URL: `{APP_URL}/api/v1/store`. JSON in, JSON out – always send `Accept: application/json`. List endpoints answer `{ data, links, meta }`; single items `{ data }`.
Errors: `422 { message, errors:{field:[…]} }`; checkout errors also carry a stable `code` (e.g. `cart_invalid`, `below_min_order`, `cod_not_allowed`, `price_changed`).
The OpenAPI reference is in [openapi.json](openapi.json) (and generated live at `/docs/api`); a Postman collection is in `docs/postman/`.

**There are no customer accounts.** Nothing in this API needs a login, a token or a cookie: customers never register or sign in. The storefront keeps the cart (variant ids and quantities) itself, for example in `localStorage`, asks the server to price it, and sends the whole order in one request.

## 1. Start with the settings

`GET /settings` (public) – call it once on start-up so one storefront build adapts to every client:

```json
{ "store": {"name","tagline","logo_url","favicon_url","email","phone","address","country"},
  "social_links": [{"platform","label","url"}],
  "footer": {"text","copyright","support_hours","whatsapp_number","whatsapp_url"},
  "seo": {"meta_title","meta_description","share_image_url"},
  "currency": {"code","symbol","decimals"},
  "tax": {"rate","inclusive","label"},
  "delivery": {"fee","free_delivery_min_cart","days"},
  "orders": {"min_order_amount","cod_max_amount"} }
```

`logo_url`, `favicon_url` and `seo.share_image_url` are absolute URLs or `null`. `social_links` holds only the **active** links, **already in the order the store owner set** (render them as they come);
`platform` is one of `facebook, instagram, linkedin, x, youtube, tiktok, whatsapp, pinterest, website, custom` (pick an icon from it), `label` is never empty. `footer.whatsapp_url` is `https://wa.me/<digits>` or `null`.
The `seo` values are site-wide defaults for pages that have no title / description of their own (products and categories carry their own `meta_title` / `meta_description`).
Prices in all responses are plain numbers in the store currency.

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

## 3. Price the cart: `POST /checkout/quote`

```json
POST /checkout/quote
{ "items": [ { "variant_id": 12, "qty": 5 }, { "variant_id": 40, "qty": 1 } ] }
```

The server prices the lines from live data (option price, bulk prices, tax, delivery, minimum order) and answers `{ data }`:

* `items[]` – per line: `price`, `unit_price` (the bulk price when reached), `bulk_applied`, `next_offer` (hint: "add 5 more to pay 140 each"), `line_total`, `saving`, `available_qty`, `can_buy`, `in_totals`, `warnings[]` (`out_of_stock`, `insufficient_stock`, `low_stock`, `below_min_qty`, `above_max_qty`, `unavailable`; `blocking: true` ones stop the order).
* `summary` – `items_total`, `product_discount`, `bulk_discount`, `subtotal`, `delivery_fee`, `delivery_free`, `amount_for_free_delivery`, `grand_total`, `total_savings`, `tax_total`, `tax_inclusive`, `tax_label`, `min_order_amount`.
* `can_checkout` and `issues[]` (blocking problems, including `below_min_order`).

Nothing is stored or reserved. Prices sent by the client are ignored. Limits: at most `SHOP_MAX_ITEMS_PER_ORDER` (30) lines, each quantity at most `SHOP_MAX_QTY_PER_LINE` (500) and at most the product's own `max_order_qty`; the same option listed twice is a `422` (send one line with the total).

## 4. Place the order: `POST /orders`

```json
POST /orders          Idempotency-Key: 8-64 characters (recommended)
{
  "customer": { "name": "Alex Morgan", "phone": "+1 555 010 0100", "email": "alex@example.com" },   // phone required, email optional
  "address":  { "line1": "12 Example Street", "city": "Springfield",                                  // line1 and city required
                "area": "Downtown", "landmark": "", "state": "", "postcode": "12345", "country": "Testland",
                "name": "", "phone": "" },                                                            // name and phone default to the customer's
  "billing_address": { … },                                                                          // optional, defaults to the delivery address
  "items": [ { "variant_id": 12, "qty": 5 } ],
  "customer_note": "Call before delivery",                                                           // optional, 500 characters
  "expected_total": 1060.00                                                                          // optional: the grand_total the customer saw
}
```

* `201` = a new order, `200` + `Idempotency-Replayed: true` = the same `Idempotency-Key` (from the same phone) was used before: the earlier order is returned and nothing is created twice. Send a key (for example a UUID generated when the checkout page opens) so a double tap or a retry is safe.
* `409 price_changed` (`expected_total`, `current_total`) – the total the customer saw is no longer right; show the new total (call the quote again) and let them confirm.
* `422 cart_invalid` with `lines[]` (`variant_id`, `code`, `message`) – out of stock, not enough stock, quantity limits, option no longer sold; `422 below_min_order`, `422 cod_not_allowed`.
* The **customer is found or created by normalised phone** (`+49 170 1234567`, `+49-170-1234567` and `0049 170 1234567` are the same person; a number typed without the country code is another number, unless the store has set a default phone country code: then `0170 1234567` is the same person as `+49 170 1234567`). A changed name or a new e-mail updates the contact; an order without e-mail never wipes a stored one. The order keeps its own snapshot of the contact and the addresses.
* The answer is the order: `order_number` (`PREFIX-2026-000123`), `status`, `payment_method` (`cod` – cash on delivery is the only method today), `totals`, `items[]` (snapshots), `shipping_address`, `billing_address`, `timeline[]`, `customer`. Stock is deducted at once.
* Hidden-field **honeypot**: add an input named `website` (`SHOP_HONEYPOT_FIELD`) to the checkout form, hide it with CSS and leave it empty. A request that fills it is refused with `422 { "code": "rejected" }`. API clients that do not send the field are unaffected. No captcha.

## 5. Look an order up: `GET /orders/{orderNumber}?phone=…`

Order status, items, totals, tracking (courier, AWB, link) and the timeline, for the person who placed it. The `phone` must be the phone number of the order (any way of typing it). **A wrong phone answers `404`, exactly like an order that does not exist** (and a missing phone `422`), so order numbers cannot be probed. There is no customer order list, no cancel and no reorder: the customer calls the shop; the admin changes the status.

## Rate limits

Because nobody logs in, the public endpoints are protected by limits (`429` with a `Retry-After` header when exceeded; all can be changed per client in `.env`):

| Endpoint | Limit |
|---|---|
| public reads (settings, banners, categories, products, tags) | 240 / minute per IP (`PUBLIC_PER_MINUTE`) |
| `POST /checkout/quote` | 60 / minute per IP (`QUOTE_PER_MINUTE`) |
| `POST /orders` | 10 / minute and 60 / hour per IP, and 10 / hour per phone number (`CHECKOUT_PER_MINUTE`, `CHECKOUT_PER_HOUR`, `CHECKOUT_PER_PHONE_HOUR`) |
| `GET /orders/{orderNumber}` | 20 / minute per IP and 15 / hour per order number (`ORDER_LOOKUP_PER_MINUTE`, `ORDER_LOOKUP_PER_ORDER_HOUR`) |

Allow the storefront origin in `FRONTEND_URLS` (CORS). Calls from a browser need no credentials.
