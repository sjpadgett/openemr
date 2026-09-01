<?php

/**
 * Inbound fax webhook endpoint.
 *
 * Login-less by necessity: the caller is a fax vendor, not a browser, so there
 * is no OpenEMR session and no CSRF token to check. Access is gated entirely by
 * the vendor's configured authenticator - for Sinch a shared secret in the URL,
 * optional HTTP Basic credentials and an optional CIDR allowlist, since Sinch
 * does not sign its callbacks at all.
 *
 * Only the vendor a site has actually enabled for fax, and only while that
 * vendor is in webhook ingest mode, is reachable here. A site running the
 * default polling mode exposes nothing: the endpoint answers 404 as though it
 * did not exist.
 *
 * The response body is deliberately empty - a webhook client gets a status code
 * and nothing else, so a prober cannot tell a wrong secret from a wrong site
 * from an unconfigured vendor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Login-less: access is gated entirely by the vendor authenticator. Locate
// globals.php by walking up to the interface/ bootstrap so this works
// regardless of how deep the file sits in the module.
$ignoreAuth = true;
$oeBootstrap = __DIR__;
for ($oeUp = 0; $oeUp < 8 && !is_file($oeBootstrap . '/globals.php'); $oeUp++) {
    $oeBootstrap = dirname($oeBootstrap);
}
require_once($oeBootstrap . '/globals.php');

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Modules\FaxSMS\Controller\SinchFaxClient;
use OpenEMR\Modules\FaxSMS\Webhook\InboundFaxReceiver;
use OpenEMR\Modules\FaxSMS\Webhook\WebhookRequestContext;

// Keep the body empty and free of stray notices.
ini_set('display_errors', '0');

/**
 * Answer with a bare status code and stop. No body, ever: the caller is a
 * machine and a prober must not be able to distinguish failure modes.
 */
function oeFaxWebhookRespond(int $status): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Length: 0');
    header('Cache-Control: no-store');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    oeFaxWebhookRespond(405);
}

// Bound what we are willing to read before touching it. A fax document is
// large but not unbounded, and the vendor is not trusted to be well-behaved.
$maxBytes = 60 * 1024 * 1024;
$declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declaredLength > $maxBytes) {
    oeFaxWebhookRespond(413);
}

$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isMultipart = str_contains(strtolower($contentType), 'multipart/');

// PHP has already consumed a multipart body into $_POST/$_FILES; anything else
// has to be read from the input stream, under the same cap.
$rawBody = '';
if (!$isMultipart) {
    $rawBody = (string)file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if (strlen($rawBody) > $maxBytes) {
        oeFaxWebhookRespond(413);
    }
}

$context = new WebhookRequestContext(
    secret: (string)($_GET['secret'] ?? ''),
    authorizationHeader: (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
    remoteIp: (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    contentType: $contentType,
    rawBody: $rawBody,
    formFields: $isMultipart && is_array($_POST) ? $_POST : [],
    files: $isMultipart && is_array($_FILES) ? $_FILES : [],
);

try {
    // Only the site's enabled fax vendor can build a receiver, and only in
    // webhook mode. Everything else is indistinguishable from "no endpoint".
    $receiver = SinchFaxClient::createWebhookReceiver();
    if (!$receiver instanceof InboundFaxReceiver) {
        oeFaxWebhookRespond(404);
    }

    $result = $receiver->handle($context);
} catch (Throwable $e) {
    ServiceContainer::getLogger()->error('Inbound fax webhook endpoint failed', ['exception' => $e]);
    oeFaxWebhookRespond(500);
}

// A duplicate is a success from the vendor's point of view: acknowledging it
// stops the retry loop that would otherwise keep replaying the same fax.
oeFaxWebhookRespond(match ($result) {
    InboundFaxReceiver::RESULT_ACCEPTED,
    InboundFaxReceiver::RESULT_DUPLICATE,
    InboundFaxReceiver::RESULT_IGNORED => 204,
    InboundFaxReceiver::RESULT_UNAUTHORIZED => 403,
    InboundFaxReceiver::RESULT_BAD_REQUEST => 400,
    default => 500,
});
