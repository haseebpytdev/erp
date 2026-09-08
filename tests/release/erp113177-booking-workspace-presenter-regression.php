<?php

declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {
    final class HeaderBag { public function get(string $name, string $default = ''): string { return $name === 'content-type' ? 'text/html' : $default; } }
    class Response { public HeaderBag $headers; public function __construct(private string $content) { $this->headers = new HeaderBag(); } public function getContent(): string { return $this->content; } public function setContent(string $content): void { $this->content = $content; } }
}

namespace Illuminate\Http {
    class Request { public function __construct(private string $uri) {} public function path(): string { return $this->uri; } }
}

namespace App\Services\Operations {
    final class BookingEditLockResolver { public function resolve(int $booking): array { return ['locked' => false, 'reason' => '']; } }
}

namespace {
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function route(string $name): string { return '/__test/'.$name; }
    function url(string $path): string { return $path; }

    require dirname(__DIR__, 2).'/app/Services/Operations/BookingWorkspaceShellPresenter.php';

    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$checks): void { if (! $condition) throw new RuntimeException($message); $checks++; };
    $request = new \Illuminate\Http\Request('operations/bookings/13');
    $response = new \Symfony\Component\HttpFoundation\Response('<html><head></head><body><section class="content"><span>GENERAL</span><div data-booking-workspace="1">Booking</div></section></body></html>');
    $presenter = new \App\Services\Operations\BookingWorkspaceShellPresenter(new \App\Services\Operations\BookingEditLockResolver());
    $rendered = $presenter->transform($request, $response)->getContent();

    $assert(str_contains($rendered, 'et-general-progressive-step1-11390'), 'GENERAL booking transform completes');
    $assert(str_contains($rendered, 'data-et-general-progressive-js="ERP-11.3.138"'), 'GENERAL booking GET receives the product workspace asset');
    $assert(str_contains($rendered, 'data-et-booking-review-entry="1"'), 'booking GET render completes through Review entry injection');
    $assert(! str_contains($rendered, 'injectGeneralTransportSelectionBootstrap'), 'removed Transport bootstrap is not emitted or called');
    echo "ERP-11.3.177 Booking workspace presenter: {$checks} assertions passed.\n";
}
