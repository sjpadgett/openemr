<?php

/**
 * Sinch Fax API v3 client.
 *
 * Implements the module's fax channel against Sinch's project-scoped REST API.
 * Three things distinguish it from the other fax vendors:
 *
 *  - Outbound documents are posted to Sinch as base64 content in the request
 *    body, so nothing has to be staged at a URL the provider can reach. There
 *    is no public media handout for Sinch, which means the fax path works on
 *    sites that are not reachable from the internet.
 *  - Inbound faxes reach the local queue by either of two ingest modes, chosen
 *    per site: POLL (the inbox pulls) or WEBHOOK (Sinch pushes). The mode
 *    decides only *when* ingest runs - both write into `oe_faxsms_queue` and
 *    this class renders, acts on and disposes of faxes from that queue either
 *    way, so switching modes cannot strand faxes taken in under the other one.
 *  - Sinch has no "delete the fax" operation. DELETE /faxes/{id}/file frees the
 *    stored document while the fax record itself remains listable, so handling
 *    a fax releases the provider's copy and marks the local queue row rather
 *    than deleting anything upstream.
 *
 * Document disposal goes through the shared FaxDocumentDisposalTrait and
 * received faxes are filed through FaxDocumentService, so a fax handled here is
 * handled exactly the way every other vendor's fax is.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Controller;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Crypto\CryptoInterface;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\FaxSMS\Contracts\FaxChannelInterface;
use OpenEMR\Modules\FaxSMS\Contracts\FaxDocumentDisposalInterface;
use OpenEMR\Modules\FaxSMS\Enums\InboundIngestMode;
use OpenEMR\Modules\FaxSMS\Enums\ServiceType;
use OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest\Client;
use OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest\FaxInstance;
use OpenEMR\Modules\FaxSMS\Service\FaxMailer;
use OpenEMR\Modules\FaxSMS\Service\FaxUploadStaging;
use OpenEMR\Modules\FaxSMS\Webhook\InboundFaxContentFetcherInterface;
use OpenEMR\Modules\FaxSMS\Webhook\InboundFaxPayload;
use OpenEMR\Modules\FaxSMS\Webhook\InboundFaxReceiver;
use OpenEMR\Modules\FaxSMS\Webhook\SharedSecretAuthenticator;
use OpenEMR\Modules\FaxSMS\Webhook\SinchWebhookPayloadParser;

class SinchFaxClient extends AppDispatch implements
    FaxChannelInterface,
    FaxDocumentDisposalInterface,
    InboundFaxContentFetcherInterface
{
    use FaxDocumentDisposalTrait;

    /** Max faxes to pull from the Sinch API in a single ingest sweep. */
    private const FAX_LIST_LIMIT = 100;

    /** Statuses that will not change again. */
    private const TERMINAL_FAX_STATUSES = ['COMPLETED', 'FAILURE'];

    /** Terminal statuses that never carry a document, so no fetch is warranted. */
    private const FAILED_FAX_STATUSES = ['FAILURE'];

    /**
     * Staged-upload extension -> Sinch fileType. Mirrors the extensions
     * FaxUploadStaging is willing to produce.
     */
    private const FILE_TYPE_BY_EXTENSION = [
        'pdf' => 'PDF',
        'tif' => 'TIF',
        'tiff' => 'TIF',
        'jpg' => 'JPG',
        'jpeg' => 'JPG',
        'png' => 'PNG',
        'txt' => 'TXT',
    ];

    protected string $baseDir = '';
    protected CryptoInterface $crypto;
    protected mixed $credentials = null;
    public string $portalUrl = 'https://dashboard.sinch.com';

    private readonly FaxUploadStaging $uploadStaging;
    private ?Client $client = null;
    private string $projectId = '';
    private string $keyId = '';
    private string $keySecret = '';
    private string $serviceId = '';
    private string $faxNumber = '';
    private InboundIngestMode $ingestMode = InboundIngestMode::POLL;
    private string $webhookSecret = '';
    private string $webhookBasicUser = '';
    private string $webhookBasicPassword = '';
    private string $webhookAllowedIps = '';

    public function __construct()
    {
        $globals = OEGlobalsBag::getInstance();
        $this->crypto = ServiceContainer::getCrypto();
        $this->uploadStaging = FaxUploadStaging::create();
        $this->baseDir = $globals->getString('temporary_files_dir');

        try {
            $this->credentials = $this->getCredentials();

            if ($this->projectId !== '' && $this->keyId !== '' && $this->keySecret !== '') {
                $this->client = new Client($this->projectId, $this->keyId, $this->keySecret);
            }
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error(
                'Sinch fax client initialization failed',
                ['exception' => $e]
            );
            // Continue anyway so the setup screen remains reachable.
        }

        // Call parent constructor last - it handles routing/dispatch and may exit.
        parent::__construct();
    }

    /**
     * Load and unpack the stored Sinch credentials.
     *
     * @return mixed
     */
    public function getCredentials(): mixed
    {
        $credentials = AppDispatch::getSetup();

        $this->projectId = $this->credentialString($credentials, 'sinch_project_id');
        $this->keyId = $this->credentialString($credentials, 'sinch_key_id');
        $this->keySecret = $this->credentialString($credentials, 'sinch_key_secret');
        $this->serviceId = $this->credentialString($credentials, 'sinch_service_id');
        // Ensure the 'from' fax number is always in E.164 format.
        $this->faxNumber = $this->formatPhone($this->credentialString($credentials, 'sinch_fax_number'));

        $this->ingestMode = InboundIngestMode::fromValue(
            is_array($credentials) ? ($credentials['sinch_inbound_mode'] ?? null) : null
        );
        $this->webhookSecret = $this->credentialString($credentials, 'sinch_webhook_secret');
        $this->webhookBasicUser = $this->credentialString($credentials, 'sinch_webhook_user');
        $this->webhookBasicPassword = $this->credentialString($credentials, 'sinch_webhook_password');
        $this->webhookAllowedIps = $this->credentialString($credentials, 'sinch_webhook_allowed_ips');

        return $credentials;
    }

    /**
     * Read one credential as a trimmed string, whatever the stored shape.
     */
    private function credentialString(mixed $credentials, string $key): string
    {
        if (!is_array($credentials)) {
            return '';
        }
        $value = $credentials[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Verify the vendor is configured and the caller holds the module ACL.
     *
     * @param array{0: string, 1: string} $acl
     */
    public function authenticate($acl = ['patients', 'docs']): int|bool
    {
        if (empty($this->credentials)) {
            $this->credentials = $this->getCredentials();
        }

        if ($this->projectId === '' || $this->keyId === '' || $this->keySecret === '') {
            return 0;
        }

        return $this->verifyAcl($acl[0], $acl[1]);
    }

    /**
     * Send a fax through Sinch.
     *
     * The document is posted as base64 content in the request body, so no
     * publicly reachable staging URL is ever created for outbound PHI.
     *
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function sendFax(): string
    {
        if (!$this->authenticate()) {
            return $this->authErrorDefault;
        }

        if ($this->client === null) {
            return xlt('Sinch client not initialized. Please configure credentials.');
        }

        $isContent = $this->getRequest('isContent');
        $fileParam = $this->getRequest('file');
        $file = is_scalar($fileParam) ? (string)$fileParam : '';
        $docId = $this->getRequest('docid');
        $phoneParam = $this->getRequest('phone');
        $phone = is_scalar($phoneParam) ? $this->formatPhone((string)$phoneParam) : '';
        $isDocumentsParam = $this->getRequest('isDocuments');
        $isDocuments = !empty($isDocumentsParam);
        $email = $this->getRequest('email');
        $hasEmail = $this->validEmail($email);
        $smtpEnabled = OEGlobalsBag::getInstance()->getString('SMTP_HOST') !== '';
        $user = self::getLoggedInUser();

        // Resolve a plain server path for the file-mode send.
        if (empty($isContent) && !$isDocuments && $file !== '') {
            if (str_starts_with($file, 'file://')) {
                $file = substr($file, 7);
            }
            $realPath = realpath($file);
            if ($realPath === false) {
                return xlt('Error: No content to fax');
            }
            $file = str_replace('\\', '/', $realPath);

            // Outbound file-mode sends must reference an upload this controller
            // staged. Rejecting any other resolved path stops an authenticated
            // caller from faxing out arbitrary local files.
            if (!$this->uploadStaging->isStagedUploadPath($file)) {
                ServiceContainer::getLogger()->warning('Sinch sendFax rejected a non-staged file path');
                return xlt('Error: Invalid file location');
            }
        }

        // Decrypt the staged upload to a per-request plaintext tempnam. The
        // tempnam carries no extension, so remember the staged file's own
        // extension - it is what tells Sinch the document's fileType.
        $stagedPath = null;
        $plainStagePath = null;
        $fileTypeHint = null;
        if (empty($isContent) && !$isDocuments && $file !== '' && is_file($file)) {
            $plainStagePath = $this->uploadStaging->decryptStagedToTemp($file);
            if ($plainStagePath === null) {
                return xlt('Error: Failed to read fax content');
            }
            $fileTypeHint = self::FILE_TYPE_BY_EXTENSION[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
            $stagedPath = $file;
            $file = $plainStagePath;
        }

        if ($isDocuments) {
            // Same hardened read every other vendor uses: enforces the
            // patients/docs ACL and that the document actually belongs to a
            // patient, so a request-supplied id cannot reach arbitrary rows.
            try {
                $file = $this->readAuthorizedFaxDocument(is_scalar($docId) ? (int)$docId : 0);
            } catch (\Throwable $e) {
                ServiceContainer::getLogger()->warning(
                    'Sinch sendFax could not read the requested patient document',
                    ['exception' => $e]
                );
                $this->uploadStaging->removeStagedArtifacts($stagedPath, $plainStagePath);

                return xlt('Error: No content to send.');
            }
        }

        // Email the same payload when requested. $file holds raw bytes for the
        // document and inline-content branches, and a plaintext path otherwise.
        $error = false;
        $emailPath = null;
        $payloadIsContent = $isDocuments || !empty($isContent);
        if ($hasEmail && $smtpEnabled) {
            try {
                $emailPath = FaxMailer::mailUploadedDocument($email, '', $file, $user, $payloadIsContent);
            } catch (\PHPMailer\PHPMailer\Exception) {
                $error = true;
            }
        }

        if ($phone === '') {
            $this->uploadStaging->removeStagedArtifacts($stagedPath, $plainStagePath, $emailPath);
            return xlt('Error: Invalid phone number');
        }
        if ($this->faxNumber === '') {
            $this->uploadStaging->removeStagedArtifacts($stagedPath, $plainStagePath, $emailPath);
            return xlt('Error: No sending fax number is configured');
        }

        try {
            $payload = $this->resolveOutboundPayload($file, $payloadIsContent, $fileTypeHint);
            if ($payload === null) {
                return xlt('Error: Could not prepare the document for faxing.');
            }

            $options = [
                'to' => $phone,
                'from' => $this->faxNumber,
                'files' => [$payload],
            ];
            if ($this->serviceId !== '') {
                $options['serviceId'] = $this->serviceId;
            }

            $faxes = $this->client->fax->v3->faxes->create($options);

            // Stateless: Sinch is the system of record. The sent fax is NOT
            // persisted to oe_faxsms_queue; it appears in the outbound list on
            // the next poll.
            $accepted = $faxes[0] ?? null;
            if ($accepted instanceof FaxInstance && $accepted->status === 'FAILURE') {
                ServiceContainer::getLogger()->error('Sinch rejected an outbound fax', [
                    'faxId' => $accepted->id,
                    'errorType' => $accepted->errorType,
                    'errorCode' => $accepted->errorCode,
                ]);
                return xlt('Error: The fax was rejected by the provider.');
            }

            return xlt('Fax Successfully Sent') . ($error ? ('<br />' . xlt('Email Failed')) : '');
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('Sinch fax send failed', ['exception' => $e]);
            return xlt('Error: The fax could not be sent.');
        } finally {
            $this->uploadStaging->removeStagedArtifacts($stagedPath, $plainStagePath, $emailPath);
        }
    }

    /**
     * Build the base64 file entry for an outbound send.
     *
     * @param string      $file         Raw bytes when $isContent, otherwise a plaintext path.
     * @param bool        $isContent    Whether $file already holds the document bytes.
     * @param string|null $fileTypeHint Sinch fileType resolved from the staged
     *                                  upload's extension, when there was one.
     * @return array{content: string, fileType: string}|null
     */
    private function resolveOutboundPayload(string $file, bool $isContent, ?string $fileTypeHint = null): ?array
    {
        if ($isContent) {
            if ($file === '') {
                return null;
            }
            // Defensive decrypt: a no-op on plaintext, but it covers a caller
            // that handed us bytes still encrypted at rest.
            $bytes = $this->crypto->decryptFromFilesystem($file);
            if (!is_string($bytes) || $bytes === '') {
                return null;
            }

            return ['content' => $bytes, 'fileType' => $fileTypeHint ?? $this->sniffFileType($bytes)];
        }

        if (!is_file($file)) {
            ServiceContainer::getLogger()->error('Sinch outbound source file is not available');
            return null;
        }
        $bytes = file_get_contents($file);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $fileType = $fileTypeHint
            ?? (self::FILE_TYPE_BY_EXTENSION[$ext] ?? $this->sniffFileType($bytes));

        return ['content' => $bytes, 'fileType' => $fileType];
    }

    /**
     * Determine the Sinch fileType from the leading bytes. Used when the payload
     * arrives as content with no filename to read an extension from.
     */
    private function sniffFileType(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'PDF',
            str_starts_with($bytes, "II\x2A\x00"), str_starts_with($bytes, "MM\x00\x2A") => 'TIF',
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'PNG',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'JPG',
            default => 'PDF',
        };
    }

    /**
     * Build a receiver for the inbound webhook endpoint, or null when this site
     * must not expose one.
     *
     * Called from an unauthenticated context, so it re-derives everything from
     * stored configuration and refuses unless the site has genuinely opted in:
     * Sinch must be the enabled fax vendor, ingest mode must be WEBHOOK, and a
     * usable secret must be configured. Any other state yields null, which the
     * endpoint reports as 404.
     */
    public static function createWebhookReceiver(): ?InboundFaxReceiver
    {
        $enabledFaxVendor = ServiceType::fromValue(
            OEGlobalsBag::getInstance()->get('oefax_enable_fax') ?? null
        );
        if ($enabledFaxVendor !== ServiceType::SINCH) {
            return null;
        }

        $client = new self();
        if ($client->ingestMode !== InboundIngestMode::WEBHOOK) {
            return null;
        }
        if (strlen($client->webhookSecret) < SharedSecretAuthenticator::MIN_SECRET_LENGTH) {
            ServiceContainer::getLogger()->warning(
                'Sinch webhook mode is enabled but no usable secret is configured; endpoint disabled'
            );

            return null;
        }

        return new InboundFaxReceiver(
            new SharedSecretAuthenticator(
                $client->webhookSecret,
                $client->webhookBasicUser,
                $client->webhookBasicPassword,
                SharedSecretAuthenticator::parseAllowlist($client->webhookAllowedIps)
            ),
            new SinchWebhookPayloadParser(),
            new FaxDocumentService(),
            ServiceContainer::getLogger(),
            $client->queueAccount(),
            $client
        );
    }

    /**
     * Fetch a fax document from Sinch, for a callback that carried metadata
     * only. Satisfies InboundFaxContentFetcherInterface.
     */
    public function fetchFaxContent(string $faxId): ?string
    {
        return $this->downloadFaxContent($faxId);
    }

    /**
     * The webhook URL a site pastes into its Sinch service configuration.
     * Returns '' until a secret exists, so the setup screen can prompt for one
     * rather than hand out a URL that would never authenticate.
     */
    public function getWebhookUrl(): string
    {
        if ($this->webhookSecret === '') {
            return '';
        }
        $globals = OEGlobalsBag::getInstance();
        $base = rtrim($globals->getString('site_addr_oath') . $globals->getString('web_root'), '/');

        return $base
            . '/interface/modules/custom_modules/oe-module-faxsms/library/faxReceive.php'
            . '?secret=' . urlencode($this->webhookSecret);
    }

    /**
     * Queue rows are scoped by (account, job_id); the Sinch project is this
     * vendor's account identity.
     */
    private function queueAccount(): string
    {
        return $this->projectId;
    }

    /**
     * Pull recent faxes from Sinch and ingest any the queue has not seen.
     *
     * This is the single ingest implementation. POLL mode runs it on every
     * inbox view; WEBHOOK mode runs it as an occasional reconcile sweep, so a
     * webhook that was missed, misconfigured or briefly undeliverable still
     * lands rather than being lost.
     *
     * Inbound faxes are stored with their document, since that is what the
     * inbox acts on. Outbound faxes are recorded as metadata only - we already
     * hold what we sent, and the document stays retrievable from Sinch on
     * demand for the sent list's view/download actions.
     *
     * @return int Number of newly queued faxes.
     */
    public function ingestInboundFaxes(int $sinceDays = 30): int
    {
        if ($this->client === null) {
            return 0;
        }

        $documents = new FaxDocumentService();
        $account = $this->queueAccount();
        $ingested = 0;

        try {
            $faxes = $this->client->fax->v3->faxes->read(
                ['createTimeFrom' => gmdate('Y-m-d\TH:i:s\Z', (int)(strtotime("-{$sinceDays} days") ?: time()))],
                self::FAX_LIST_LIMIT
            );
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('Sinch inbound ingest could not list faxes', ['exception' => $e]);

            return 0;
        }

        foreach ($faxes as $fax) {
            $faxId = (string)($fax->id ?? '');
            if ($faxId === '') {
                continue;
            }

            try {
                $existing = $documents->getFaxDocument($faxId);
                if ($existing !== null) {
                    // Already queued. Keep its status current so a fax that has
                    // since completed or failed stops showing as in-flight.
                    $status = strtolower((string)($fax->status ?? ''));
                    if ($status !== '' && $status !== strtolower((string)($existing['status'] ?? ''))) {
                        $documents->updateFaxStatus($faxId, $status);
                    }
                    continue;
                }

                $isInbound = ($fax->direction ?? 'INBOUND') !== 'OUTBOUND';
                // Never queue a failed inbound fax: there is no document behind
                // it and nothing for a user to act on.
                if ($isInbound && in_array((string)$fax->status, self::FAILED_FAX_STATUSES, true)) {
                    continue;
                }

                $payload = $this->toInboundPayload($fax, $isInbound);
                $documents->insertInboundFaxToQueue($this->toQueueRecord($payload), $account);
                $ingested++;
            } catch (\Throwable $e) {
                // One bad fax must not abort the sweep.
                ServiceContainer::getLogger()->error('Sinch ingest failed for a fax', [
                    'exception' => $e,
                    'faxId' => $faxId,
                ]);
            }
        }

        return $ingested;
    }

    /**
     * Normalize a listed fax into the shared inbound payload, fetching the
     * document for inbound faxes that are complete.
     */
    private function toInboundPayload(FaxInstance $fax, bool $isInbound): InboundFaxPayload
    {
        $faxId = (string)$fax->id;
        $content = null;
        if ($isInbound && $fax->status === 'COMPLETED' && $fax->hasFile !== false) {
            $content = $this->downloadFaxContent($faxId);
        }

        $created = $fax->completedTime ?? $fax->createTime;

        return new InboundFaxPayload(
            faxId: $faxId,
            from: (string)($fax->from ?? ''),
            to: (string)($fax->to ?? ''),
            direction: $isInbound ? 'inbound' : 'outbound',
            status: strtolower((string)($fax->status ?? 'unknown')),
            pages: (int)($fax->numberOfPages ?? 0),
            receivedOn: $created instanceof \DateTimeImmutable
                ? $created->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
                : gmdate('Y-m-d H:i:s'),
            content: $content,
        );
    }

    /**
     * Shape a payload the way FaxDocumentService::insertInboundFaxToQueue()
     * expects, so pull ingest and the webhook receiver share one storage path.
     */
    private function toQueueRecord(InboundFaxPayload $payload): object
    {
        $record = new \stdClass();
        $record->JobId = $payload->faxId;
        $record->CallingNumber = $payload->from !== '' ? $payload->from : 'unknown';
        $record->CalledNumber = $payload->to;
        $record->ReceivedOn = $payload->receivedOn;
        $record->PagesReceived = $payload->pages;
        $record->FaxImage = $payload->hasContent() ? base64_encode((string)$payload->content) : '';
        $record->DocumentParams = (object)['Type' => $payload->mimeType];

        return $record;
    }

    /**
     * Run ingest if this mode wants it now.
     *
     * POLL ingests on every view. WEBHOOK only sweeps once per interval, using
     * a marker file's mtime as site-wide state so the throttle holds across
     * users and processes without a schema change.
     */
    private function ingestIfDue(): void
    {
        $interval = $this->ingestMode->reconcileIntervalSeconds();
        if ($interval === 0) {
            $this->ingestInboundFaxes();
            return;
        }

        $marker = $this->reconcileMarkerPath();
        if ($marker !== null && is_file($marker) && (time() - (int)filemtime($marker)) < $interval) {
            return;
        }
        if ($marker !== null) {
            // Touch before the sweep so concurrent views do not all sweep.
            @touch($marker);
        }
        $this->ingestInboundFaxes();
    }

    private function reconcileMarkerPath(): ?string
    {
        $dir = OEGlobalsBag::getInstance()->getString('OE_SITE_DIR') . '/documents/logs_and_misc';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/.sinch_fax_reconcile';
    }

    /**
     * Live count of inbound faxes awaiting handling, read from the local queue.
     */
    public function fetchReminderCount(): string
    {
        try {
            $rows = (new FaxDocumentService())->fetchQueueRange(
                gmdate('Y-m-d H:i:s', (int)(strtotime('-90 days') ?: time())),
                gmdate('Y-m-d H:i:s', time() + 86400),
                'inbound'
            );
            $count = 0;
            foreach ($rows as $row) {
                if (empty($row['patient_id'])) {
                    $count++;
                }
            }

            return (string)json_encode(['count' => $count]);
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('Sinch reminder count failed', ['exception' => $e]);

            return (string)json_encode(['count' => 0]);
        }
    }

    /**
     * E.164 check, matching the format Sinch requires for `to` and `from`.
     */
    public function validatePhone(string $n): bool
    {
        return (bool)preg_match('/^\+[1-9]\d{10,14}$/', $n);
    }

    /**
     * Document action endpoint used by the shared getDocument() UI handler -
     * the same contract every other vendor implements.
     *
     * Request: docid (fax id), download ('true'|...), delete ('true'|...).
     *   delete   -> mark the queue row handled and release Sinch's stored copy.
     *   download -> stage an encrypted temp copy for disposeDocument to stream,
     *               mark handled, release the provider copy.
     *   view     -> return {base64, mime, filename} for the in-modal viewer.
     *
     * Inbound documents come from the local queue; outbound ones are fetched
     * from Sinch on demand, since sent faxes are queued as metadata only.
     */
    public function viewFax(): string
    {
        if (!$this->authenticate()) {
            return (string)json_encode(['error' => xlt('Not authorized')]);
        }

        $docIdRaw = $this->getRequest('docid');
        $faxId = is_scalar($docIdRaw) ? (string)$docIdRaw : '';
        $isDownload = $this->getRequest('download') == 'true';
        $isDelete = $this->getRequest('delete') == 'true';

        if ($faxId === '') {
            return (string)json_encode(['error' => xlt('Missing fax ID')]);
        }

        try {
            $documents = new FaxDocumentService();

            if ($isDelete) {
                $documents->deleteFaxDocument($faxId, true);
                $this->releaseUpstreamDocument($faxId);

                return (string)json_encode('success');
            }

            $rawData = $this->readFaxBytes($faxId, $documents);
            if ($rawData === null || $rawData === '') {
                return (string)json_encode(['error' => xlt('Fax document not available')]);
            }

            if ($isDownload) {
                // The user is taking it: stage an encrypted temp copy for
                // disposeDocument to stream, mark the queue row handled, and
                // release the provider copy.
                $filePath = $this->saveFaxToFile($rawData, $faxId);
                $this->setSession('where', $filePath);
                $documents->deleteFaxDocument($faxId, true);
                $this->releaseUpstreamDocument($faxId);

                return (string)json_encode([
                    'base64' => base64_encode($rawData),
                    'mime' => 'application/pdf',
                    'filename' => 'Fax_' . $faxId . '.pdf',
                    'path' => $filePath,
                ]);
            }

            // View: base64 for the in-modal viewer; nothing is disposed.
            return (string)json_encode([
                'base64' => base64_encode($rawData),
                'mime' => 'application/pdf',
                'filename' => 'Fax_' . $faxId . '.pdf',
            ]);
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('Sinch viewFax failed', ['exception' => $e, 'faxId' => $faxId]);

            return (string)json_encode(['error' => xlt('Error retrieving fax')]);
        }
    }

    /**
     * Read a fax's bytes from wherever this vendor keeps them: the local queue
     * for received faxes, the provider for sent ones.
     */
    private function readFaxBytes(string $faxId, FaxDocumentService $documents): ?string
    {
        $queued = $documents->getFaxDocument($faxId);
        if ($queued !== null && ($queued['direction'] ?? 'inbound') === 'inbound') {
            return $documents->readQueuedFaxContent($faxId);
        }

        return $this->downloadFaxContent($faxId);
    }

    /**
     * Stage fax bytes to an encrypted-at-rest temp file for the download flow.
     * The path is read back by disposeDocument()/sendFile() in the shared
     * disposal trait, so a Sinch download is disposed exactly like every other
     * vendor's.
     *
     * @return string Absolute file path
     */
    private function saveFaxToFile(string $data, string $faxId): string
    {
        $dir = $this->baseDir;
        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $filePath = $dir . DIRECTORY_SEPARATOR . 'Fax_' . preg_replace('/[^A-Za-z0-9_-]/', '', $faxId) . '.pdf';
        file_put_contents($filePath, $this->crypto->encryptForFilesystem($data));

        return $filePath;
    }

    /**
     * File a received fax to a patient chart through the shared
     * FaxDocumentService, so it lands as a patient Document in the FAX category
     * exactly like every other vendor's received fax, then release the
     * provider's stored copy.
     */
    public function assignFax(): string
    {
        if (!$this->authenticate()) {
            return (string)json_encode(['error' => xlt('Not authorized')]);
        }

        $faxIdRaw = $this->getRequest('fax_id');
        $patientIdRaw = $this->getRequest('patient_id');
        $faxId = is_scalar($faxIdRaw) ? (string)$faxIdRaw : '';
        $patientId = is_numeric($patientIdRaw) ? (int)$patientIdRaw : 0;

        if ($faxId === '' || $patientId <= 0) {
            return (string)json_encode(['error' => xlt('Missing fax ID or patient ID')]);
        }

        try {
            $result = (new FaxDocumentService())->assignFaxToPatient($faxId, $patientId);
            if (empty($result['success'])) {
                return (string)json_encode(['error' => xlt('Failed to store fax document')]);
            }

            // Filed to the chart - release the provider's stored copy.
            $this->releaseUpstreamDocument($faxId);

            return (string)json_encode([
                'success' => true,
                'document_id' => $result['document_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('Sinch assignFax failed', ['exception' => $e, 'faxId' => $faxId]);

            return (string)json_encode(['error' => xlt('Failed to assign fax')]);
        }
    }

    /**
     * Render the inbox and sent list from the local fax queue.
     *
     * Identical in both ingest modes - only ingestIfDue() behaves differently,
     * so a site that switches between polling and webhooks sees the same inbox,
     * the same row actions and the same history throughout.
     */
    public function getPending(): string
    {
        if (!$this->authenticate()) {
            return $this->authErrorDefault;
        }

        $this->ingestIfDue();

        $fromRaw = $this->getRequest('datefrom');
        $toRaw = $this->getRequest('dateto');
        $fromTs = strtotime(is_scalar($fromRaw) ? (string)$fromRaw : '');
        $toTs = strtotime(is_scalar($toRaw) ? (string)$toRaw : '');
        if ($fromTs === false) {
            $fromTs = (int)strtotime('-30 days');
        }
        if ($toTs === false) {
            $toTs = time();
        }

        // Index 0 = received (inbound), 1 = sent (outbound), 2 = reserved.
        $responseMsg = [0 => '', 1 => '', 2 => xlt('Not Implemented')];

        try {
            $rows = (new FaxDocumentService())->fetchQueueRange(
                date('Y-m-d H:i:s', $fromTs) ,
                date('Y-m-d 23:59:59', $toTs)
            );

            foreach ($rows as $row) {
                $faxId = (string)($row['job_id'] ?? '');
                if ($faxId === '') {
                    continue;
                }
                $isInbound = ($row['direction'] ?? 'inbound') !== 'outbound';
                $status = (string)($row['status'] ?? 'unknown');
                $details = json_decode((string)($row['details_json'] ?? '{}'), true);
                $details = is_array($details) ? $details : [];
                $pages = match (true) {
                    is_numeric($details['pages'] ?? null) => (int)$details['pages'],
                    is_numeric($details['num_pages'] ?? null) => (int)$details['num_pages'],
                    default => 0,
                };

                $receivedUtc = (string)($row['receive_date'] ?? '');
                $dateLocal = $receivedUtc !== ''
                    ? $this->formatQueueDate($receivedUtc)
                    : (string)($row['date'] ?? '');
                $pagesCol = $pages > 0 ? (text((string)$pages) . ' ' . xlt('pages')) : '';
                $isTerminal = in_array(strtoupper($status), self::TERMINAL_FAX_STATUSES, true)
                    || in_array($status, ['received', 'delivered', 'completed'], true);

                $cells = '<tr><td>' . text($dateLocal) . '</td><td>'
                    . text((string)($row['calling_number'] ?? '')) . '</td><td>'
                    . text((string)($row['called_number'] ?? '')) . '</td><td>' . $pagesCol . '</td><td>';

                if (!$isInbound) {
                    $actions = $isTerminal
                        ? $this->documentAction($faxId, 'false', 'fa-file-pdf', xla('View fax document'))
                        . $this->documentAction($faxId, 'true', 'fa-file-download', xla('Download fax document'))
                        : '';
                    $responseMsg[1] .= $cells . text($status) . '</td><td class="text-left">' . $actions . '</td></tr>';
                    continue;
                }

                if (!$isTerminal) {
                    // In-flight: shown for visibility, no actions yet.
                    $responseMsg[0] .= $cells . "<span class='badge badge-secondary'>" . text($status)
                        . '</span></td><td class="text-left"></td></tr>';
                    continue;
                }

                $actions = "<a role='button' href='#' onclick=\"assignFaxToPatient(" . attr_js($faxId)
                    . ")\"><i class='fa fa-chart-simple mr-2' title='" . xla('File fax to a patient chart') . "'></i></a>"
                    . $this->documentAction($faxId, 'false', 'fa-file-pdf', xla('View fax document'))
                    . $this->documentAction($faxId, 'true', 'fa-file-download', xla('Download fax document'))
                    . "<a role='button' href='#' onclick=\"getDocument(event, null, " . attr_js($faxId)
                    . ", 'false', 'true')\"><i class='text-danger fa fa-trash mr-2' title='"
                    . xla('Delete fax') . "'></i></a>";

                $responseMsg[0] .= $cells . text($status) . '</td><td class="text-left">' . $actions . '</td></tr>';
            }
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('Sinch getPending failed', ['exception' => $e]);
        }

        if ($responseMsg[0] === '') {
            $responseMsg[0] = xlt('Currently inbox is empty.');
        }
        if ($responseMsg[1] === '') {
            $responseMsg[1] = xlt('No sent faxes found.');
        }

        echo json_encode($responseMsg);
        exit();
    }

    /**
     * Render a stored UTC queue timestamp in the server's local zone.
     *
     * Only `receive_date` is written in UTC; the `date` column is the database's
     * own NOW(), so callers pass that one through unconverted.
     */
    private function formatQueueDate(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        $parsed = date_create_immutable($stored, new \DateTimeZone('UTC'));
        if (!$parsed instanceof \DateTimeImmutable) {
            return $stored;
        }

        return $parsed->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('M j, Y g:i:sa T');
    }

    /**
     * Build one view/download icon routed through the shared getDocument()
     * handler, so every Sinch row uses the same UI contract as other vendors.
     */
    private function documentAction(string $faxId, string $download, string $icon, string $title): string
    {
        return "<a role='button' href='#' onclick=\"getDocument(event, null, " . attr_js($faxId) . ', '
            . attr_js($download) . ")\"><i class='fa " . attr($icon) . " mr-2' title='" . $title . "'></i></a>";
    }

    /**
     * Download the rendered fax PDF from Sinch.
     */
    private function downloadFaxContent(string $faxId): ?string
    {
        if ($this->client === null || $faxId === '') {
            return null;
        }
        try {
            return $this->client->fax->v3->faxes->getContext($faxId)->downloadContent();
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('Sinch fax download failed', ['exception' => $e, 'faxId' => $faxId]);

            return null;
        }
    }

    /**
     * Release the document Sinch is storing for a fax, once it has been filed
     * to a chart, downloaded, or dismissed. Sinch retains the fax record
     * itself; only the stored PHI document is removed.
     */
    private function releaseUpstreamDocument(string $faxId): bool
    {
        if ($this->client === null || $faxId === '') {
            return false;
        }
        try {
            return $this->client->fax->v3->faxes->getContext($faxId)->deleteContent();
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error(
                'Sinch fax content release failed',
                ['exception' => $e, 'faxId' => $faxId]
            );

            return false;
        }
    }

    public function forwardFax(): string
    {
        return (string)json_encode(['error' => xlt('Forward fax not yet implemented')]);
    }

    /**
     * Stage an uploaded fax document for a later send.
     */
    public function faxProcessUploads(): string
    {
        $upload = $_FILES['fax'] ?? null;

        return is_array($upload)
            ? $this->uploadStaging->processUpload($this->baseDir, $upload)
            : '';
    }

    public function getCallLogs(): string
    {
        return xlt('Not Supported');
    }
}
