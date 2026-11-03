<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** The API reference carries no client name and no local address: the title comes from APP_NAME and the server from APP_URL. */
class DocsTest extends TestCase
{
    public function test_the_export_takes_the_title_from_app_name_and_the_server_from_app_url(): void
    {
        config(['app.name' => 'Acme Shop', 'app.url' => 'https://acme.test']);
        URL::forceRootUrl('https://acme.test');   // what the boot does with APP_URL
        URL::forceScheme('https');
        $path = sys_get_temp_dir().'/openapi-'.uniqid().'.json';

        Artisan::call('scramble:export', ['--path' => $path]);
        $doc = json_decode((string) file_get_contents($path), true);
        @unlink($path);

        $this->assertSame('Acme Shop', $doc['info']['title']);
        $this->assertSame('https://acme.test/api', $doc['servers'][0]['url']);
    }

    public function test_the_committed_reference_and_postman_collection_name_no_client_and_no_local_address(): void
    {
        foreach (['docs/openapi.json', 'docs/postman/Store-API.postman_collection.json'] as $file) {
            $text = (string) file_get_contents(base_path($file));
            $this->assertStringNotContainsString('JHK', $text, "$file names a client");
            $this->assertStringNotContainsString('localhost:8000', $text, "$file has a local address");
            $this->assertStringNotContainsString('127.0.0.1', $text, "$file has a local address");
        }
    }
}
