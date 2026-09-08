#!/usr/bin/env php
<?php
/**
 * Business Central sales-order end-to-end test.
 *
 *   1. POST   create a sales order (with lines)
 *   2. PUT    update the sales order header (shipping/payment/etc.)
 *   3. POST   release the order (Microsoft.NAV.releaseOrder)
 *   4. On success, email the SO number + a BC deep link via Amazon SES.
 *
 * Token is fetched via the Azure AD client-credentials flow (app-only),
 * so nothing here expires between runs — just keep BC_CLIENT_SECRET set.
 *
 * Usage:   php bc_order_test.php
 * Config:  copy .env.example -> .env and fill in the blanks.
 */

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/* --------------------------------------------------------------------- */
/* Tiny helpers                                                          */
/* --------------------------------------------------------------------- */

function loadDotEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        // Strip optional surrounding quotes.
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

function requireEnv(string $key): string
{
    $v = env($key);
    if ($v === null) {
        fail("Missing required config: $key (set it in .env or the environment).");
    }
    return $v;
}

function step(string $msg): void
{
    fwrite(STDOUT, "\n\033[1;36m▶ $msg\033[0m\n");
}

function info(string $msg): void
{
    fwrite(STDOUT, "  $msg\n");
}

function ok(string $msg): void
{
    fwrite(STDOUT, "  \033[0;32m✓ $msg\033[0m\n");
}

function fail(string $msg): never
{
    fwrite(STDERR, "\n\033[0;31m✗ $msg\033[0m\n");
    exit(1);
}

/**
 * Perform an HTTP request with cURL. Returns [statusCode, decodedBody, rawBody].
 * On any non-2xx status, prints the response and aborts (unless $tolerate given).
 */
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null, array $tolerate = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw    = curl_exec($ch);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        fail("cURL error ($errno): $error");
    }

    $decoded = null;
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
    }

    $isOk = $status >= 200 && $status < 300;
    if (!$isOk && !in_array($status, $tolerate, true)) {
        $detail = $raw;
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $detail = $decoded['error']['code'] ?? '';
            $detail = trim($detail . ' — ' . $decoded['error']['message'], ' —');
        }
        fail("HTTP $status from $method $url\n  $detail");
    }

    return [$status, $decoded, $raw];
}

/* --------------------------------------------------------------------- */
/* Config                                                                */
/* --------------------------------------------------------------------- */

loadDotEnv(__DIR__ . '/.env');

$tenant      = requireEnv('BC_TENANT_ID');
$environment = requireEnv('BC_ENVIRONMENT');
$companyId   = requireEnv('BC_COMPANY_ID');
$apiPath     = requireEnv('BC_API_PATH');          // e.g. greatLakesDental/webAPI/v1.0
$clientId    = requireEnv('BC_CLIENT_ID');
$clientSecret = requireEnv('BC_CLIENT_SECRET');

$currency    = env('BC_CURRENCY_CODE', 'USD');
$customerNo  = env('BC_CUSTOMER_NUMBER', 'C0100008');
$extDocPrefix = env('BC_EXTERNAL_DOC_PREFIX', 'ADAMTEST-PLEASEDELETE');
$companyName = env('BC_COMPANY_NAME');

$apiBase = sprintf(
    'https://api.businesscentral.dynamics.com/v2.0/%s/%s/api/%s/companies(%s)',
    $tenant,
    rawurlencode($environment),
    $apiPath,
    $companyId
);

/* --------------------------------------------------------------------- */
/* 0. Get an access token (client credentials)                           */
/* --------------------------------------------------------------------- */

step('Requesting access token (client credentials)');

$tokenUrl = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token";
[, $tok] = httpRequest('POST', $tokenUrl,
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'scope'         => 'https://api.businesscentral.dynamics.com/.default',
    ])
);
$accessToken = $tok['access_token'] ?? null;
if (!$accessToken) {
    fail('No access_token in token response.');
}
ok('Token acquired.');

$authHeaders = [
    "Authorization: Bearer $accessToken",
    'Content-Type: application/json',
];

/* --------------------------------------------------------------------- */
/* 1. Create the sales order                                             */
/* --------------------------------------------------------------------- */

step('Step 1 — Creating sales order');

$externalDocNo = $extDocPrefix . '-' . date('YmdHis');

$orderPayload = [
    'currencyCode'           => $currency,
    'customerNumber'         => $customerNo,
    'externalDocumentNumber' => $externalDocNo,
    'salesOrderLines'        => [
        [
            'accountId'    => '09056b12-208f-425f-8112-7387ae12bf02',
            'description'  => 'UPS - UPS Ground',
            'lineType'     => 'Account',
            'locationCode' => 'MAIN',
            'quantity'     => 1,
            'unitPrice'    => 16.66,
        ],
        [
            'discountAmount' => 0,
            'itemId'         => '9a2eb3bc-4a58-ec11-9f09-000d3a5e096f',
            'lineType'       => 'Item',
            'locationCode'   => 'MAIN',
            'quantity'       => 10,
            'unitPrice'      => 7.50,
        ],
        [
            'accountId'    => 'db4f7bca-9d9e-4caa-a6b7-c0252921fd63',
            'description'  => 'Tariff surcharges',
            'lineType'     => 'Account',
            'locationCode' => 'MAIN',
            'quantity'     => 1,
            'unitPrice'    => 4.44,
        ],
    ],
    'shippingPostalAddress'  => [
        'city'              => 'Tonawanda',
        'countryLetterCode' => 'US',
        'postalCode'        => '14150',
        'state'             => 'NY',
        'street'            => '200 Cooper Ave',
    ],
];

$createUrl = $apiBase . '/salesOrders?$expand=salesOrderLines';
[, $order] = httpRequest('POST', $createUrl, $authHeaders, json_encode($orderPayload));

$soNumber = $order['number'] ?? null;
$soId     = $order['id'] ?? null;
if (!$soNumber) {
    fail("Order created but no 'number' returned:\n" . json_encode($order, JSON_PRETTY_PRINT));
}
ok("Sales order created: $soNumber (externalDoc: $externalDocNo)");

/* --------------------------------------------------------------------- */
/* 2. Update the sales order header                                      */
/* --------------------------------------------------------------------- */

step("Step 2 — Updating header for $soNumber");

// Key: salesOrderHeaders('Order', '<number>')
$headerKey = sprintf("('Order',%%20'%s')", rawurlencode($soNumber));
$headerUrl = $apiBase . '/salesOrderHeaders' . $headerKey;

$headerPayload = [
    'shippingAgentCode'        => 'UPS',
    'shippingAgentServiceCode' => 'GND',
    'paymentMethodCode'        => 'WEB',
    'divisionCode'             => '02',
    'salesSource'              => 'Ecommerce',
    'locationCode'             => 'MAIN',
    'paymentTermsCode'         => 'cc',
];

httpRequest('PUT', $headerUrl,
    array_merge($authHeaders, ['If-Match: *']),
    json_encode($headerPayload)
);
ok('Header updated.');

/* --------------------------------------------------------------------- */
/* 3. Release the order                                                  */
/* --------------------------------------------------------------------- */

step("Step 3 — Releasing $soNumber");

$releaseUrl = $apiBase . '/salesOrderHeaders' . $headerKey . '/Microsoft.NAV.releaseOrder';
httpRequest('POST', $releaseUrl,
    ["Authorization: Bearer $accessToken", 'If-Match: *', 'Content-Length: 0'],
    ''
);
ok('Order released.');

/* --------------------------------------------------------------------- */
/* 4. Send success email via SES                                         */
/* --------------------------------------------------------------------- */

step('Step 4 — Sending confirmation email');

// BC deep link to the Sales Order card (page 42), filtered to this order.
$deepLink = sprintf(
    'https://businesscentral.dynamics.com/%s/%s?%spage=42&filter=%s',
    $tenant,
    rawurlencode($environment),
    $companyName ? 'company=' . rawurlencode($companyName) . '&' : '',
    rawurlencode("'No.' IS '$soNumber'")
);

$from = env('SES_FROM');
$to   = env('SES_TO');

if (!$from || !$to) {
    info('SES_FROM / SES_TO not set — skipping email.');
    info("SO: $soNumber");
    info("Link: $deepLink");
    ok("Done (email skipped). Sales order $soNumber released.");
    exit(0);
}

$recipients = array_values(array_filter(array_map('trim', explode(',', $to))));

// Tests performed, in order — mirrors the steps run above.
$testsPerformed = [
    'Created a new sales order (with account + item lines and a shipping address)',
    'Updated the sales order header (shipping agent, payment method/terms, division, sales source, location)',
    'Released the sales order',
];

$intro = 'An API test was requested for a new Business Central version. The following tests were performed:';

$subject = "Business Central API test — Sales Order $soNumber";

$textBody =
    "$intro\n\n" .
    implode("\n", array_map(fn($t) => "  • $t", $testsPerformed)) . "\n\n" .
    "Please verify the sales order below.\n\n" .
    "Sales Order:      $soNumber\n" .
    "External Doc No.: $externalDocNo\n" .
    "Environment:      $environment\n\n" .
    "Open in Business Central:\n$deepLink\n";

$htmlBody =
    '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222">' .
    '<p>' . htmlspecialchars($intro) . '</p>' .
    '<ul>' .
    implode('', array_map(fn($t) => '<li>' . htmlspecialchars($t) . '</li>', $testsPerformed)) .
    '</ul>' .
    '<p>Please verify the sales order below.</p>' .
    '<table cellpadding="4" style="font-family:Arial,sans-serif;font-size:14px;border-collapse:collapse">' .
    '<tr><td><b>Sales Order</b></td><td>' . htmlspecialchars($soNumber) . '</td></tr>' .
    '<tr><td><b>External Doc No.</b></td><td>' . htmlspecialchars($externalDocNo) . '</td></tr>' .
    '<tr><td><b>Environment</b></td><td>' . htmlspecialchars($environment) . '</td></tr>' .
    '</table>' .
    '<p><a href="' . htmlspecialchars($deepLink) . '">Open ' . htmlspecialchars($soNumber) . ' in Business Central</a></p>' .
    '</div>';

$smtpUser = requireEnv('SES_SMTP_USERNAME');
$smtpPass = requireEnv('SES_SMTP_PASSWORD');
$smtpHost = env('SES_SMTP_HOST', 'email-smtp.us-east-1.amazonaws.com');
$smtpPort = (int) env('SES_SMTP_PORT', '587');

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->CharSet    = PHPMailer::CHARSET_UTF8;
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->Port       = $smtpPort;
    // 587 -> STARTTLS, 465 -> implicit TLS.
    $mail->SMTPSecure = $smtpPort === 465
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

    $mail->setFrom($from);
    foreach ($recipients as $rcpt) {
        $mail->addAddress($rcpt);
    }
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();
    ok('Email sent via SES SMTP to ' . implode(', ', $recipients) . '.');
} catch (MailException $e) {
    fail('SES SMTP send failed: ' . $mail->ErrorInfo);
}

ok("Done. Sales order $soNumber created, released, and emailed.");
info("Link: $deepLink");
