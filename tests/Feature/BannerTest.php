<?php

namespace Tests\Feature;

use App\Domain\Content\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = '/api/v1/store';
    private const ADMIN = '/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedBasics();
    }

    private function admin(): void
    {
        $this->actingAsAdmin();
    }

    private function image(string $name = 'banner.jpg', int $w = 1600, int $h = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    /** POST /admin/banners (multipart). */
    private function create(array $fields = [], ?UploadedFile $desktop = null): TestResponse
    {
        return $this->post(self::ADMIN.'/banners', array_merge([
            'placement' => 'home_hero', 'title' => 'Big sale', 'subtitle' => 'Up to 50% off', 'button_text' => 'Shop now', 'link_url' => 'https://shop.example.com/sale',
            'desktop_image' => $desktop ?? $this->image(),
        ], $fields), ['Accept' => 'application/json']);
    }

    private function update(int $id, array $fields): TestResponse
    {
        return $this->post(self::ADMIN."/banners/$id", $fields + ['_method' => 'PUT'], ['Accept' => 'application/json']);
    }

    private function banner(array $attrs = [], bool $withImage = true): Banner
    {
        static $n = 0;
        $n++;
        $banner = new Banner($attrs + ['placement' => 'home_hero', 'title' => "Banner $n", 'sort_order' => $n, 'is_active' => true]);
        $banner->save();
        if ($withImage) {
            $banner->addMedia($this->image("b$n.jpg"))->toMediaCollection('desktop');
        }

        return $banner;
    }

    private function live(string $query = ''): TestResponse
    {
        return $this->getJson(self::STORE.'/banners'.$query)->assertOk();
    }

    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // ------------------------------------------------------------------ admin: create, edit, delete

    public function test_an_admin_creates_a_banner_with_a_desktop_and_a_mobile_picture(): void
    {
        $this->admin();

        $res = $this->create(['mobile_image' => $this->image('m.jpg', 800, 800), 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2036-01-01 00:00:00'])->assertCreated();

        $res->assertJsonPath('data.placement', 'home_hero')
            ->assertJsonPath('data.title', 'Big sale')->assertJsonPath('data.subtitle', 'Up to 50% off')
            ->assertJsonPath('data.button_text', 'Shop now')->assertJsonPath('data.link_url', 'https://shop.example.com/sale')
            ->assertJsonPath('data.is_active', true)->assertJsonPath('data.status', 'live')->assertJsonPath('data.sort_order', 1);
        $this->assertStringStartsWith('http', $res->json('data.desktop_image_url'));
        $this->assertStringStartsWith('http', $res->json('data.mobile_image_url'));
        $this->assertStringStartsWith('http', $res->json('data.desktop_thumb_url'));
        $banner = Banner::query()->sole();
        $this->assertCount(1, $banner->getMedia('desktop'));
        $this->assertCount(1, $banner->getMedia('mobile'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'banner.created', 'actor_type' => 'admin']);
    }

    public function test_only_the_desktop_picture_is_required_and_new_banners_go_last_in_their_placement(): void
    {
        $this->admin();

        $first = $this->create(['title' => null, 'subtitle' => null, 'button_text' => null, 'link_url' => null])->assertCreated()
            ->assertJsonPath('data.mobile_image_url', null)->assertJsonPath('data.title', null)->assertJsonPath('data.link_url', null);
        $second = $this->create(['title' => 'Second'])->assertCreated();
        $other = $this->create(['placement' => 'promo_strip'])->assertCreated();

        $this->assertSame(1, $first->json('data.sort_order'));
        $this->assertSame(2, $second->json('data.sort_order'));
        $this->assertSame(1, $other->json('data.sort_order'));   // its own placement starts at 1
    }

    public function test_banner_input_is_validated(): void
    {
        $this->admin();

        $this->post(self::ADMIN.'/banners', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['placement', 'desktop_image']);
        $this->create(['placement' => 'sidebar'])->assertStatus(422)->assertJsonValidationErrors('placement');
        $this->create(['title' => str_repeat('t', 161)])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->create(['subtitle' => str_repeat('s', 256)])->assertStatus(422)->assertJsonValidationErrors('subtitle');
        $this->create(['button_text' => str_repeat('b', 61)])->assertStatus(422)->assertJsonValidationErrors('button_text');
        $this->create(['sort_order' => -1])->assertStatus(422)->assertJsonValidationErrors('sort_order');
        $this->create(['starts_at' => 'not a date'])->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $this->create(['starts_at' => '2026-06-01', 'ends_at' => '2026-05-01'])->assertStatus(422)->assertJsonValidationErrors('ends_at');

        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', '//evil.example.com', 'ftp://example.com', 'just words', 'https://'] as $bad) {
            $this->create(['link_url' => $bad])->assertStatus(422)->assertJsonValidationErrors('link_url');
        }
        foreach (['https://example.com/a?b=1', 'http://example.com', '/category/phones', '/'] as $good) {
            $this->create(['link_url' => $good])->assertCreated();
        }

        $this->assertSame(4, Banner::count());   // only the valid ones
    }

    public function test_pictures_are_validated_by_type_size_and_dimensions(): void
    {
        $this->admin();

        $this->create([], UploadedFile::fake()->create('banner.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors('desktop_image');
        $this->create([], UploadedFile::fake()->create('banner.svg', 10, 'image/svg+xml'))->assertStatus(422)->assertJsonValidationErrors('desktop_image');
        $this->create([], UploadedFile::fake()->create('banner.gif', 10, 'image/gif'))->assertStatus(422)->assertJsonValidationErrors('desktop_image');
        $this->create([], $this->image('big.jpg', 1600, 600)->size(6000))->assertStatus(422)->assertJsonValidationErrors('desktop_image');          // > 5 MB
        $this->create([], $this->image('tiny.jpg', 200, 100))->assertStatus(422)->assertJsonValidationErrors('desktop_image')                       // narrower than 300 px
            ->assertJsonPath('errors.desktop_image.0', 'The banner picture must be at least 300 px wide and at most 6000 x 6000 px.');
        $this->create([], $this->image('huge.jpg', 7000, 100))->assertStatus(422)->assertJsonValidationErrors('desktop_image');                     // wider than 6000 px
        $this->create(['mobile_image' => $this->image('m.jpg', 800, 800)->size(4000)])->assertStatus(422)->assertJsonValidationErrors('mobile_image'); // > 3 MB
        $this->create(['mobile_image' => UploadedFile::fake()->create('m.txt', 1, 'text/plain')])->assertStatus(422)->assertJsonValidationErrors('mobile_image');

        $this->assertSame(0, Banner::count());
        $this->assertSame([], Storage::disk('public')->allFiles());   // nothing stored for the refused uploads
    }

    public function test_a_banner_is_edited_and_its_pictures_replaced_or_removed(): void
    {
        $this->admin();
        $id = $this->create(['mobile_image' => $this->image('m.jpg', 800, 800)])->assertCreated()->json('data.id');
        $oldDesktop = Banner::find($id)->getFirstMedia('desktop')->getPathRelativeToRoot();

        // text only: pictures stay
        $this->update($id, ['title' => 'New title', 'button_text' => 'Go', 'placement' => 'home_secondary'])->assertOk()
            ->assertJsonPath('data.title', 'New title')->assertJsonPath('data.placement', 'home_secondary')
            ->assertJsonPath('data.placement_label', Banner::PLACEMENTS['home_secondary']);
        $this->assertNotNull(Banner::find($id)->getFirstMedia('mobile'));

        // a new desktop picture replaces the old one
        $this->update($id, ['desktop_image' => $this->image('new.jpg')])->assertOk();
        $this->assertCount(1, Banner::find($id)->getMedia('desktop'));
        Storage::disk('public')->assertMissing($oldDesktop);

        // remove the mobile picture; the desktop one cannot be removed
        $this->update($id, ['remove_mobile_image' => '1'])->assertOk()->assertJsonPath('data.mobile_image_url', null);
        $this->assertCount(0, Banner::find($id)->getMedia('mobile'));
        $this->assertCount(1, Banner::find($id)->getMedia('desktop'));

        // an update does not need the picture, but a bad one is refused
        $this->update($id, ['desktop_image' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')])->assertStatus(422)->assertJsonValidationErrors('desktop_image');
        $this->update(99999, ['title' => 'x'])->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'banner.updated']);
    }

    public function test_the_end_date_is_checked_against_the_stored_start_on_updates(): void
    {
        $this->admin();
        $id = $this->create(['starts_at' => '2026-06-01 00:00:00'])->assertCreated()->json('data.id');

        $this->update($id, ['ends_at' => '2026-05-01 00:00:00'])->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $this->update($id, ['ends_at' => '2026-07-01 00:00:00'])->assertOk();
        $this->update($id, ['ends_at' => ''])->assertOk()->assertJsonPath('data.ends_at', null);   // open-ended again
    }

    public function test_a_banner_is_deactivated_and_deleted_with_its_files(): void
    {
        $this->admin();
        $id = $this->create(['mobile_image' => $this->image('m.jpg', 800, 800)])->assertCreated()->json('data.id');

        $this->patchJson(self::ADMIN."/banners/$id", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.status', 'inactive');
        $this->patchJson(self::ADMIN."/banners/$id", ['is_active' => true])->assertOk()->assertJsonPath('data.status', 'live');

        $this->assertNotEmpty(Storage::disk('public')->allFiles());
        $this->deleteJson(self::ADMIN."/banners/$id")->assertOk();
        $this->assertSame(0, Banner::count());
        $this->assertSame(0, DB::table('media')->where('model_type', Banner::class)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->deleteJson(self::ADMIN."/banners/$id")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'banner.deleted']);
    }

    // ------------------------------------------------------------------ admin: list and reorder

    public function test_the_admin_list_is_grouped_by_placement_in_display_order_with_statuses(): void
    {
        $this->admin();
        $b = $this->banner(['placement' => 'home_hero', 'title' => 'B', 'sort_order' => 2]);
        $a = $this->banner(['placement' => 'home_hero', 'title' => 'A', 'sort_order' => 1]);
        $p = $this->banner(['placement' => 'promo_strip', 'title' => 'P', 'sort_order' => 1]);
        $off = $this->banner(['placement' => 'home_secondary', 'title' => 'Off', 'is_active' => false]);
        $soon = $this->banner(['placement' => 'home_secondary', 'title' => 'Soon', 'starts_at' => now()->addDays(3)]);
        $old = $this->banner(['placement' => 'home_secondary', 'title' => 'Old', 'ends_at' => now()->subDay()]);

        $res = $this->getJson(self::ADMIN.'/banners')->assertOk();
        $this->assertSame(['home_hero', 'home_hero', 'home_secondary', 'home_secondary', 'home_secondary', 'promo_strip'], array_column($res->json('data'), 'placement'));
        $this->assertSame(['A', 'B', 'P'], collect($res->json('data'))->whereIn('placement', ['home_hero', 'promo_strip'])->pluck('title')->all());
        $status = collect($res->json('data'))->pluck('status', 'title');
        $this->assertSame(['A' => 'live', 'Off' => 'inactive', 'Soon' => 'scheduled', 'Old' => 'expired'], $status->only(['A', 'Off', 'Soon', 'Old'])->all());

        $this->assertCount(2, $this->getJson(self::ADMIN.'/banners?placement=home_hero')->json('data'));
        $this->assertSame(['Soon'], array_column($this->getJson(self::ADMIN.'/banners?status=scheduled')->json('data'), 'title'));
        $this->getJson(self::ADMIN.'/banners?placement=nope')->assertStatus(422);
        $this->getJson(self::ADMIN.'/banners/'.$a->id)->assertOk()->assertJsonPath('data.title', 'A');
        $this->getJson(self::ADMIN.'/banners/options')->assertOk()->assertJsonCount(3, 'placements')->assertJsonPath('placements.0.key', 'home_hero');
    }

    public function test_the_admin_list_does_not_run_queries_per_banner(): void
    {
        $this->admin();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::ADMIN.'/banners')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->banner();
        $few = $count();
        foreach (range(1, 6) as $i) {
            $this->banner();
        }

        $this->assertSame($few, $count());
    }

    public function test_banners_are_reordered(): void
    {
        $this->admin();
        $one = $this->banner(['title' => 'One', 'sort_order' => 1]);
        $two = $this->banner(['title' => 'Two', 'sort_order' => 2]);
        $three = $this->banner(['title' => 'Three', 'sort_order' => 3]);

        $this->putJson(self::ADMIN.'/banners/reorder', ['ids' => [$three->id, $one->id, $two->id]])->assertOk();

        $this->assertSame(['Three', 'One', 'Two'], array_column($this->getJson(self::ADMIN.'/banners?placement=home_hero')->json('data'), 'title'));
        $this->assertSame(['Three', 'One', 'Two'], array_column($this->live('?placement=home_hero')->json('data'), 'title'));   // customers get the new order

        $this->putJson(self::ADMIN.'/banners/reorder', ['ids' => []])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->putJson(self::ADMIN.'/banners/reorder', ['ids' => [$one->id, $one->id]])->assertStatus(422);
        $this->putJson(self::ADMIN.'/banners/reorder', ['ids' => [$one->id, 9999]])->assertStatus(422)->assertJsonValidationErrors('ids.1');
        $this->assertDatabaseHas('audit_logs', ['action' => 'banner.reordered']);
    }

    public function test_banner_admin_endpoints_need_an_admin(): void
    {
        $this->asGuest();
        $banner = $this->banner();

        $this->getJson(self::ADMIN.'/banners')->assertUnauthorized();
        $this->getJson(self::ADMIN.'/banners/options')->assertUnauthorized();
        $this->post(self::ADMIN.'/banners', [], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->post(self::ADMIN."/banners/{$banner->id}", ['_method' => 'PUT'], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->deleteJson(self::ADMIN."/banners/{$banner->id}")->assertUnauthorized();
        $this->putJson(self::ADMIN.'/banners/reorder', ['ids' => [$banner->id]])->assertUnauthorized();

        // a customer token is not an admin credential
        $customer = \App\Domain\Auth\Models\User::create(['full_name' => 'C', 'email' => 'c@example.test', 'mobile' => '03111111111', 'password' => 'secret-pass', 'is_active' => true]);
        Sanctum::actingAs($customer, ['*']);
        $this->getJson(self::ADMIN.'/banners')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ storefront

    public function test_the_storefront_gets_only_live_banners_of_a_placement_in_order_with_absolute_image_urls(): void
    {
        $this->banner(['title' => 'Second', 'sort_order' => 2, 'subtitle' => 'Sub', 'button_text' => 'Go', 'link_url' => '/sale']);
        $this->banner(['title' => 'First', 'sort_order' => 1]);
        $this->banner(['title' => 'Hidden', 'sort_order' => 3, 'is_active' => false]);
        $this->banner(['title' => 'Not yet', 'starts_at' => now()->addHour()]);
        $this->banner(['title' => 'Over', 'ends_at' => now()->subMinute()]);
        $this->banner(['title' => 'Other spot', 'placement' => 'promo_strip']);
        $this->banner(['title' => 'No picture'], withImage: false);
        $this->banner(['title' => 'Open window', 'sort_order' => 4, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $res = $this->live('?placement=home_hero');
        $this->assertSame(['First', 'Second', 'Open window'], array_column($res->json('data'), 'title'));
        $first = $res->json('data.1');
        $this->assertSame(['id', 'placement', 'title', 'subtitle', 'button_text', 'link_url', 'image_url', 'mobile_image_url'], array_keys($first));
        $this->assertSame(['Sub', 'Go', '/sale', 'home_hero'], [$first['subtitle'], $first['button_text'], $first['link_url'], $first['placement']]);
        $this->assertStringStartsWith('http', $first['image_url']);
        $this->assertNull($first['mobile_image_url']);

        // one banner only: still a list (the storefront decides between a single banner and a slider)
        $this->assertCount(1, $this->live('?placement=promo_strip')->json('data'));
    }

    public function test_a_mobile_picture_is_returned_as_an_absolute_url(): void
    {
        $banner = $this->banner();
        $banner->addMedia($this->image('m.jpg', 800, 800))->toMediaCollection('mobile');

        $this->live('?placement=home_hero')
            ->assertJsonPath('data.0.id', $banner->id)
            ->assertJsonPath('data.0.mobile_image_url', fn ($url) => str_starts_with($url, 'http') && str_contains($url, 'm.jpg'));
    }

    public function test_without_a_placement_every_live_banner_is_returned_and_an_unknown_one_is_refused(): void
    {
        $this->banner(['placement' => 'promo_strip', 'title' => 'Strip']);
        $this->banner(['placement' => 'home_hero', 'title' => 'Hero']);
        $this->banner(['placement' => 'home_hero', 'title' => 'Off', 'is_active' => false]);

        $this->assertSame(['Hero', 'Strip'], array_column($this->live()->json('data'), 'title'));
        $this->getJson(self::STORE.'/banners?placement=nope')->assertStatus(422)->assertJsonValidationErrors('placement');
        $this->assertSame([], $this->live('?placement=home_secondary')->json('data'));   // nothing live: an empty list, not an error
    }

    public function test_the_storefront_banners_endpoint_is_public_and_light(): void
    {
        $this->asGuest();
        foreach (range(1, 5) as $i) {
            $this->banner();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->live('?placement=home_hero')->assertJsonCount(5, 'data');
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));   // banners + their media
    }

    public function test_a_banner_that_expires_or_starts_disappears_and_appears_by_time(): void
    {
        $banner = $this->banner(['starts_at' => now()->addHour(), 'ends_at' => now()->addDays(2)]);
        $this->assertSame([], $this->live('?placement=home_hero')->json('data'));

        $this->travel(2)->hours();
        $this->assertSame([$banner->id], array_column($this->live('?placement=home_hero')->json('data'), 'id'));

        $this->travel(3)->days();
        $this->assertSame([], $this->live('?placement=home_hero')->json('data'));
    }
}
