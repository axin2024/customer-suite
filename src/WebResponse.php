<?php

declare(strict_types=1);

namespace CustomerSuite;

final class WebResponse
{
    public static function headers(): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'; form-action 'self'");
    }

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo Input::json($payload);
        exit;
    }

    public static function redirect(array $query = []): never
    {
        header('Location: /' . ($query === [] ? '' : '?' . http_build_query($query)), true, 303);
        exit;
    }
}
