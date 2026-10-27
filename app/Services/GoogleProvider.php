<?php
namespace App\Services;
final class GoogleProvider extends \League\OAuth2\Client\Provider\Google {
    protected function getPkceMethod(): string { return self::PKCE_METHOD_S256; }
}
