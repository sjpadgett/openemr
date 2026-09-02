<?php

/**
 * Resolves module credentials from the deployment environment, overlaying the
 * values stored in the database.
 *
 * Small sites configure credentials on the setup screen and they live encrypted
 * in `module_faxsms_credentials`. That is the wrong shape for a managed
 * deployment, where secrets come from a platform secret store, are rotated
 * without touching the application, and are deliberately absent from database
 * backups. This class lets those deployments supply credentials as environment
 * variables or a mounted JSON file, while a single-server practice carries on
 * using the setup screen and never knows this exists.
 *
 * Precedence is highest-trust-first: environment, then the mounted file, then
 * the database. A value present in a higher tier wins and is reported as
 * externally managed, so the setup screen can show it as read-only rather than
 * inviting an edit that would be silently overridden at runtime.
 *
 * Only credentials are sourced here. Behavioural settings (ingest mode, storage
 * posture) stay in the database where an administrator can change them from the
 * UI without a redeploy.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Service;

use Psr\Log\LoggerInterface;

final readonly class ExternalCredentialSource
{
    /**
     * Environment variable holding a path to a JSON file of credentials, for
     * platforms that mount secrets as files rather than inject them as
     * variables (Kubernetes projected volumes, Docker secrets).
     */
    public const FILE_PATH_ENV = 'OPENEMR_SINCH_FAX_CREDENTIALS_FILE';

    /**
     * Credential key => environment variable. Deliberately explicit rather than
     * derived from the key name, so a rename in either place cannot silently
     * stop honouring an operator's configuration.
     *
     * @var array<string, string>
     */
    private const ENV_MAP = [
        'sinch_project_id' => 'OPENEMR_SINCH_FAX_PROJECT_ID',
        'sinch_key_id' => 'OPENEMR_SINCH_FAX_KEY_ID',
        'sinch_key_secret' => 'OPENEMR_SINCH_FAX_KEY_SECRET',
        'sinch_service_id' => 'OPENEMR_SINCH_FAX_SERVICE_ID',
        'sinch_fax_number' => 'OPENEMR_SINCH_FAX_NUMBER',
        'sinch_webhook_secret' => 'OPENEMR_SINCH_FAX_WEBHOOK_SECRET',
        'sinch_webhook_user' => 'OPENEMR_SINCH_FAX_WEBHOOK_USER',
        'sinch_webhook_password' => 'OPENEMR_SINCH_FAX_WEBHOOK_PASSWORD',
        'sinch_webhook_allowed_ips' => 'OPENEMR_SINCH_FAX_WEBHOOK_ALLOWED_IPS',
    ];

    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    /**
     * Overlay externally supplied credentials onto the stored set.
     *
     * @param array<array-key, mixed> $stored Credentials as loaded from the database.
     * @return array<array-key, mixed> The effective credential set.
     */
    public function apply(array $stored): array
    {
        foreach ($this->externalValues() as $key => $value) {
            $stored[$key] = $value;
        }

        return $stored;
    }

    /**
     * Remove externally supplied credentials from a set about to be persisted.
     *
     * A read-only input still posts its value, so without this the setup screen
     * would quietly copy platform-managed secrets into the database - exactly
     * what a deployment using a secret store is trying to avoid. Stripping them
     * here means the database keeps only what an administrator actually typed.
     *
     * @param array<array-key, mixed> $setup
     * @return array<array-key, mixed>
     */
    public function stripManaged(array $setup): array
    {
        foreach ($this->managedKeys() as $key) {
            unset($setup[$key]);
        }

        return $setup;
    }

    /**
     * Credential keys supplied externally, so the UI can mark them read-only.
     *
     * @return list<string>
     */
    public function managedKeys(): array
    {
        return array_keys($this->externalValues());
    }

    public function hasAnyExternal(): bool
    {
        return $this->externalValues() !== [];
    }

    /**
     * Every externally supplied credential, environment first then file.
     *
     * @return array<string, string>
     */
    private function externalValues(): array
    {
        $values = $this->fromFile();

        // Environment wins over the file: it is the tier an operator reaches
        // for to override a baked-in mount.
        foreach (self::ENV_MAP as $key => $envName) {
            $raw = getenv($envName);
            if (is_string($raw) && trim($raw) !== '') {
                $values[$key] = trim($raw);
            }
        }

        return $values;
    }

    /**
     * Credentials from the JSON file named by FILE_PATH_ENV, if configured.
     *
     * A configured-but-unusable file is logged and ignored rather than fatal:
     * a fax module must not take the whole site down because a secret mount
     * was late, and the missing credential surfaces as "not configured" in the
     * normal way.
     *
     * @return array<string, string>
     */
    private function fromFile(): array
    {
        $path = getenv(self::FILE_PATH_ENV);
        if (!is_string($path) || trim($path) === '') {
            return [];
        }
        $path = trim($path);

        if (!is_file($path) || !is_readable($path)) {
            $this->logger?->warning('Sinch credentials file is not readable', ['path' => $path]);

            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->logger?->warning('Sinch credentials file could not be read', ['path' => $path]);

            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger?->warning('Sinch credentials file is not valid JSON', ['path' => $path]);

            return [];
        }

        // Accept both the credential keys themselves and the environment
        // variable names, so one file can be reused as an env-file.
        $envToKey = array_flip(self::ENV_MAP);
        $values = [];
        foreach ($decoded as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }
            $key = match (true) {
                isset(self::ENV_MAP[$name]) => $name,
                isset($envToKey[$name]) => $envToKey[$name],
                default => null,
            };
            if ($key !== null && trim((string)$value) !== '') {
                $values[$key] = trim((string)$value);
            }
        }

        return $values;
    }
}
