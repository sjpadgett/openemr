<?php

/**
 * Tests for the inbound ingest mode enum.
 *
 * The stored value is persisted in module credentials and decides whether a
 * site exposes a public webhook endpoint at all, so the conversions are pinned
 * here: unrecognized input must fall back to POLL (the mode that needs no
 * endpoint), and the stored string values must stay stable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Enums {
    // The label helpers call OpenEMR's escaping/translation helpers; identity
    // stubs declared in the enum's own namespace keep this suite free of the
    // gettext bootstrap without touching the global namespace.
    if (!function_exists('OpenEMR\Modules\FaxSMS\Enums\xlt')) {
        function xlt(string $s): string
        {
            return $s;
        }
    }
    if (!function_exists('OpenEMR\Modules\FaxSMS\Enums\attr')) {
        function attr(string $s): string
        {
            return htmlspecialchars($s, ENT_QUOTES);
        }
    }
    if (!function_exists('OpenEMR\Modules\FaxSMS\Enums\text')) {
        function text(string $s): string
        {
            return htmlspecialchars($s, ENT_NOQUOTES);
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'OpenEMR\\Modules\\FaxSMS\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $file = __DIR__
            . '/../../../../../../interface/modules/custom_modules/oe-module-faxsms/src/'
            . $relative;
        if (is_file($file)) {
            require_once $file;
        }
    });
}

namespace OpenEMR\Tests\Isolated\Modules\FaxSMS\Enums {

    use OpenEMR\Modules\FaxSMS\Enums\InboundIngestMode;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class InboundIngestModeTest extends TestCase
    {
        public function testStoredValuesAreStable(): void
        {
            // These strings are persisted in module credentials, so the set and
            // its order are the contract - not just that each case has a value.
            self::assertSame(
                ['poll', 'webhook'],
                array_map(static fn(InboundIngestMode $m): string => $m->value, InboundIngestMode::cases())
            );
        }

        /**
         * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
         *
         * @return array<string, array{mixed}>
         */
        public static function unrecognizedProvider(): array
        {
            return [
                'unknown string' => ['pushy'],
                'empty string' => [''],
                'null' => [null],
                'integer' => [1],
                'array' => [['webhook']],
                'wrong case' => ['WEBHOOK'],
            ];
        }

        /**
         * POLL is the safe fallback: it needs no public endpoint, so a corrupt
         * or missing setting can never leave a site waiting on a webhook it
         * never configured.
         */
        #[DataProvider('unrecognizedProvider')]
        public function testUnrecognizedValuesFallBackToPolling(mixed $value): void
        {
            self::assertSame(InboundIngestMode::POLL, InboundIngestMode::fromValue($value));
        }

        public function testRecognizedValuesRoundTrip(): void
        {
            foreach (InboundIngestMode::cases() as $case) {
                self::assertSame($case, InboundIngestMode::fromValue($case->value));
            }
        }

        public function testPollingIngestsOnEveryViewAndWebhookThrottles(): void
        {
            self::assertSame(0, InboundIngestMode::POLL->reconcileIntervalSeconds());
            self::assertGreaterThan(0, InboundIngestMode::WEBHOOK->reconcileIntervalSeconds());

            // Both modes reconcile through the same pull path, so a missed
            // webhook still lands rather than being lost.
            self::assertTrue(InboundIngestMode::POLL->ingestsOnView());
            self::assertTrue(InboundIngestMode::WEBHOOK->ingestsOnView());
        }

        public function testRenderSelectOptionsMarksTheActiveMode(): void
        {
            $html = InboundIngestMode::renderSelectOptions(InboundIngestMode::WEBHOOK);

            self::assertStringContainsString('value="poll"', $html);
            self::assertStringContainsString('value="webhook" selected', $html);
            self::assertStringNotContainsString('value="poll" selected', $html);
            self::assertSame(2, substr_count($html, '<option'));
        }

        public function testEveryCaseHasALabel(): void
        {
            foreach (InboundIngestMode::cases() as $case) {
                self::assertNotSame('', $case->getTranslatedLabel());
            }
        }
    }
}
