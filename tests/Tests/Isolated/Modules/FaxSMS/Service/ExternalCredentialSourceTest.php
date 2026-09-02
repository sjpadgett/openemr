<?php

/**
 * Tests for platform-supplied fax credentials.
 *
 * The point of this class is that a managed deployment can keep secrets out of
 * the database entirely, so the cases that matter are the precedence order and
 * — most of all — that stripManaged() stops a posted read-only field from
 * copying a platform secret back into the database.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\FaxSMS\Service;

use Composer\Autoload\ClassLoader;
use OpenEMR\Modules\FaxSMS\Service\ExternalCredentialSource;
use PHPUnit\Framework\TestCase;

final class ExternalCredentialSourceTest extends TestCase
{
    /** @var list<string> */
    private array $touchedEnv = [];
    /** @var list<string> */
    private array $tempFiles = [];

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

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $name) {
            putenv($name);
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->touchedEnv = [];
        $this->tempFiles = [];
    }

    public function testStoredCredentialsPassThroughUntouchedWhenNothingIsExternal(): void
    {
        $source = new ExternalCredentialSource();
        $stored = ['sinch_project_id' => 'from-db', 'sinch_key_secret' => 'db-secret'];

        self::assertSame($stored, $source->apply($stored));
        self::assertSame([], $source->managedKeys());
        self::assertFalse($source->hasAnyExternal());
    }

    public function testEnvironmentOverridesTheDatabase(): void
    {
        $this->setEnv('OPENEMR_SINCH_FAX_PROJECT_ID', 'env-project');
        $this->setEnv('OPENEMR_SINCH_FAX_KEY_SECRET', 'env-secret');

        $source = new ExternalCredentialSource();
        $effective = $source->apply([
            'sinch_project_id' => 'db-project',
            'sinch_key_secret' => 'db-secret',
            'sinch_fax_number' => '+15557654321',
        ]);

        self::assertSame('env-project', $effective['sinch_project_id']);
        self::assertSame('env-secret', $effective['sinch_key_secret']);
        // Untouched keys still come from the database.
        self::assertSame('+15557654321', $effective['sinch_fax_number']);

        self::assertTrue($source->hasAnyExternal());
        self::assertEqualsCanonicalizing(
            ['sinch_project_id', 'sinch_key_secret'],
            $source->managedKeys()
        );
    }

    public function testBlankEnvironmentValueIsNotTreatedAsConfigured(): void
    {
        $this->setEnv('OPENEMR_SINCH_FAX_PROJECT_ID', '   ');

        $source = new ExternalCredentialSource();

        self::assertSame('db-project', $source->apply(['sinch_project_id' => 'db-project'])['sinch_project_id']);
        self::assertSame([], $source->managedKeys());
    }

    public function testMountedFileSuppliesCredentialsAndEnvironmentBeatsIt(): void
    {
        $file = $this->writeJsonFile([
            'sinch_project_id' => 'file-project',
            'sinch_key_id' => 'file-key',
        ]);
        $this->setEnv(ExternalCredentialSource::FILE_PATH_ENV, $file);
        $this->setEnv('OPENEMR_SINCH_FAX_PROJECT_ID', 'env-project');

        $effective = (new ExternalCredentialSource())->apply(['sinch_project_id' => 'db-project']);

        self::assertSame('env-project', $effective['sinch_project_id'], 'Environment outranks the file.');
        self::assertSame('file-key', $effective['sinch_key_id'], 'File outranks the database.');
    }

    public function testFileMayUseEnvironmentVariableNamesAsKeys(): void
    {
        $file = $this->writeJsonFile(['OPENEMR_SINCH_FAX_KEY_SECRET' => 'file-secret']);
        $this->setEnv(ExternalCredentialSource::FILE_PATH_ENV, $file);

        $effective = (new ExternalCredentialSource())->apply([]);

        self::assertSame('file-secret', $effective['sinch_key_secret']);
    }

    /**
     * A late or broken secret mount must not take the site down; the credential
     * simply reads as unconfigured.
     */
    public function testUnusableFileIsIgnoredRatherThanFatal(): void
    {
        foreach (['/nonexistent/path/creds.json', $this->writeRawFile('not json at all')] as $path) {
            $this->setEnv(ExternalCredentialSource::FILE_PATH_ENV, $path);
            $source = new ExternalCredentialSource();

            self::assertSame(['sinch_project_id' => 'db'], $source->apply(['sinch_project_id' => 'db']));
            self::assertSame([], $source->managedKeys());
        }
    }

    public function testUnknownKeysInTheFileAreIgnored(): void
    {
        $file = $this->writeJsonFile(['something_else' => 'nope', 'sinch_key_id' => 'yes']);
        $this->setEnv(ExternalCredentialSource::FILE_PATH_ENV, $file);

        $effective = (new ExternalCredentialSource())->apply([]);

        self::assertArrayNotHasKey('something_else', $effective);
        self::assertSame('yes', $effective['sinch_key_id']);
    }

    /**
     * The security-relevant case: the setup screen renders managed fields
     * read-only, but a read-only input still posts. Without stripping, saving
     * the form would write the platform's secret into the database.
     */
    public function testManagedCredentialsAreStrippedBeforePersistence(): void
    {
        $this->setEnv('OPENEMR_SINCH_FAX_KEY_SECRET', 'platform-secret');

        $posted = [
            'sinch_project_id' => 'typed-by-admin',
            'sinch_key_secret' => 'platform-secret',
            'sinch_fax_number' => '+15557654321',
        ];

        $persisted = (new ExternalCredentialSource())->stripManaged($posted);

        self::assertArrayNotHasKey('sinch_key_secret', $persisted, 'A platform secret must never be stored.');
        self::assertSame('typed-by-admin', $persisted['sinch_project_id']);
        self::assertSame('+15557654321', $persisted['sinch_fax_number']);
    }

    public function testStripIsANoOpWhenNothingIsManaged(): void
    {
        $posted = ['sinch_project_id' => 'a', 'sinch_key_secret' => 'b'];

        self::assertSame($posted, (new ExternalCredentialSource())->stripManaged($posted));
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $this->touchedEnv[] = $name;
    }

    /**
     * @param array<string, string> $data
     */
    private function writeJsonFile(array $data): string
    {
        return $this->writeRawFile((string)json_encode($data));
    }

    private function writeRawFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sinchcreds');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
