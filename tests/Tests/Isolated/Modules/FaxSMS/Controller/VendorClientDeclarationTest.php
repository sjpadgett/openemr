<?php

/**
 * Guards that every fax/SMS vendor client is a legally declared class.
 *
 * PHP runs its inheritance compatibility checks when a class is *declared*, not
 * when it is used, so a child that (for example) adds a type to a property the
 * abstract AppDispatch base left untyped is a hard fatal the moment the class
 * loads. Nothing else in this suite instantiates the vendor clients - they need
 * a database, a session and vendor credentials - so that whole category of
 * breakage could otherwise reach a release untested: the module would simply
 * fatal the first time a user opened the fax screen.
 *
 * Reflecting each class is enough to trigger the check, and costs nothing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\FaxSMS\Controller;

use Composer\Autoload\ClassLoader;
use OpenEMR\Modules\FaxSMS\Contracts\FaxChannelInterface;
use OpenEMR\Modules\FaxSMS\Contracts\FaxDocumentDisposalInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class VendorClientDeclarationTest extends TestCase
{
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

    /**
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     *
     * @return array<string, array{class-string}>
     */
    public static function faxVendorClientProvider(): array
    {
        return [
            'Sinch' => ['OpenEMR\Modules\FaxSMS\Controller\SinchFaxClient'],
            'SignalWire' => ['OpenEMR\Modules\FaxSMS\Controller\SignalWireClient'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('faxVendorClientProvider')]
    public function testVendorClientDeclarationIsLegal(string $class): void
    {
        // Reflecting the class forces PHP to declare it, which is where an
        // incompatible property or method signature against AppDispatch fatals.
        $reflection = new ReflectionClass($class);

        self::assertSame($class, $reflection->getName());
        self::assertFalse($reflection->isAbstract(), 'A vendor client must be instantiable.');
        self::assertTrue(
            $reflection->isSubclassOf('OpenEMR\Modules\FaxSMS\Controller\AppDispatch'),
            'Vendor clients dispatch through AppDispatch.'
        );
    }

    /**
     * A fax vendor must advertise both capabilities the dispatcher relies on:
     * that it can send, and that its documents dispose through the shared,
     * path-confined routine rather than a per-vendor copy.
     *
     * @param class-string $class
     */
    #[DataProvider('faxVendorClientProvider')]
    public function testFaxVendorClientsDeclareTheSharedContracts(string $class): void
    {
        $reflection = new ReflectionClass($class);

        self::assertTrue(
            $reflection->implementsInterface(FaxChannelInterface::class),
            "{$class} must declare it can send a fax."
        );
        self::assertTrue(
            $reflection->implementsInterface(FaxDocumentDisposalInterface::class),
            "{$class} must dispose documents through the shared contract."
        );
    }

    /**
     * The base declares $credentials untyped, and PHP forbids a child adding a
     * type to an inherited untyped property. Pinning this stops the next vendor
     * from reintroducing the fatal by "tidying up" the property.
     */
    #[DataProvider('faxVendorClientProvider')]
    public function testCredentialsPropertyStaysCompatibleWithTheBase(string $class): void
    {
        $property = (new ReflectionClass($class))->getProperty('credentials');

        self::assertFalse(
            $property->hasType(),
            'AppDispatch::$credentials is untyped; typing it in a subclass is a class-load fatal.'
        );
    }
}
