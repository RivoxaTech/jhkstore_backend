<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Once `php artisan config:cache` has run (the go-live steps do it), Laravel no longer reads `.env` and the env helper returns only its default anywhere
 * outside the config folder. Code that used it there (the admin seeder, two rate limits) silently ran with defaults: on the live host the seeder refused its own
 * password. Every setting that comes from `.env` is read in `config/*.php` and used through `config()`.
 */
class NoEnvOutsideConfigTest extends TestCase
{
    public function test_nothing_but_the_config_files_reads_the_environment(): void
    {
        $found = [];
        foreach (['app', 'database', 'routes', 'resources'] as $folder) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($folder), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! preg_match('/\.php$/', $file->getFilename())) {
                    continue;
                }
                foreach (file($file->getPathname()) as $number => $line) {
                    // a call of the helper: not `.env (` in a message, not a method called env
                    if (preg_match('/(?<![\w.>:$\x27"])env\s*\(\s*[\x27"]/', $line) && ! preg_match('/^\s*(\/\/|\*|#)/', $line)) {
                        $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($number + 1);
                    }
                }
            }
        }

        $this->assertSame([], $found, 'Read the value in a config file and use config(): the environment is not available here after config:cache.');
    }
}
