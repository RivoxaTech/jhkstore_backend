<?php

namespace Database\Seeders;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Actions\ManageProductImages;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\SubCategory;
use App\Domain\Catalog\Models\Tag;
use App\Domain\Content\Actions\SaveBanner;
use App\Domain\Content\Models\Banner;
use App\Domain\Delivery\Models\DeliveryZone;
use App\Domain\Promotion\Models\Coupon;
use App\Domain\Promotion\Models\Discount;
use App\Domain\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * OPTIONAL sample data for the storefront developer (not part of DatabaseSeeder): a neutral children's-toy catalogue,
 * nothing that names a real business.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * What it makes: 6 categories (five with sub-categories, "Arts & Crafts" without), 40 products with options, bulk prices, the tags
 * best-seller / new-arrival / featured (and the featured switch), a generated picture or two per product (drawn locally with GD, stored like an
 * upload: no hot-linked pictures), 3 banners, one automatic discount, one coupon (WELCOME10), two delivery zones, bank transfer switched on with one
 * account, and a few store texts. Safe to run again: everything is matched by slug / SKU / code / name, nothing is duplicated.
 */
class DemoSeeder extends Seeder
{
    /** [name, slug, [sub-categories], hue of its pictures (0-359)] */
    private const CATEGORIES = [
        ['Building & Blocks', 'building-blocks', ['Wooden Blocks', 'Brick Sets'], 28],
        ['Soft Toys', 'soft-toys', ['Plush Animals', 'Cuddle Buddies'], 340],
        ['Learning & Puzzles', 'learning-puzzles', ['Puzzles', 'Letters & Numbers', 'Science Kits'], 200],
        ['Outdoor Play', 'outdoor-play', ['Ride-ons', 'Sand & Water'], 120],
        ['Arts & Crafts', 'arts-crafts', [], 275],
        ['Baby & Toddler', 'baby-toddler', ['Rattles & Teethers', 'Bath Toys'], 50],
    ];

    /**
     * [name, SKU, category slug, sub-category (or null), MRP, price, tags, options (null = one option), short description, bulk? (3+ and 6+ get cheaper)]
     * Tags: b = best-seller, n = new-arrival, f = featured.
     */
    private const PRODUCTS = [
        ['Rainbow Stacking Blocks', 'TOY-001', 'building-blocks', 'Wooden Blocks', 35, 28, 'bf', ['24 pieces', '48 pieces'], 'Smooth wooden blocks in bright colours for little builders.', true],
        ['Classic Wooden Alphabet Blocks', 'TOY-002', 'building-blocks', 'Wooden Blocks', 30, 24, 'b', null, 'Twenty-six blocks to spell, stack and sort.', true],
        ['Wooden Castle Builder', 'TOY-003', 'building-blocks', 'Wooden Blocks', 55, 45, 'n', null, 'A castle with towers, arches and a drawbridge to build again and again.', false],
        ['Mega Brick Starter Set', 'TOY-004', 'building-blocks', 'Brick Sets', 40, 32, 'b', ['100 bricks', '250 bricks'], 'Big, easy-grip bricks that click together.', true],
        ['City Builder Brick Box', 'TOY-005', 'building-blocks', 'Brick Sets', 48, 39, 'nf', null, 'Roads, houses and a little fire truck to build a busy town.', false],
        ['Magnetic Tiles Set', 'TOY-006', 'building-blocks', null, 60, 49, 'f', ['32 tiles', '64 tiles'], 'Colourful magnetic tiles that snap into shapes and towers.', true],
        ['Marble Run Tower', 'TOY-007', 'building-blocks', 'Brick Sets', 52, 42, '', null, 'Build a track and watch the marbles race to the bottom.', false],
        ['Cuddly Bear Plush', 'TOY-008', 'soft-toys', 'Plush Animals', 24, 18, 'bf', ['Small', 'Medium', 'Large'], 'A soft, huggable bear for bedtime and adventures.', true],
        ['Floppy Bunny Plush', 'TOY-009', 'soft-toys', 'Plush Animals', 22, 17, 'n', ['Pink', 'Grey'], 'Long ears, soft fur and a cheerful face.', true],
        ['Gentle Elephant Plush', 'TOY-010', 'soft-toys', 'Plush Animals', 26, 20, '', null, 'A calm, squishy elephant friend.', false],
        ['Sleepy Sloth Cuddle Pal', 'TOY-011', 'soft-toys', 'Cuddle Buddies', 28, 22, 'b', null, 'A sleepy sloth with long arms made for hugging.', false],
        ['Dreamy Cloud Pillow Friend', 'TOY-012', 'soft-toys', 'Cuddle Buddies', 20, 16, 'n', null, 'A cloud-shaped cushion with a sweet sleepy face.', false],
        ['Giant Teddy Bear', 'TOY-013', 'soft-toys', 'Plush Animals', 80, 65, 'f', null, 'A very big bear for very big hugs.', false],
        ['Wooden Animal Puzzle', 'TOY-014', 'learning-puzzles', 'Puzzles', 18, 14, 'b', null, 'Chunky puzzle pieces shaped like farm animals.', true],
        ['Floor Puzzle: Under the Sea', 'TOY-015', 'learning-puzzles', 'Puzzles', 25, 20, 'n', ['24 pieces', '48 pieces'], 'A big floor puzzle with fish, whales and a little submarine.', false],
        ['Shape Sorter Cube', 'TOY-016', 'learning-puzzles', 'Puzzles', 22, 17, '', null, 'Match the shapes to the holes and learn as you play.', true],
        ['Alphabet Learning Cards', 'TOY-017', 'learning-puzzles', 'Letters & Numbers', 12, 9, 'b', null, 'Bright picture cards for first letters and words.', true],
        ['Counting Beads and Abacus', 'TOY-018', 'learning-puzzles', 'Letters & Numbers', 24, 19, 'f', null, 'Slide the beads and learn to count.', false],
        ['Number Train Set', 'TOY-019', 'learning-puzzles', 'Letters & Numbers', 32, 26, 'n', null, 'A little train of numbered carriages to line up.', false],
        ['Kitchen Volcano Science Kit', 'TOY-020', 'learning-puzzles', 'Science Kits', 28, 22, 'n', null, 'Make a safe, fizzy volcano at the kitchen table.', false],
        ['Magnet Explorer Set', 'TOY-021', 'learning-puzzles', 'Science Kits', 26, 21, '', null, 'Find out what magnets pick up and what they do not.', false],
        ['Rock and Crystal Dig Kit', 'TOY-022', 'learning-puzzles', 'Science Kits', 30, 24, 'b', null, 'Dig out real rocks and crystals with the little tools inside.', false],
        ['Balance Bike', 'TOY-023', 'outdoor-play', 'Ride-ons', 90, 75, 'bf', ['Red', 'Blue', 'Green'], 'A light balance bike that grows with your child.', false],
        ['Scooter for Little Riders', 'TOY-024', 'outdoor-play', 'Ride-ons', 70, 58, 'n', ['Teal', 'Orange'], 'Three wheels, a steady stand and a bell.', false],
        ['Beach Sand Play Set', 'TOY-025', 'outdoor-play', 'Sand & Water', 18, 14, 'b', null, 'Buckets, spades and moulds for the beach or the sandpit.', true],
        ['Water Table Splash Fun', 'TOY-026', 'outdoor-play', 'Sand & Water', 48, 39, '', null, 'A table of water for pouring, floating and splashing.', false],
        ['Garden Bug Catcher Kit', 'TOY-027', 'outdoor-play', null, 16, 12, 'n', null, 'A magnifier and a little box to meet the garden creatures.', true],
        ['Jumbo Chalk Set', 'TOY-028', 'arts-crafts', null, 10, 7, 'b', ['12 colours', '24 colours'], 'Chunky, washable chalk for the pavement.', true],
        ['Washable Finger Paint Set', 'TOY-029', 'arts-crafts', null, 15, 11, 'bf', null, 'Six bright colours that wash off hands and tables.', true],
        ['Wooden Doll Colouring Kit', 'TOY-030', 'arts-crafts', null, 20, 16, 'n', null, 'Paint and decorate your own little wooden dolls.', false],
        ['Craft Box: Paper and Glitter', 'TOY-031', 'arts-crafts', null, 22, 17, '', null, 'Paper, stickers, glitter glue and safe scissors in one box.', false],
        ['Modelling Dough Tubs', 'TOY-032', 'arts-crafts', null, 14, 10, 'b', ['4 tubs', '8 tubs'], 'Soft, non-drying dough in bright colours.', true],
        ['Easel and Whiteboard', 'TOY-033', 'arts-crafts', null, 55, 45, 'f', null, 'A two-sided easel with a chalkboard and a whiteboard.', false],
        ['Soft Rattle Set', 'TOY-034', 'baby-toddler', 'Rattles & Teethers', 16, 12, 'b', null, 'Three gentle rattles that are easy for tiny hands.', true],
        ['Silicone Teething Ring', 'TOY-035', 'baby-toddler', 'Rattles & Teethers', 9, 7, 'n', ['Mint', 'Peach'], 'A soft ring for sore gums.', true],
        ['Rainbow Stacking Rings', 'TOY-036', 'baby-toddler', null, 14, 11, 'b', null, 'Stack the rings from big to small.', true],
        ['Bath Time Floating Friends', 'TOY-037', 'baby-toddler', 'Bath Toys', 15, 11, 'n', null, 'Six little animals that float and squirt.', true],
        ['Bath Boat and Ducks', 'TOY-038', 'baby-toddler', 'Bath Toys', 13, 10, '', null, 'A boat and three ducks for the bath.', false],
        ['Soft Activity Book', 'TOY-039', 'baby-toddler', null, 17, 13, 'f', null, 'A cloth book with crinkles, mirrors and flaps.', false],
        ['Push and Pull Duck', 'TOY-040', 'baby-toddler', null, 24, 19, 'n', null, 'A wooden duck that waddles along behind a toddler.', false],
    ];

    private const TAGS = ['b' => 'best-seller', 'n' => 'new-arrival', 'f' => 'featured'];

    public function run(): void
    {
        $this->call(TagSeeder::class);
        $admin = Admin::query()->first() ?? Admin::create(['username' => 'demo', 'name' => 'Demo', 'password' => 'demo-password', 'is_active' => true]);

        $categories = $this->categories();
        $this->products($categories, $admin);
        $this->banners($admin);
        $this->promotions();
        $this->delivery();
        $this->store();
    }

    // ------------------------------------------------------------------ catalogue

    /** @return array<string, array{category: Category, hue: int}> by slug */
    private function categories(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $i => [$name, $slug, $subs, $hue]) {
            $category = Category::query()->withTrashed()->firstOrNew(['slug' => $slug]);
            if (! $category->exists) {
                $category->fill(['name' => $name, 'is_active' => true, 'is_top' => true, 'sort_order' => $i + 1]);
                $category->slug = $slug;
                $category->save();
            }
            foreach ($subs as $j => $subName) {
                $sub = SubCategory::query()->withTrashed()->firstOrNew(['category_id' => $category->id, 'name' => $subName]);
                if (! $sub->exists) {
                    $sub->fill(['name' => $subName, 'is_active' => true, 'sort_order' => $j + 1]);
                    $sub->category_id = $category->id;
                    $sub->slug = SubCategory::uniqueSlug($subName);
                    $sub->save();
                }
            }
            $out[$slug] = ['category' => $category, 'hue' => $hue];
        }

        return $out;
    }

    private function products(array $categories, Admin $admin): void
    {
        $save = app(SaveProduct::class);
        $images = app(ManageProductImages::class);

        foreach (self::PRODUCTS as $n => [$name, $sku, $categorySlug, $subName, $mrp, $price, $tagCodes, $options, $short, $bulk]) {
            if (DB::table('products')->where('sku', $sku)->exists()) {
                continue;
            }
            ['category' => $category, 'hue' => $hue] = $categories[$categorySlug];
            $tagSlugs = array_map(fn ($c) => self::TAGS[$c], str_split($tagCodes));
            $optionNames = $options ?? [null];

            $product = $save->create([
                'category_id' => $category->id,
                'sub_category_id' => $subName ? SubCategory::query()->where('category_id', $category->id)->where('name', $subName)->value('id') : null,
                'name' => $name,
                'sku' => $sku,
                'short_description' => $short,
                'description' => $short.' Made for curious children, with rounded edges and child-safe finishes. Suitable for ages 18 months and up unless the pack says otherwise.',
                'status' => 'active',
                'min_order_qty' => 1,
                'max_order_qty' => 10,
                'tag_ids' => Tag::query()->whereIn('slug', $tagSlugs)->pluck('id')->all(),
                'content_tabs' => [
                    ['tab_key' => 'description', 'format' => 'paragraphs', 'content' => $short."\n\nChildren learn best through play, and this toy is made to be picked up, explored and shared."],
                    ['tab_key' => 'features', 'format' => 'bullets', 'content' => "Non-toxic paints and finishes\nSmooth, rounded edges\nEasy for small hands to hold\nA gift they will want to open again"],
                    ['tab_key' => 'specifications', 'format' => 'table', 'content' => "Age|18 months and up\nMaterial|Wood, fabric or BPA-free plastic\nCare|Wipe clean with a damp cloth"],
                ],
                'variants' => collect($optionNames)->values()->map(function ($option, $i) use ($name, $sku, $mrp, $price, $bulk, $optionNames) {
                    $step = 1 + $i * 0.35;   // the bigger option costs more
                    $sell = round($price * $step, 0);

                    return [
                        'name' => $option === null ? $name : "$name - $option",
                        'sku' => count($optionNames) === 1 ? "$sku-1" : $sku.'-'.($i + 1),
                        'is_default' => $i === 0,
                        'mrp' => round($mrp * $step, 0),
                        'selling_price' => $sell,
                        'stock' => 12 + (($i * 7 + strlen($name)) % 40),
                        'price_tiers' => $bulk ? [['min_qty' => 3, 'price' => round($sell * 0.92, 0)], ['min_qty' => 6, 'price' => round($sell * 0.85, 0)]] : [],
                    ];
                })->all(),
            ], $admin);

            if (in_array('featured', $tagSlugs, true)) {
                $save->setFeatured($product, true, $admin);
            }
            $this->pictures($images, $product, $name, $hue, $n);
        }

        // a product that is sold out shows the sold-out state on the storefront
        $sold = Product::query()->where('sku', 'TOY-007')->first();
        if ($sold && ! $sold->is_sold_out) {
            $save->setSoldOut($sold, true, $admin);
        }
    }

    /** Two pictures for every third product (a gallery), one for the rest. */
    private function pictures(ManageProductImages $images, Product $product, string $name, int $hue, int $n): void
    {
        $files = [];
        $count = $n % 3 === 0 ? 2 : 1;
        for ($i = 0; $i < $count; $i++) {
            $files[] = $this->picture($name, ($hue + $i * 24 + $n * 3) % 360, $n * 7 + $i, 800, 800, "{$product->slug}-".($i + 1).'.jpg');
        }
        $images->add($product, $files, Admin::query()->firstOrFail());
        array_map(fn ($file) => @unlink($file->getPathname()), $files);   // medialibrary keeps its own copy
    }

    // ------------------------------------------------------------------ banners, promotions, delivery, texts

    private function banners(Admin $admin): void
    {
        if (Banner::query()->exists()) {
            return;
        }
        $banners = app(SaveBanner::class);
        $rows = [
            ['home_hero', 'Toys that make *learning* fun', 'Safe, joyful toys for curious little minds.', 'Shop Now', '/shop', 200, 1600, 700],
            ['home_hero', 'Soft friends for *bedtime*', 'Cuddly plush toys that last for years.', 'Shop Soft Toys', '/category/soft-toys', 340, 1600, 700],
            ['home_secondary', 'The *perfect gift* for every little dreamer', 'Wrap up something they will love.', 'Find a Gift', '/shop', 28, 1600, 500],
        ];
        foreach ($rows as $i => [$placement, $title, $subtitle, $button, $link, $hue, $w, $h]) {
            $desktop = $this->picture($title, $hue, 100 + $i, $w, $h, "banner-$i.jpg");
            $mobile = $placement === 'home_hero' ? $this->picture($title, $hue, 100 + $i, 800, 1000, "banner-$i-mobile.jpg") : null;
            $banners->create(['placement' => $placement, 'title' => $title, 'subtitle' => $subtitle, 'button_text' => $button, 'link_url' => $link], $desktop, $mobile, $admin);
            @unlink($desktop->getPathname());
            $mobile && @unlink($mobile->getPathname());
        }
    }

    private function promotions(): void
    {
        Discount::query()->firstOrCreate(['name' => 'Spring toy sale'], ['type' => 'percent', 'value' => 10, 'scope' => 'category', 'is_active' => true, 'priority' => 0])
            ->targets()->firstOrCreate(['type' => 'category', 'target_id' => Category::query()->where('slug', 'arts-crafts')->value('id')]);

        Coupon::query()->firstOrCreate(['code' => 'WELCOME10'], ['name' => 'Welcome: 10% off', 'type' => 'percent', 'value' => 10, 'scope' => 'store', 'is_active' => true, 'min_order' => 30, 'max_discount' => 20]);
    }

    private function delivery(): void
    {
        DeliveryZone::query()->firstOrCreate(['name' => 'City centre'], ['match_type' => 'city', 'match_values' => ['Springfield'], 'fee' => 3, 'free_above' => 60, 'eta_text' => '1 to 2 days', 'sort_order' => 1, 'is_active' => true]);
        DeliveryZone::query()->firstOrCreate(['name' => 'Rest of the country'], ['match_type' => 'postcode', 'match_values' => ['*'], 'fee' => 7, 'free_above' => 100, 'eta_text' => '3 to 5 days', 'sort_order' => 2, 'is_active' => true]);
    }

    private function store(): void
    {
        app(Settings::class)->set([
            'store_name' => 'JHK Store',
            'store_tagline' => 'Play. Learn. Grow.',
            'store_email' => 'hello@example.com',
            'store_phone' => '+1 555 010 2030',
            'store_address' => '12 Example Street, Springfield',
            'support_hours' => 'Mon-Sat 9:00-18:00',
            'whatsapp_number' => '+1 555 010 2030',
            'delivery_method' => 'zones',
            'delivery_unmatched' => 'flat',
            'delivery_fee' => 7,
            'pay_cod_enabled' => true,
            'pay_bank_enabled' => true,
            'pay_bank_instructions' => 'Use your order number as the payment reference, then upload a screenshot of the transfer.',
            'pay_bank_accounts' => [['bank' => 'Example Bank', 'title' => 'JHK Store', 'number' => '0123456789', 'iban' => '', 'active' => true]],
            'seo_meta_title' => 'JHK Store: safe, joyful toys',
            'seo_meta_description' => 'Safe, non-toxic and educational toys for children, delivered to your door.',
        ]);
    }

    // ------------------------------------------------------------------ generated pictures (GD, no files shipped, nothing downloaded)

    /** A soft gradient with a few toy-like shapes. The same arguments always draw the same picture. Returns an upload ready for medialibrary. */
    private function picture(string $label, int $hue, int $seed, int $w, int $h, string $name): UploadedFile
    {
        mt_srand(crc32($label) + $seed);
        $img = imagecreatetruecolor($w, $h);
        [$r1, $g1, $b1] = $this->hsl($hue, 0.65, 0.88);
        [$r2, $g2, $b2] = $this->hsl(($hue + 25) % 360, 0.7, 0.72);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            imageline($img, 0, $y, $w, $y, imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
        }
        $unit = min($w, $h);
        for ($i = 0; $i < 5; $i++) {
            [$r, $g, $b] = $this->hsl(($hue + 40 + $i * 55 + mt_rand(0, 30)) % 360, 0.75, 0.55);
            $colour = imagecolorallocate($img, $r, $g, $b);
            $size = (int) ($unit * (0.14 + mt_rand(0, 14) / 100));
            $x = mt_rand((int) ($w * 0.12), (int) ($w * 0.88));
            $y = mt_rand((int) ($h * 0.15), (int) ($h * 0.85));
            match ($i % 3) {
                0 => imagefilledellipse($img, $x, $y, $size, $size, $colour),
                1 => imagefilledrectangle($img, $x - (int) ($size / 2), $y - (int) ($size / 2), $x + (int) ($size / 2), $y + (int) ($size / 2), $colour),
                default => imagefilledpolygon($img, [$x, $y - $size / 2, $x + $size / 2, $y + $size / 2, $x - $size / 2, $y + $size / 2], $colour),
            };
        }
        $path = tempnam(sys_get_temp_dir(), 'demo').'.jpg';
        imagejpeg($img, $path, 82);
        imagedestroy($img);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /** @return array{0:int,1:int,2:int} */
    private function hsl(int $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (intdiv($h, 60) % 6) {
            0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x], 3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x],
        };

        return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
    }
}
