<?php

/**
 * Tests for the vendor document-retention posture.
 *
 * This setting decides whether the integration may ever fetch a document from
 * the vendor, so the important properties are that it defaults to the posture
 * that cannot lose a fax, and that it correctly reports the one configuration
 * combination which silently cannot deliver documents at all.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Enums {
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

    use OpenEMR\Modules\FaxSMS\Enums\VendorDocumentStorage;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class VendorDocumentStorageTest extends TestCase
    {
        public function testStoredValuesAreStable(): void
        {
            self::assertSame(
                ['retained', 'none'],
                array_map(
                    static fn(VendorDocumentStorage $s): string => $s->value,
                    VendorDocumentStorage::cases()
                )
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
                'unknown string' => ['off'],
                'empty string' => [''],
                'null' => [null],
                'bool' => [false],
                'wrong case' => ['NONE'],
            ];
        }

        /**
         * RETAINED is the safe fallback: it is the posture under which a missed
         * fax can still be recovered, so a corrupt setting degrades to not
         * losing documents.
         */
        #[DataProvider('unrecognizedProvider')]
        public function testUnrecognizedValuesFallBackToRetained(mixed $value): void
        {
            self::assertSame(VendorDocumentStorage::RETAINED, VendorDocumentStorage::fromValue($value));
        }

        public function testRecognizedValuesRoundTrip(): void
        {
            foreach (VendorDocumentStorage::cases() as $case) {
                self::assertSame($case, VendorDocumentStorage::fromValue($case->value));
            }
        }

        public function testOnlyRetainedDocumentsCanBeFetched(): void
        {
            self::assertTrue(VendorDocumentStorage::RETAINED->isFetchable());
            self::assertFalse(
                VendorDocumentStorage::NONE->isFetchable(),
                'With no vendor copy there is nothing to download, delete or view on demand.'
            );
        }

        /**
         * The combination worth catching in the UI: polling plus no vendor
         * storage learns that faxes arrived but can never retrieve them.
         */
        public function testPollOnlyDeliveryRequiresVendorStorage(): void
        {
            self::assertTrue(VendorDocumentStorage::RETAINED->supportsPollOnlyDelivery());
            self::assertFalse(VendorDocumentStorage::NONE->supportsPollOnlyDelivery());
        }

        public function testRenderSelectOptionsMarksTheActivePosture(): void
        {
            $html = VendorDocumentStorage::renderSelectOptions(VendorDocumentStorage::NONE);

            self::assertStringContainsString('value="none" selected', $html);
            self::assertStringNotContainsString('value="retained" selected', $html);
            self::assertSame(2, substr_count($html, '<option'));
        }

        public function testEveryCaseHasALabel(): void
        {
            foreach (VendorDocumentStorage::cases() as $case) {
                self::assertNotSame('', $case->getTranslatedLabel());
            }
        }
    }
}
