<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 *
 * Router for `php -S` in .github/workflows/ucp-official.yml. Serves one
 * generated profile at /.well-known/ucp with the headers the module's
 * controller sends (Controller\WellKnown\Ucp, covered by UcpTest), so the
 * official conformance suite can run its core tier without a Magento.
 *
 *   UCP_PROFILE=build/ucp/checkout.json php -S 127.0.0.1:8182 Test/Conformance/server.php
 */

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path !== '/.well-known/ucp') {
    http_response_code(404);
    header('Content-Type: application/json');
    file_put_contents('php://output', '{"error":"not_found"}');
    return true;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    return true;
}

$file = (string) getenv('UCP_PROFILE');
if ($file === '' || !is_file($file)) {
    http_response_code(500);
    file_put_contents('php://output', 'UCP_PROFILE is not set to a generated profile.');
    return true;
}

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($file);

return true;
