<?php

namespace App\Domain\Company\Models;

use App\Domain\Auth\Models\Admin;
use App\Domain\Company\Enums\CompanySource;
use App\Domain\Company\Enums\CompanyStatus;
use App\Support\GeneratesUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Company extends Model implements HasMedia
{
    use GeneratesUniqueSlug, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'name', 'registration_type_id', 'vendor_type_id', 'origin_country_id', 'email', 'contact_number',
        'vat_gst_number', 'contact_name', 'contact_mobile', 'contact_email', 'contact_whatsapp', 'address',
        'landmark', 'city', 'zip_code', 'promotion_support',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'source' => CompanySource::class,
            'is_in_house' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        // Logo is public (shown on the storefront); the catalogue is private and only served to admins.
        $this->addMediaCollection('logo')->singleFile()->useDisk('public');
        $this->addMediaCollection('catalogue')->useDisk('local');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(256)->height(256)->performOnCollections('logo')->nonQueued();
    }

    public function registrationType(): BelongsTo
    {
        return $this->belongsTo(CompanyRegistrationType::class, 'registration_type_id');
    }

    public function vendorType(): BelongsTo
    {
        return $this->belongsTo(VendorType::class);
    }

    public function originCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'origin_country_id');
    }

    public function brands(): HasMany
    {
        // chaperone(): each loaded brand knows its company, so a brand can show the company logo without extra queries.
        return $this->hasMany(Brand::class)->chaperone();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', CompanyStatus::Approved);
    }
}
