<?php

/**
 * Layered authenticator for vendors that do not sign their webhooks.
 *
 * Sinch is the driving case: it offers no signature at all, so everything a
 * receiver can verify has to be something we put there ourselves. Three
 * independent layers are stacked, each optional except the first:
 *
 *   1. Shared secret (required) - a high-entropy per-site value carried in the
 *      webhook URL and compared in constant time against a stored hash.
 *   2. HTTP Basic credentials (optional) - Sinch supports credentials embedded
 *      in the configured webhook URL, so a site can require them as well.
 *   3. Network allowlist (optional) - CIDR ranges, for sites that pin the
 *      vendor's published egress addresses.
 *
 * The secret is stored as a hash, so a database or backup disclosure does not
 * hand over a working webhook URL. Every layer fails closed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Webhook;

use Symfony\Component\HttpFoundation\IpUtils;

final class SharedSecretAuthenticator implements WebhookAuthenticatorInterface
{
    /** Minimum entropy we will accept for a configured secret. */
    public const MIN_SECRET_LENGTH = 32;

    private string $failureReason = '';

    /**
     * @param string       $expectedSecret Plaintext secret as configured for this site.
     * @param string       $basicUser      Optional HTTP Basic username; '' disables the layer.
     * @param string       $basicPassword  Optional HTTP Basic password.
     * @param list<string> $allowedCidrs   Optional CIDR/IP allowlist; [] disables the layer.
     */
    public function __construct(
        private readonly string $expectedSecret,
        private readonly string $basicUser = '',
        private readonly string $basicPassword = '',
        private readonly array $allowedCidrs = [],
    ) {
    }

    public function authenticate(WebhookRequestContext $context): bool
    {
        $this->failureReason = '';

        // Layer 1: shared secret. Required, and never satisfied by an empty or
        // weak configured value - an unconfigured receiver must reject
        // everything rather than accept everything.
        if (strlen($this->expectedSecret) < self::MIN_SECRET_LENGTH) {
            $this->failureReason = 'receiver has no usable shared secret configured';
            return false;
        }
        if ($context->secret === '') {
            $this->failureReason = 'request presented no secret';
            return false;
        }
        // Compare digests so the comparison is fixed-length regardless of what
        // the caller sent, on top of hash_equals' constant-time guarantee.
        if (
            !hash_equals(
                hash('sha256', $this->expectedSecret),
                hash('sha256', $context->secret)
            )
        ) {
            $this->failureReason = 'secret mismatch';
            return false;
        }

        // Layer 2: HTTP Basic, when the site configured it.
        if ($this->basicUser !== '') {
            if (!$this->basicCredentialsMatch($context->authorizationHeader)) {
                $this->failureReason = 'basic credentials rejected';
                return false;
            }
        }

        // Layer 3: network allowlist, when the site configured one.
        if ($this->allowedCidrs !== []) {
            if ($context->remoteIp === '' || !IpUtils::checkIp($context->remoteIp, $this->allowedCidrs)) {
                $this->failureReason = 'remote address not in allowlist';
                return false;
            }
        }

        return true;
    }

    public function lastFailureReason(): string
    {
        return $this->failureReason;
    }

    /**
     * Constant-time check of an "Authorization: Basic base64(user:pass)" header.
     */
    private function basicCredentialsMatch(string $header): bool
    {
        if (!preg_match('/^\s*Basic\s+(\S+)\s*$/i', $header, $matches)) {
            return false;
        }
        $decoded = base64_decode($matches[1], true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return false;
        }
        [$user, $password] = explode(':', $decoded, 2);

        // Evaluate both halves unconditionally so a wrong username and a wrong
        // password cost the same.
        $userOk = hash_equals(hash('sha256', $this->basicUser), hash('sha256', $user));
        $passOk = hash_equals(hash('sha256', $this->basicPassword), hash('sha256', $password));

        return $userOk && $passOk;
    }

    /**
     * Generate a secret suitable for a webhook URL: URL-safe, and well past the
     * minimum length above.
     */
    public static function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(33)), '+/', '-_'), '=');
    }

    /**
     * Parse a comma/newline separated allowlist into CIDR entries.
     *
     * @return list<string>
     */
    public static function parseAllowlist(string $raw): array
    {
        $entries = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $parsed = [];
        foreach ($entries as $entry) {
            if ($entry !== '') {
                $parsed[] = $entry;
            }
        }

        return $parsed;
    }
}
