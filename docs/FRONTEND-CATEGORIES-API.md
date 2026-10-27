# Categories API (for the frontend developer)

Base URL: `http://localhost:8000/api/v1` (Postman: `docs/postman/Category-Display.postman_collection.json`, just press Send; variable `base_url`).
Public, read-only, no login. Send `Accept: application/json`. Responses are cached for 10 minutes on the server, and the cache is
cleared the moment an admin changes anything, so the site is always up to date.

## The structure

```
Category            (top level, e.g. "Orthodontics")        icon_url, image_url
 └─ sub_categories  (e.g. "Brackets")                       icon_url, image_url
     └─ children    (e.g. "Metal Brackets") - only SOME     icon_url, image_url
                    sub-categories have children
```

* A sub-category with `children: []` has no dropdown: clicking it goes straight to its products.
* A sub-category with children shows a dropdown of them; clicking a child goes to its products.
* Only active items are returned, already sorted. Do not sort or filter again.
* `icon_url` / `image_url` are absolute URLs, or `null` when no picture was uploaded (show a placeholder / the first letters).
* `slug` is the key for links, e.g. `/category/orthodontics`. (The products-by-slug API comes with the Products module.)

## 1. All categories (category page, menu)

`GET /store/categories`

```json
{
  "data": [
    {
      "id": 2,
      "name": "Orthodontics",
      "slug": "orthodontics",
      "description": null,
      "is_top": true,
      "icon_url": "http://localhost:8000/storage/12/icon.png",
      "image_url": null,
      "sub_categories": [
        {
          "id": 13,
          "name": "Brackets",
          "slug": "brackets",
          "icon_url": null,
          "image_url": null,
          "children": [
            { "id": 14, "name": "Metal Brackets", "slug": "metal-brackets", "icon_url": null, "image_url": null },
            { "id": 15, "name": "Ceramic Brackets", "slug": "ceramic-brackets", "icon_url": null, "image_url": null }
          ]
        },
        { "id": 21, "name": "Bands & Tubes", "slug": "bands-tubes", "icon_url": null, "image_url": null, "children": [] }
      ]
    }
  ]
}
```

Query options:

| Param | Meaning |
|---|---|
| `top=1` | only the **top categories** (max 12, ordered) - use for the **home screen** |

## 2. Top categories for the home screen

`GET /store/categories?top=1` - same shape as above, at most 12 categories. For the home tiles use `name`, `slug`, `icon_url`,
`image_url`; ignore `sub_categories` there.

## 3. One category

`GET /store/categories/{slug}` - e.g. `/store/categories/orthodontics`. Same object as one item above (`data` is the object, not a list).
`404` when the slug does not exist or the category is inactive.

## How the category page (like dentalkart.com/categories) maps to the data

1. Call `GET /store/categories` once.
2. Left list = `data[]` (name, icon_url). The selected one is the active tab.
3. Right grid = the selected category's `sub_categories[]` (icon_url, name, "N items" = `sub_categories.length`).
4. Show the dropdown arrow only when `children.length > 0`; its items are `children[]`.
5. Links: sub-category / child without children -> products page by `slug`.

## Other customer APIs that already exist

Other examples are in `docs/postman/Dentalkart-APIs.postman_collection.json`: `GET /store/lookups/registration-types|vendor-types|countries`,
`GET /store/company-registration/options`, `POST /store/company-registrations` (seller registration form),
`GET /store/brands` and `GET /store/brands/{slug}`, `GET /store/settings/delivery`, `POST /store/auth/login`, `GET /store/auth/me`.
