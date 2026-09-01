<?php

/**
 * Tests for the layered webhook authenticator.
 *
 * This class is the whole security boundary for a vendor that does not sign its
 * callbacks, so the cases below pin the properties that matter rather than just
 * the happy path: it must fail closed when unconfigured, reject a weak secret
 * outright, and treat each optional layer as an independent veto.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\FaxSMS\Webhook;

use Composer\Autoload\ClassLoader;
use OpenEMR\Modules\FaxSMS\Webhook\SharedSecretAuthenticator;
use OpenEMR\Modules\FaxSMS\Webhook\WebhookRequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharedSecretAuthenticatorTest extends TestCase
{
    private const SECRET = 'a-good-long-webhook-secret-value-0123456789';

    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (!$loader instanceof ClassLoader) {
            self::fail('Composer ClassLoader not available to register module autoload prefix.');
        }
        $loader->addPsr4(
            'OpenEMR\\Modules\\FaxSMS\\',
            dirname(__DIR__, 6) . '/interface/modules/custom_modules/oe-module-faxsms/src/'
        );
    }

    public function testCorrectSecretAloneIsAccepted(): void
    {
        $auth = new SharedSecretAuthenticator(self::SECRET);

        self::assertTrue($auth->authenticate(new WebhookRequestContext(secret: self::SECRET)));
        self::assertSame('', $auth->lastFailureReason());
    }

    public function testWrongSecretIsRejected(): void
    {
        $auth = new SharedSecretAuthenticator(self::SECRET);

        self::assertFalse($auth->authenticate(new WebhookRequestContext(secret: 'nope')));
        self::assertStringContainsString('mismatch', $auth->lastFailureReason());
    }

    public function testMissingSecretIsRejected(): void
    {
        $auth = new SharedSecretAuthenticator(self::SECRET);

        self::assertFalse($auth->authenticate(new WebhookRequestContext()));
    }

    /**
     * An unconfigured receiver must reject everything, not accept everything —
     * the difference between a disabled endpoint and an open one.
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     *
     * @return array<string, array{string}>
     */
    public static function unusableSecretProvider(): array
    {
        return [
            'empty' => [''],
            'far too short' => ['abc'],
            'one below the minimum' => [str_repeat('x', SharedSecretAuthenticator::MIN_SECRET_LENGTH - 1)],
        ];
    }

    #[DataProvider('unusableSecretProvider')]
    public function testUnconfiguredOrWeakSecretFailsClosed(string $configured): void
    {
        $auth = new SharedSecretAuthenticator($configured);

        // Even a caller echoing the configured value back is refused.
        self::assertFalse($auth->authenticate(new WebhookRequestContext(secret: $configured)));
        self::assertStringContainsString('no usable shared secret', $auth->lastFailureReason());
    }

    public function testBasicAuthLayerIsAnIndependentVeto(): void
    {
        $auth = new SharedSecretAuthenticator(self::SECRET, 'hookuser', 'hookpass');
        $header = 'Basic ' . base64_encode('hookuser:hookpass');

        self::assertTrue($auth->authenticate(
            new WebhookRequestContext(secret: self::SECRET, authorizationHeader: $header)
        ));

        // Right secret, wrong password: still refused.
        self::assertFalse($auth->authenticate(new WebhookRequestContext(
            secret: self::SECRET,
            authorizationHeader: 'Basic ' . base64_encode('hookuser:wrong')
        )));
        self::assertStringContainsString('basic credentials', $auth->lastFailureReason());

        // Right secret, no header at all.
        self::assertFalse($auth->authenticate(new WebhookRequestContext(secret: self::SECRET)));
    }

    public function testMalformedAuthorizationHeaderIsRejected(): void
    {
        $auth = new SharedSecretAuthenticator(self::SECRET, 'hookuser', 'hookpass');

        foreach (['Bearer abc', 'Basic !!!not-base64!!!', 'Basic ' . base64_encode('nocolon'), ''] as $header) {
            self::assertFalse(
                $auth->authenticate(new WebhookRequestContext(secret: self::SECRET, authorizationHeader: $header)),
                "Header should have been rejected: {$header}"
            );
        }
    }

    public function testAllowlistLayerIsAnIndependentVeto(): void
    {
        $auth = new SharedSecretAuthenticator(self::SECRET, '', '', ['203.0.113.0/24', '198.51.100.7']);

        self::assertTrue($auth->authenticate(
            new WebhookRequestContext(secret: self::SECRET, remoteIp: '203.0.113.45')
        ));
        self::assertTrue($auth->authenticate(
            new WebhookRequestContext(secret: self::SECRET, remoteIp: '198.51.100.7')
        ));

        self::assertFalse($auth->authenticate(
            new WebhookRequestContext(secret: self::SECRET, remoteIp: '192.0.2.1')
        ));
        self::assertStringContainsString('allowlist', $auth->lastFailureReason());

        // An allowlist is configured but the peer address is unknown: refuse.
        self::assertFalse($auth->authenticate(new WebhookRequestContext(secret: self::SECRET, remoteIp: '')));
    }

    public function testEmptyAllowlistDoesNotRestrictSource(): void
    {
        $auth = new SharedSecretAuthenticator(self::SECRET);

        self::assertTrue($auth->authenticate(
            new WebhookRequestContext(secret: self::SECRET, remoteIp: '192.0.2.1')
        ));
    }

    public function testGeneratedSecretIsUrlSafeAndStrongEnough(): void
    {
        $secrets = [];
        for ($i = 0; $i < 25; $i++) {
            $secret = SharedSecretAuthenticator::generateSecret();
            self::assertGreaterThanOrEqual(SharedSecretAuthenticator::MIN_SECRET_LENGTH, strlen($secret));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $secret, 'Secret must survive a URL unaltered.');
            $secrets[] = $secret;
        }
        self::assertCount(25, array_unique($secrets), 'Generated secrets must not repeat.');
    }

    /**
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function allowlistParsingProvider(): array
    {
        return [
            'comma separated' => ['203.0.113.0/24,198.51.100.7', ['203.0.113.0/24', '198.51.100.7']],
            'whitespace and newlines' => ["203.0.113.0/24\n  198.51.100.7 ", ['203.0.113.0/24', '198.51.100.7']],
            'semicolons' => ['203.0.113.0/24; 198.51.100.7', ['203.0.113.0/24', '198.51.100.7']],
            'empty' => ['', []],
            'only separators' => [' , ; ', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('allowlistParsingProvider')]
    public function testAllowlistParsing(string $raw, array $expected): void
    {
        self::assertSame($expected, SharedSecretAuthenticator::parseAllowlist($raw));
    }
}
