<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

use function Illuminate\Support\php_binary;

/**
 * `php artisan serve` for LOCAL development, made fast and complete (the same name replaces Laravel's own command; on any other environment it
 * behaves exactly like Laravel's):
 *
 *  - PHP's OPcache is switched on for the built-in server. Without it (the command-line PHP of Laragon has it off) every request compiles the whole
 *    framework again: 0.4 s for the smallest answer instead of 0.06 s. Pages that need four answers took seconds.
 *  - A queue listener and the scheduler are started next to it, so the e-mails and push notifications of an order are sent and the "Queue worker"
 *    card of Settings > Notifications is green, without opening more terminals. (`--no-helpers` leaves them out.)
 *
 * Production (Apache / Nginx with PHP-FPM) never uses this command.
 */
#[AsCommand(name: 'serve')]
class Serve extends ServeCommand
{
    /** @var array<string, Process> */
    private array $helpers = [];

    public function __construct()
    {
        $this->signature .= "\n {--no-helpers : Do not start the queue listener and the scheduler}\n {--no-opcache : Do not switch OPcache on}";
        parent::__construct();
    }

    public function handle()
    {
        if ($this->local() && ! $this->option('no-helpers')) {
            $this->startHelpers();
        }

        return parent::handle();
    }

    protected function serverCommand()
    {
        $command = parent::serverCommand();
        if (! $this->local() || $this->option('no-opcache') || ! $this->opcacheAvailable()) {
            return $command;
        }
        $php = array_shift($command);

        return [$php, '-d', 'zend_extension=php_opcache.dll', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=1',
            '-d', 'opcache.revalidate_freq=2', '-d', 'opcache.memory_consumption=128', ...$command];
    }

    private function local(): bool
    {
        return app()->environment('local');
    }

    /** The extension file sits next to the other extensions of this PHP (Windows builds); on Linux OPcache is a normal part of PHP and usually already on. */
    private function opcacheAvailable(): bool
    {
        if (extension_loaded('Zend OPcache')) {
            return false;   // already loaded by php.ini: nothing to add
        }
        $dir = (string) ini_get('extension_dir');

        return $dir !== '' && is_file(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'php_opcache.dll');
    }

    private function startHelpers(): void
    {
        $artisan = base_path('artisan');
        $php = php_binary();
        $wanted = [
            'queue' => [$php, $artisan, 'queue:listen', '--tries=2', '--sleep=2', '--timeout=90'],
            'scheduler' => [$php, $artisan, 'schedule:work'],
        ];
        foreach ($wanted as $name => $command) {
            if (isset($this->helpers[$name]) && $this->helpers[$name]->isRunning()) {
                continue;
            }
            $process = new Process($command, base_path(), null, null, null);
            $process->disableOutput();   // nobody reads it: a full pipe would freeze the helper
            $process->start();
            $this->helpers[$name] = $process;
        }
        $this->components->info('Queue listener and scheduler started (mails and push notifications are sent; stop them with Ctrl+C).');
        register_shutdown_function(function () {
            foreach ($this->helpers as $process) {
                if ($process->isRunning()) {
                    $process->stop(2);
                }
            }
        });
        // one heartbeat now, so the panel shows "running" at once instead of after the first minute
        $beat = new Process([$php, $artisan, 'shop:queue-heartbeat'], base_path());
        $beat->disableOutput();
        $beat->start();
    }
}
