<?php

namespace Database\Seeders;

use App\Domain\Catalog\CategoryTree;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\SubCategory;
use Illuminate\Database\Seeder;

/**
 * Categories and sub-categories of the customer site (names, slugs and colours come from the frontend's categories.js),
 * plus a few sub-sub-categories as examples. The first 12 categories start as 'top' (home screen).
 * Idempotent: matched by slug, so running it again updates names/colours instead of duplicating (admin edits to order and switches are kept). Images are uploaded later in the admin panel.
 */
class CategorySeeder extends Seeder
{
    /** [name, slug, colour, [[sub-category name, slug], ...]] */
    private const CATEGORIES = [
        ['Endodontics', 'endodontics', '#e8f4f8', [
            ['Root Canal Files', 'root-canal-files'],
            ['Rotary Files', 'rotary-files'],
            ['Gutta Percha', 'gutta-percha'],
            ['Irrigating Needles', 'irrigating-needles'],
            ['Endomotors', 'endomotors'],
            ['Apex Locators', 'apex-locators'],
            ['Obturators', 'obturators'],
            ['Sealer/Cement', 'sealer-cement'],
            ['Access Burs', 'access-burs'],
        ]],
        ['Orthodontics', 'orthodontics', '#f0f8e8', [
            ['Brackets', 'brackets'],
            ['Wires', 'wires'],
            ['Bands & Tubes', 'bands-tubes'],
            ['Elastics', 'elastics'],
            ['Retainers', 'retainers'],
            ['Clear Aligners', 'clear-aligners'],
            ['Pliers & Cutters', 'pliers-cutters'],
        ]],
        ['Equipments', 'equipments', '#f8f0e8', [
            ['Dental Chairs', 'dental-chairs'],
            ['Autoclaves', 'autoclaves'],
            ['X-Ray Units', 'xray-units'],
            ['Air Compressors', 'air-compressors'],
            ['Ultrasonic Scalers', 'ultrasonic-scalers'],
            ['Light Cure Units', 'light-cure-units'],
            ['Amalgamators', 'amalgamators'],
        ]],
        ['Instruments', 'instruments', '#f8e8f0', [
            ['Extraction Forceps', 'extraction-forceps'],
            ['Scalers & Curettes', 'scalers-curettes'],
            ['Mirrors & Probes', 'mirrors-probes'],
            ['Elevators', 'elevators'],
            ['Burs', 'burs'],
            ['Pluggers & Condensers', 'pluggers-condensers'],
        ]],
        ['Restorative', 'restorative', '#e8e8f8', [
            ['Composites', 'composites'],
            ['Glass Ionomer', 'glass-ionomer'],
            ['Dental Cements', 'dental-cements'],
            ['Matrices', 'matrices'],
            ['Bonding Agents', 'bonding-agents'],
            ['Cavity Liners', 'cavity-liners'],
        ]],
        ['Prosthodontics', 'prosthodontics', '#f8f8e8', [
            ['Impression Materials', 'impression-materials'],
            ['Crown & Bridge', 'crown-bridge'],
            ['Denture Teeth', 'denture-teeth'],
            ['Articulation', 'articulation'],
            ['Denture Acrylics', 'denture-acrylics'],
        ]],
        ['Implantology', 'implantology', '#e8f8f4', [
            ['Implant Systems', 'implant-systems'],
            ['Bone Grafts', 'bone-grafts'],
            ['Membranes', 'membranes'],
            ['Implant Instruments', 'implant-instruments'],
            ['Abutments', 'abutments'],
        ]],
        ['Periodontics', 'periodontics', '#f4e8f8', [
            ['Scalers', 'scalers'],
            ['Curettes', 'curettes'],
            ['Perio Probes', 'perio-probes'],
            ['Surgical Kits', 'surgical-kits'],
        ]],
        ['Oral Surgery', 'oral-surgery', '#f8ece8', [
            ['Surgical Forceps', 'surgical-forceps'],
            ['Sutures', 'sutures'],
            ['Surgical Burs', 'surgical-burs'],
            ['Elevators', 'oral-elevators'],
        ]],
        ['Sterilization', 'sterilization', '#e8f8ec', [
            ['Autoclave Pouches', 'autoclave-pouches'],
            ['Disinfectants', 'disinfectants'],
            ['Gloves', 'gloves'],
            ['Masks', 'masks'],
        ]],
        ['Materials', 'materials', '#ece8f8', [
            ['Alginate', 'alginate'],
            ['Plaster & Stone', 'plaster-stone'],
            ['Wax', 'wax'],
            ['Acrylic', 'acrylic'],
        ]],
        ['Student Section', 'student-section', '#f8f4e8', [
            ['Starter Kits', 'starter-kits'],
            ['Basic Instruments', 'basic-instruments'],
            ['Textbooks', 'textbooks'],
            ['Study Models', 'study-models'],
        ]],
        ['Pharmacy', 'pharmacy', '#f8e8ec', []],
    ];

    /** Sub-sub-categories: sub-category slug => [[name, slug], ...]. Only some sub-categories have them. */
    private const CHILDREN = [
        'root-canal-files' => [['Hand Files', 'hand-files'], ['K-Files', 'k-files'], ['H-Files', 'h-files']],
        'brackets' => [['Metal Brackets', 'metal-brackets'], ['Ceramic Brackets', 'ceramic-brackets'], ['Self-Ligating Brackets', 'self-ligating-brackets']],
        'wires' => [['NiTi Wires', 'niti-wires'], ['Stainless Steel Wires', 'stainless-steel-wires'], ['TMA Wires', 'tma-wires']],
        'retainers' => [['Retainer Boxes', 'retainer-boxes'], ['Relief Wax', 'relief-wax']],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $i => [$name, $slug, $color, $subs]) {
            $category = Category::withTrashed()->firstOrNew(['slug' => $slug]);
            $category->slug = $slug;
            $category->fill(['name' => $name, 'color' => $color] + ($category->exists ? [] : ['sort_order' => $i + 1, 'is_top' => $i < Category::MAX_TOP]))->save();
            $category->trashed() && $category->restore();

            foreach ($subs as $j => [$subName, $subSlug]) {
                $sub = SubCategory::withTrashed()->firstOrNew(['category_id' => $category->id, 'slug' => $subSlug]);
                $sub->category_id = $category->id;
                $sub->slug = $subSlug;
                $sub->fill(['name' => $subName] + ($sub->exists ? [] : ['sort_order' => $j + 1]))->save();
                $sub->trashed() && $sub->restore();

                foreach (self::CHILDREN[$subSlug] ?? [] as $k => [$childName, $childSlug]) {
                    $child = SubCategory::withTrashed()->firstOrNew(['category_id' => $category->id, 'slug' => $childSlug]);
                    $child->category_id = $category->id;
                    $child->parent_id = $sub->id;
                    $child->slug = $childSlug;
                    $child->fill(['name' => $childName] + ($child->exists ? [] : ['sort_order' => $k + 1]))->save();
                    $child->trashed() && $child->restore();
                }
            }
        }

        CategoryTree::forget();
    }
}
