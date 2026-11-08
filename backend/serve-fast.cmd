@echo off
rem The Laravel API for LOCAL development, about 8 times faster than "php artisan serve" on Windows.
rem
rem Why it is faster: PHP's OPcache is off in the command-line PHP of Laragon (every request then compiles the whole framework again, 0.4 s for
rem a tiny answer), and the built-in server answers one request at a time. This script switches OPcache on and starts 4 workers (the cheap
rem answers of a page, like the product list, the banners and the settings, are then served at the same time).
rem
rem Usage:   serve-fast.cmd            (port 8002)
rem          serve-fast.cmd 8010       (another port)
rem Stop it with Ctrl+C. Code changes are picked up within 2 seconds; a change of .env needs a restart.
rem Production (Apache / Nginx + PHP-FPM) does not need this: OPcache is on there.
setlocal
set PORT=%1
if "%PORT%"=="" set PORT=8002
set PHP_CLI_SERVER_WORKERS=4
cd /d "%~dp0public"
echo API on http://127.0.0.1:%PORT%  (OPcache on, 4 workers). Stop with Ctrl+C.
php -d zend_extension=php_opcache.dll -d opcache.enable_cli=1 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=2 -d opcache.memory_consumption=128 -S 127.0.0.1:%PORT% ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
