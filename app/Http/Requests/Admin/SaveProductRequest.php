<?php

namespace App\Http\Requests\Admin;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductContentTab;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Reward\Actions\RewardLedger;
use App\Domain\Support\Settings;
use Illuminate\Support\Arr;
use App\Domain\Catalog\Models\Brand;
use Illuminate\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Create (POST /products) or update (product in the URL) a product with its variants, tags and content tabs.
 *
 * A SIMPLE product may send `mrp`, `selling_price`, `reward_points`, `expiry_date` and `stock` (a number)
 * at the top level instead of `variants`; they become the product's single hidden default variant.
 * Send `variants` for a product with options (an `id` keeps/updates a variant; variants missing from the list are removed).
 */
class SaveProductRequest extends FormRequest
{
    /** true when the simple (flat) price fields were turned into one variant */
    private bool $flat = false;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $product = $this->route('product');

        if (! $this->has('variants') && ($this->has('mrp') || $this->has('selling_price'))) {
            $this->flat = true;
            $default = $product?->variants()->orderByDesc('is_default')->orderBy('id')->first();

            $row = [
                'id' => $default?->id,
                'name' => $default?->name ?? $this->input('name', $product?->name),
                'sku' => $this->input('sku', $product?->sku),
                'is_default' => true,
                'mrp' => $this->input('mrp'),
                'selling_price' => $this->input('selling_price'),
                'reward_points' => $this->input('reward_points'),
                'expiry_date' => $this->input('expiry_date'),
                'stock' => $this->input('stock'),
                'stock_was' => $this->input('stock_was'),
            ];
            foreach (['price_tiers', 'weight_grams', 'barcode'] as $key) {
                if ($this->has($key)) {
                    $row[$key] = $this->input($key);
                }
            }
            $this->merge(['variants' => [$row]]);
        }
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $req = $product ? 'sometimes' : 'required';

        return [
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'category_id' => [$req, 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'sub_category_id' => ['nullable', 'integer', Rule::exists('sub_categories', 'id')->whereNull('deleted_at')],
            'name' => [$req, 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:280', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'sku' => [$req, 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product?->id)],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:100000'],
            'badge_text' => ['nullable', 'string', 'max:40'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,99.99'],
            'country_of_origin' => ['nullable', 'string', 'max:80'],
            'warranty_text' => ['nullable', 'string', 'max:255'],
            'min_order_qty' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'max_order_qty' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'is_returnable' => ['sometimes', 'boolean'],
            'return_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'is_cod_available' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(ProductStatus::values())],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],

            'variants' => [$product ? 'sometimes' : 'required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:80', 'distinct'],
            'variants.*.is_default' => ['sometimes', 'boolean'],
            'variants.*.mrp' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'variants.*.selling_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.reward_points' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'variants.*.expiry_date' => ['nullable', 'date'],
            'variants.*.barcode' => ['nullable', 'string', 'max:40'],
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'variants.*.stock_was' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'variants.*.low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'variants.*.price_tiers' => ['sometimes', 'nullable', 'array', 'max:5'],
            'variants.*.price_tiers.*.min_qty' => ['required', 'integer', 'min:2', 'max:65535', 'distinct'],
            'variants.*.price_tiers.*.price' => ['required', 'numeric', 'min:0', 'max:99999999'],

            'related_ids' => ['sometimes', 'array', 'max:12'],
            'related_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],

            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')],

            'content_tabs' => ['sometimes', 'array'],
            'content_tabs.*.tab_key' => ['required', Rule::in(array_keys(ProductContentTab::TABS)), 'distinct'],
            'content_tabs.*.format' => ['nullable', Rule::in(['paragraphs', 'bullets', 'table'])],
            'content_tabs.*.content' => ['nullable', 'string', 'max:100000'],
            'content_tabs.*.manual_url' => ['nullable', 'url', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'variants.*.mrp.required' => 'Enter the MRP.',
            'variants.*.selling_price.required' => 'Enter the selling price.',
            'variants.*.sku.distinct' => 'Two variants have the same SKU.',
        ];
    }

    /** Cross-field rules: ownership of brand / sub-category, price <= MRP, unique variant SKUs. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $this->checkOwnership($validator);
            $this->checkVariants($validator);
        }];
    }

    private function checkOwnership(Validator $validator): void
    {
        $product = $this->route('product');
        $brandId = $this->input('brand_id', $product?->brand_id);
        $categoryId = $this->input('category_id', $product?->category_id);
        $subId = $this->has('sub_category_id') ? $this->input('sub_category_id') : $product?->sub_category_id;

        if ($product && in_array($product->id, array_map('intval', (array) $this->input('related_ids', [])), true)) {
            $validator->errors()->add('related_ids', 'A product cannot be related to itself.');
        }

        $min = (int) $this->input('min_order_qty', $product?->min_order_qty ?? 1);
        if ((int) $this->input('max_order_qty', $product?->max_order_qty ?? 10) < $min) {
            $validator->errors()->add('max_order_qty', 'The maximum order quantity cannot be lower than the minimum.');
        }

        // New choices must be live ones; an unchanged choice that was switched off later does not block saving other edits.
        $changed = fn (string $key, mixed $current) => ! $product || ($this->has($key) && (int) $this->input($key) !== (int) $current);

        if ($this->has('brand_id') && $brandId) {
            $brand = Brand::query()->find($brandId);
            if ($brand && ! $brand->is_active && $changed('brand_id', $product?->brand_id)) {
                $validator->errors()->add('brand_id', 'This brand is not active.');
            }
        }

        if ($this->has('category_id') || $this->has('sub_category_id')) {
            $category = Category::query()->find($categoryId);
            if ($category && ! $category->is_active && $changed('category_id', $product?->category_id)) {
                $validator->errors()->add('category_id', 'This category is not active.');
            }
            if ($subId) {
                $sub = SubCategory::query()->find($subId);
                if ($sub && $sub->category_id !== (int) $categoryId) {
                    $validator->errors()->add('sub_category_id', 'This sub-category does not belong to the selected category.');
                } elseif ($sub && ! $sub->is_active && $changed('sub_category_id', $product?->sub_category_id)) {
                    $validator->errors()->add('sub_category_id', 'This sub-category is not active.');
                }
            }
        }
    }

    private function checkVariants(Validator $validator): void
    {
        $product = $this->route('product');
        $rows = $this->input('variants');
        if (! is_array($rows)) {
            return;
        }

        if (! collect($rows)->contains(fn ($row) => (bool) ($row['is_active'] ?? true))) {
            $validator->errors()->add('variants', 'At least one option must be active, otherwise the product has nothing to sell.');

            return;
        }

        $own = $product ? $product->variants()->pluck('id')->all() : [];
        if ($this->flat && $product && count($own) > 1) {
            $validator->errors()->add('variants', 'This product has options: send the variants list instead of a single price.');

            return;
        }

        foreach ($rows as $i => $row) {
            if (! empty($row['id']) && ! in_array((int) $row['id'], $own, true)) {
                $validator->errors()->add("variants.$i.id", 'This variant does not belong to the product.');
            }
            if ((float) $row['selling_price'] > (float) $row['mrp']) {
                $validator->errors()->add("variants.$i.selling_price", 'The selling price cannot be higher than the MRP.');
            }
            $this->checkTiers($validator, $i, $row);
            if (! $this->flat && blank($row['name'] ?? null)) {
                $validator->errors()->add("variants.$i.name", 'Name the option (for example "Blue, 500 ml").');
            }
            if (count($rows) > 1 && blank($row['sku'] ?? null)) {
                $validator->errors()->add("variants.$i.sku", 'Enter a SKU for the option.');
            }

            $sku = $row['sku'] ?? (count($rows) === 1 ? $this->input('sku', $product?->sku) : null);
            if ($sku !== null && DB::table('product_variants')->where('sku', $sku)->when($row['id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->exists()) {
                $validator->errors()->add("variants.$i.sku", 'This SKU is already used by another variant.');
            }
        }
    }

    /** Quantity prices must be cheaper than the selling price, and cheaper again for every higher quantity. */
    private function checkTiers(Validator $validator, int $i, array $row): void
    {
        $tiers = collect($row['price_tiers'] ?? [])->sortBy('min_qty')->all();
        $previous = (float) $row['selling_price'];

        foreach ($tiers as $j => $tier) {
            if ((float) $tier['price'] >= $previous) {
                $validator->errors()->add("variants.$i.price_tiers.$j.price", $previous === (float) $row['selling_price']
                    ? 'A quantity price must be lower than the selling price.'
                    : 'A larger quantity must have a lower price than the smaller quantity.');
            }
            $previous = (float) $tier['price'];
        }
    }

    /** Validated data for SaveProduct (price fields of a simple product are already folded into `variants`). */
    public function productData(): array
    {
        $data = $this->validated();
        if (! app(Settings::class)->brandsEnabled()) {
            unset($data['brand_id']); // brands are switched off: a product keeps whatever brand it had
        }
        unset($data['mrp'], $data['selling_price'], $data['reward_points'], $data['expiry_date'], $data['stock'], $data['stock_was']);
        if (! app(RewardLedger::class)->enabled() && isset($data['variants'])) {
            // points are not editable while the feature is off: existing values stay as they are
            $data['variants'] = array_map(fn ($row) => Arr::except($row, 'reward_points'), $data['variants']);
        }
        if (array_key_exists('return_days', $data) && $data['return_days'] === null) {
            unset($data['return_days']); // keeps the default (10)
        }

        return $data;
    }
}
