<?php

namespace App\Http\Requests\Admin;

use App\Domain\Content\Models\Banner;
use App\Support\ShopTime;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST /banners, multipart) or update (banner in the URL; send only what changes) a banner.
 * Pictures: `desktop_image` (required on create) and `mobile_image` (optional; `remove_mobile_image` drops it).
 * `link_url` is an http(s) address or a site path such as /category/phones - never javascript: or data: links.
 */
class SaveBannerRequest extends FormRequest
{
    public const IMAGE_RULES = ['image', 'mimes:png,jpg,jpeg,webp', 'dimensions:min_width=300,max_width=6000,max_height=6000'];

    public function authorize(): bool
    {
        return true;
    }

    /** Dates are typed in shop time (an offset is converted to it), see ShopTime. */
    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['starts_at', 'ends_at'] as $key) {
            if ($this->filled($key)) {
                $data[$key] = ShopTime::normalise($this->input($key));
            }
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $banner = $this->route('banner');
        $req = $banner ? 'sometimes' : 'required';

        return [
            'placement' => [$req, 'string', Rule::in(array_keys(Banner::PLACEMENTS))],
            'title' => ['nullable', 'string', 'max:160'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'button_text' => ['nullable', 'string', 'max:60'],
            'link_url' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                $ok = preg_match('#^https?://[^\s/$.?\#].\S*$#i', $value) === 1 || preg_match('#^/(?!/)\S*$#', $value) === 1;
                if (! $ok) {
                    $fail('The link must start with http://, https:// or / (a page of your store).');
                }
            }],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'desktop_image' => [$banner ? 'nullable' : 'required', ...self::IMAGE_RULES, 'max:5120'],
            'mobile_image' => ['nullable', ...self::IMAGE_RULES, 'max:3072'],
            'remove_mobile_image' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'desktop_image.required' => 'Choose the banner picture.',
            'desktop_image.max' => 'The banner picture must be 5 MB or smaller.',
            'desktop_image.dimensions' => 'The banner picture must be at least 300 px wide and at most 6000 x 6000 px.',
            'mobile_image.max' => 'The mobile picture must be 3 MB or smaller.',
            'mobile_image.dimensions' => 'The mobile picture must be at least 300 px wide and at most 6000 x 6000 px.',
            'placement.in' => 'Choose where the banner is shown.',
        ];
    }

    public function attributes(): array
    {
        return [
            'desktop_image' => 'banner picture', 'mobile_image' => 'mobile picture', 'link_url' => 'link', 'button_text' => 'button text',
            'starts_at' => 'start date', 'ends_at' => 'end date',
        ];
    }

    /** The end must come after the start (also when only one of them is sent on an update). */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $banner = $this->route('banner');
            $start = $this->has('starts_at') ? $this->input('starts_at') : $banner?->starts_at;
            $end = $this->has('ends_at') ? $this->input('ends_at') : $banner?->ends_at;
            if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start))) {
                $validator->errors()->add('ends_at', 'The end date must be after the start date.');
            }
        }];
    }

    /** Column values (and `remove_mobile_image`); the pictures are read with $request->file(). */
    public function bannerData(): array
    {
        return collect($this->validated())->except(['desktop_image', 'mobile_image'])->all();
    }
}
