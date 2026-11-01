# Storefront API (for whoever builds the customer site)

Base URL: `{APP_URL}/api/v1/store`. JSON in, JSON out – always send `Accept: application/json`. List endpoints answer `{ data, links, meta }`; single items `{ data }`.
Errors: `422 { message, errors:{field:[…]} }`; cart/checkout errors also carry a stable `code` (e.g. `insufficient_stock`, `below_min_qty`, `cart_empty`, `price_changed`).
The OpenAPI reference is generated at `/docs/api`; a Postman collection is in `docs/postman/`.

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
  "orders": {"min_order_amount","cod_max_amount"},
  "features": {"rewards": false},
  "rewards": null }
```

`features.rewards` tells you whether to build the points UI. When rewards are off, no points fields appear anywhere in the API.
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
| `GET /products` | filters `tag`, `category`, `sub_category` (slugs; add `category` to disambiguate), `q`, `min_price`, `max_price`, `in_stock=1`, `rating`, `sort` (`popularity`,`newest`,`price_asc`,`price_desc`,`discount`,`rating`), `page`, `per_page` ≤ 100. Home page sections = the list with a tag, e.g. `?tag=best-seller&per_page=10` |
| `GET /products/{slug}` | detail: options (`variants[]` with price, stock `qty`, `low_stock`, bulk `price_tiers`, own pictures), images, content tabs, `delivery.arrives_by`, related products |
| `GET /tags` | active tags |
| `GET /products/{slug}/reviews`, `POST …/reviews` | list + summary; posting needs a customer token (one review per customer; posting again updates it) |

A product is the parent of its **options**; only an option (`variant_id`) goes into the cart. A product with one option is shown as that option (`has_single_option`).
Card/detail fields such as `badge_label`, `show_starting_at` and `display_mode` are hints for the layout; images are absolute URLs or `null`.

## 3. Customers

`POST /auth/register`, `POST /auth/login` (email **or** mobile in `login`) → `{ token, user, cart_lines_merged }`; send `Authorization: Bearer <token>`. `GET /auth/me`, `POST /auth/logout`.
Address book: `GET|POST /addresses`, `GET|PUT|DELETE /addresses/{id}`, `POST /addresses/{id}/default` (fields: name, phone, alt_phone, line1, area, landmark, city, state, postcode, country, tax_number, type `home|work|other`, latitude, longitude).

## 4. Cart (guest or customer)

* A guest has no token at first: the first `POST /cart/items` answer contains `data.token` (also the `X-Cart-Token` response header). Send it back as the `X-Cart-Token` request header. Logging in or registering with that header merges the guest cart.
* `GET /cart`, `POST /cart/items {variant_id, qty}`, `PUT /cart/items/{variantId} {qty}`, `DELETE /cart/items/{variantId}`, `DELETE /cart`. Every answer is the whole cart, recalculated by the server:
  `items[]` (price, bulk price in use, next bulk offer hint, `warnings[]`), `summary` (`items_total`, `product_discount`, `bulk_discount`, `subtotal`, `delivery_fee`, `grand_total`, `tax_total`, `tax_inclusive`, `tax_label`, `total_savings`, …), `can_checkout`, `issues[]`.
* With reward points on: `PUT /cart/coins {coins_to_redeem}` (customer token) and the `summary.coins` block (requested / applied / wallet balance / limit that applied).

## 5. Checkout and orders (customer token)

* `POST /orders` `{ address_id | address{…}, billing_address_id?, customer_note?, expected_total?, coins_to_redeem? }` – header `Idempotency-Key` (8–64 chars) makes a retry return the same order (`200` + `Idempotency-Replayed: true`; `201` = new). `expected_total` makes the server answer `409 price_changed` when the price moved.
* `GET /orders`, `GET /orders/{orderNumber}` (totals, items, addresses, timeline, shipment), `POST /orders/{orderNumber}/cancel` (before it ships), `POST /orders/{orderNumber}/reorder`.
* Only cash on delivery exists today (`payment_method = cod`).

## Rate limits

login 8/min · registration 10/hour · cart 120/min · checkout/cancel/reorder 20/min · addresses and order history 60/min (`429` when exceeded).
