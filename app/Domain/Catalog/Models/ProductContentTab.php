<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class ProductContentTab extends Model
{
    /** tab_key => label shown on the product page */
    public const TABS = [
        'features' => 'Features',
        'description' => 'Description',
        'packaging' => 'Packaging',
        'specifications' => 'Key Specifications',
        'directions' => 'Direction To Use',
        'additional_info' => 'Additional Info',
        'warranty' => 'Warranty',
    ];

    protected $fillable = ['product_id', 'tab_key', 'format', 'content', 'manual_url', 'sort_order'];

    public function label(): string
    {
        return self::TABS[$this->tab_key] ?? ucfirst(str_replace('_', ' ', $this->tab_key));
    }
}
