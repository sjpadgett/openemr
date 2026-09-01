<?php

/**
 * The parts of an inbound webhook request an authenticator is allowed to see.
 *
 * Kept as an explicit value object rather than a Symfony Request so the
 * authenticators stay trivially testable and can never reach past what they
 * were handed - no superglobals, no session, no request-wide surface.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Webhook;

final readonly class WebhookRequestContext
{
    /**
     * @param string $secret               Shared secret presented by the caller (query parameter).
     * @param string $authorizationHeader  Raw Authorization header, if any.
     * @param string $remoteIp             Socket peer address.
     * @param string $contentType          Request Content-Type.
     * @param string $rawBody              Raw request body (empty for multipart, which PHP consumes).
     * @param array<string, mixed> $formFields Parsed multipart fields, when the request was multipart.
     * @param array<string, mixed> $files      Parsed multipart file entries, when the request was multipart.
     */
    public function __construct(
        public string $secret = '',
        public string $authorizationHeader = '',
        public string $remoteIp = '',
        public string $contentType = '',
        public string $rawBody = '',
        public array $formFields = [],
        public array $files = [],
    ) {
    }

    /** The media type with any RFC 7231 parameters stripped. */
    public function mediaType(): string
    {
        return strtolower(trim(explode(';', $this->contentType)[0]));
    }

    public function isJson(): bool
    {
        return str_contains($this->mediaType(), 'json');
    }
}
