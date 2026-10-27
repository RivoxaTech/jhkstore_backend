<?php

namespace Database\Seeders;

use App\Domain\Company\Models\CompanyRegistrationType;
use App\Domain\Company\Models\Country;
use App\Domain\Company\Models\VendorType;
use Illuminate\Database\Seeder;

/**
 * Dropdown options of the seller registration form.
 * The live site's exact option lists were not visible, so these are typical values; admins can edit them later.
 */
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Private Limited Company', 'Public Limited Company', 'Limited Liability Partnership (LLP)',
            'Partnership Firm', 'Sole Proprietorship', 'One Person Company', 'Other',
        ] as $i => $name) {
            CompanyRegistrationType::updateOrCreate(['name' => $name], ['sort_order' => $i]);
        }

        foreach ([
            'Manufacturer', 'Importer', 'Distributor', 'Authorized Dealer', 'Wholesaler', 'Retailer', 'Other',
        ] as $i => $name) {
            VendorType::updateOrCreate(['name' => $name], ['sort_order' => $i]);
        }

        $countries = [
            'IN' => ['India', '+91'], 'US' => ['United States', '+1'], 'GB' => ['United Kingdom', '+44'],
            'DE' => ['Germany', '+49'], 'FR' => ['France', '+33'], 'IT' => ['Italy', '+39'], 'ES' => ['Spain', '+34'],
            'CH' => ['Switzerland', '+41'], 'SE' => ['Sweden', '+46'], 'DK' => ['Denmark', '+45'],
            'NL' => ['Netherlands', '+31'], 'BE' => ['Belgium', '+32'], 'AT' => ['Austria', '+43'],
            'JP' => ['Japan', '+81'], 'KR' => ['South Korea', '+82'], 'CN' => ['China', '+86'],
            'TW' => ['Taiwan', '+886'], 'HK' => ['Hong Kong', '+852'], 'SG' => ['Singapore', '+65'],
            'MY' => ['Malaysia', '+60'], 'TH' => ['Thailand', '+66'], 'VN' => ['Vietnam', '+84'],
            'ID' => ['Indonesia', '+62'], 'PK' => ['Pakistan', '+92'], 'BD' => ['Bangladesh', '+880'],
            'LK' => ['Sri Lanka', '+94'], 'NP' => ['Nepal', '+977'], 'AE' => ['United Arab Emirates', '+971'],
            'SA' => ['Saudi Arabia', '+966'], 'TR' => ['Turkey', '+90'], 'IL' => ['Israel', '+972'],
            'BR' => ['Brazil', '+55'], 'MX' => ['Mexico', '+52'], 'CA' => ['Canada', '+1'],
            'AU' => ['Australia', '+61'], 'NZ' => ['New Zealand', '+64'], 'ZA' => ['South Africa', '+27'],
            'EG' => ['Egypt', '+20'], 'PL' => ['Poland', '+48'], 'CZ' => ['Czech Republic', '+420'],
            'RU' => ['Russia', '+7'], 'UA' => ['Ukraine', '+380'], 'IE' => ['Ireland', '+353'],
            'PT' => ['Portugal', '+351'], 'FI' => ['Finland', '+358'], 'NO' => ['Norway', '+47'],
            'LI' => ['Liechtenstein', '+423'], 'HU' => ['Hungary', '+36'], 'GR' => ['Greece', '+30'],
        ];
        foreach ($countries as $code => [$name, $dial]) {
            Country::updateOrCreate(['code' => $code], ['name' => $name, 'dial_code' => $dial]);
        }
    }
}
