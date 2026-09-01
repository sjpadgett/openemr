<?php

/**
 * Sinch Fax API v3 client.
 *
 * Implements the module's fax channel against Sinch's project-scoped REST API.
 * Two things distinguish it from the other fax vendors:
 *
 *  - Outbound documents are posted to Sinch as base64 content in the request
 *    body, so nothing has to be staged at a URL the provider can reach. There
 *    is no public media handout for Sinch, which means the fax path works on
 *    sites that are not reachable from the internet.
 *  - Sinch has no "delete the fax" operation. DELETE /faxes/{id}/file frees the
 *    stored document while the fax record itself remains listable. Handled
 *    faxes are therefore recognized by the absence of stored content
 *    (hasFile === false) rather than by disappearing upstream.
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
use OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest\Client;
use OpenEMR\Modules\FaxSMS\RestClient\Sinch\Rest\FaxInstance;
use OpenEMR\Modules\FaxSMS\Service\FaxMailer;
use OpenEMR\Modules\FaxSMS\Service\FaxUploadStaging;

class SinchFaxClient extends AppDispatch implements FaxChannelInterface, FaxDocumentDisposalInterface
{
    use FaxDocumentDisposalTrait;

    /** Max faxes to pull from the Sinch API in a single check. */
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

        $this->projectId = is_string($credentials['sinch_project_id'] ?? null)
            ? trim($credentials['sinch_project_id']) : '';
        $this->keyId = is_string($credentials['sinch_key_id'] ?? null)
            ? trim($credentials['sinch_key_id']) : '';
        $this->keySecret = is_string($credentials['sinch_key_secret'] ?? null)
            ? trim($credentials['sinch_key_secret']) : '';
        $this->serviceId = is_string($credentials['sinch_service_id'] ?? null)
            ? trim($credentials['sinch_service_id']) : '';
        // Ensure the 'from' fax number is always in E.164 format.
        $this->faxNumber = $this->formatPhone(
            is_string($credentials['sinch_fax_number'] ?? null) ? $credentials['sinch_fax_number'] : ''
        );

        return $credentials;
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
     * Live count of inbound faxes awaiting handling, read straight from Sinch.
     * Handled faxes have had their stored document released, so the count of
     * inbound faxes that still hold content is the unhandled count.
     */
    public function fetchReminderCount(): string
    {
        if ($this->client === null) {
            return (string)json_encode(['count' => 0]);
        }
        try {
            $faxes = $this->client->fax->v3->faxes->read(
                [
                    'direction' => 'INBOUND',
                    'createTimeFrom' => gmdate('Y-m-d\TH:i:s\Z', (int)(strtotime('-30 days') ?: time())),
                ],
                self::FAX_LIST_LIMIT
            );
            $count = 0;
            foreach ($faxes as $fax) {
                if ($fax->status === 'COMPLETED' && $fax->hasFile !== false) {
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
     *   delete   -> release the stored document at Sinch; returns 'success'.
     *   download -> stage an encrypted temp copy for disposeDocument to stream,
     *               release the provider copy, return {base64, mime, filename, path}.
     *   view     -> return {base64, mime, filename} for the in-modal viewer.
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
            if ($isDelete) {
                $this->releaseUpstreamDocument($faxId);
                return (string)json_encode('success');
            }

            $rawData = $this->downloadFaxContent($faxId);
            if ($rawData === null || $rawData === '') {
                return (string)json_encode(['error' => xlt('Fax document not available from provider')]);
            }

            if ($isDownload) {
                // The user is taking it: stage an encrypted temp copy for
                // disposeDocument to stream, then release the provider copy to
                // honor the "downloaded -> no longer available here" contract.
                $filePath = $this->saveFaxToFile($rawData, $faxId);
                $this->setSession('where', $filePath);
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
        $filePath = $dir . DIRECTORY_SEPARATOR . 'Fax_' . $faxId . '.pdf';
        file_put_contents($filePath, $this->crypto->encryptForFilesystem($data));

        return $filePath;
    }

    /**
     * File a received fax to a patient chart: download it from Sinch, store it
     * through the shared FaxDocumentService (so it lands as a patient Document
     * in the FAX category like every other vendor's received fax), then release
     * the provider's stored copy.
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
            $fax = $this->fetchUpstreamFax($faxId);
            $rawData = $this->downloadFaxContent($faxId);
            if ($rawData === null || $rawData === '') {
                return (string)json_encode(['error' => xlt('Fax document not available from provider')]);
            }

            $result = (new FaxDocumentService())->storeFaxDocument(
                $faxId,
                $rawData,
                $fax?->from ?? '',
                $patientId
            );

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
     * Render the fax inbox and sent list directly from the live Sinch list.
     *
     * Stateless by design (the RingCentral/SignalWire model): Sinch is the
     * system of record, so this neither writes to nor reads from
     * oe_faxsms_queue. Because Sinch keeps the fax record after its document is
     * released, an inbound fax is treated as handled once it no longer has
     * stored content. A provider response that omits hasFile leaves the row
     * visible rather than hiding it, so nothing is ever silently dropped from
     * the inbox.
     */
    public function getPending(): string
    {
        if (!$this->authenticate()) {
            return $this->authErrorDefault;
        }

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

        if ($this->client !== null) {
            try {
                $faxes = $this->client->fax->v3->faxes->read(
                    [
                        'createTimeFrom' => gmdate('Y-m-d\TH:i:s\Z', (int)strtotime(date('Y-m-d', $fromTs) . ' 00:00:01 UTC')),
                        'createTimeTo' => gmdate('Y-m-d\TH:i:s\Z', (int)strtotime(date('Y-m-d', $toTs) . ' 23:59:59 UTC')),
                    ],
                    self::FAX_LIST_LIMIT
                );

                foreach ($faxes as $fax) {
                    $faxId = (string)($fax->id ?? '');
                    if ($faxId === '') {
                        continue;
                    }
                    $status = (string)($fax->status ?? 'UNKNOWN');
                    $from = (string)($fax->from ?? '');
                    $to = (string)($fax->to ?? '');
                    $pages = (int)($fax->numberOfPages ?? 0);
                    $dateLocal = $fax->createTime
                        ? $fax->createTime->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                            ->format('M j, Y g:i:sa T')
                        : '';

                    // Column mapping matches the shared non-RC fax table headers:
                    //   Status = status, Pages = numberOfPages.
                    $statusCol = text($status);
                    $resultCol = $pages > 0 ? (text((string)$pages) . ' ' . xlt('pages')) : '';
                    $isTerminal = in_array($status, self::TERMINAL_FAX_STATUSES, true);

                    if (($fax->direction ?? '') === 'OUTBOUND') {
                        $actions = '';
                        if ($status === 'COMPLETED' && $fax->hasFile !== false) {
                            $actions .= $this->documentAction($faxId, 'false', 'fa-file-pdf', xla('View fax document'));
                            $actions .= $this->documentAction($faxId, 'true', 'fa-file-download', xla('Download fax document'));
                        }
                        $responseMsg[1] .= '<tr><td>' . text($dateLocal) . '</td><td>' . text($from) . '</td><td>'
                            . text($to) . '</td><td>' . $resultCol . '</td><td>' . $statusCol
                            . '</td><td class="text-left">' . $actions . '</td></tr>';
                        continue;
                    }

                    // Inbound. Hide failures; show in-progress; act on completed.
                    if (in_array($status, self::FAILED_FAX_STATUSES, true)) {
                        continue;
                    }
                    // Already filed/downloaded: Sinch keeps the record but the
                    // document is gone, so it is no longer pending.
                    if ($fax->hasFile === false) {
                        continue;
                    }

                    $actions = '';
                    if ($isTerminal) {
                        $actions .= "<a role='button' href='#' onclick=\"assignFaxToPatient(" . attr_js($faxId)
                            . ")\"><i class='fa fa-chart-simple mr-2' title='" . xla('File fax to a patient chart') . "'></i></a>";
                        $actions .= $this->documentAction($faxId, 'false', 'fa-file-pdf', xla('View fax document'));
                        $actions .= $this->documentAction($faxId, 'true', 'fa-file-download', xla('Download fax document'));
                        $actions .= "<a role='button' href='#' onclick=\"getDocument(event, null, " . attr_js($faxId)
                            . ", 'false', 'true')\"><i class='text-danger fa fa-trash mr-2' title='"
                            . xla('Delete fax') . "'></i></a>";
                    } else {
                        // In-progress: shown for visibility, no actions yet.
                        $statusCol = "<span class='badge badge-secondary'>" . text($status) . '</span>';
                    }

                    $responseMsg[0] .= '<tr><td>' . text($dateLocal) . '</td><td>' . text($from) . '</td><td>'
                        . text($to) . '</td><td>' . $resultCol . '</td><td>' . $statusCol
                        . '</td><td class="text-left">' . $actions . '</td></tr>';
                }
            } catch (\Throwable $e) {
                ServiceContainer::getLogger()->error('Sinch getPending failed', ['exception' => $e]);
            }
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
     * Build one view/download icon routed through the shared getDocument()
     * handler, so every Sinch row uses the same UI contract as other vendors.
     */
    private function documentAction(string $faxId, string $download, string $icon, string $title): string
    {
        return "<a role='button' href='#' onclick=\"getDocument(event, null, " . attr_js($faxId) . ', '
            . attr_js($download) . ")\"><i class='fa " . attr($icon) . " mr-2' title='" . $title . "'></i></a>";
    }

    /**
     * Fetch a single fax resource from Sinch by id.
     */
    private function fetchUpstreamFax(string $faxId): ?FaxInstance
    {
        if ($this->client === null || $faxId === '') {
            return null;
        }
        try {
            return $this->client->fax->v3->faxes->getContext($faxId)->fetch();
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error('Sinch fax fetch failed', ['exception' => $e, 'faxId' => $faxId]);
            return null;
        }
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
