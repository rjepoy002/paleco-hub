<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
date_default_timezone_set('Asia/Manila');
if (is_file(ROOT.'/vendor/autoload.php')) {
    require ROOT.'/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(ROOT)->safeLoad();
} else {
    spl_autoload_register(function ($class) { $file = ROOT.'/'.str_replace('\\','/',lcfirst($class)).'.php'; if (is_file($file)) require $file; });
}
function env(string $key, string $default = ''): string { $system=getenv($key); return $system!==false ? $system : (string)($_ENV[$key] ?? $default); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = ''): string { return rtrim(env('APP_URL','http://localhost/paleco-hub'),'/').$path; }
function go(string $path): never { header('Location: '.url($path)); exit; }
function csrf(): string { return '<input type="hidden" name="_token" value="'.e($_SESSION['csrf']).'">'; }
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    // Chromium also checks form-action on the OAuth POST's redirect to Google.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://accounts.google.com");
    session_name('PALECOHUB');
    session_set_cookie_params(['lifetime'=>0,'path'=>parse_url(url(),PHP_URL_PATH) ?: '/', 'secure'=>str_starts_with(url(),'https://'),'httponly'=>true,'samesite'=>'Lax']);
    ini_set('session.use_strict_mode','1');
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
