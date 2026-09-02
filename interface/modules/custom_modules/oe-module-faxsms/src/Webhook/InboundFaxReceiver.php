<?php

/**
 * Vendor-neutral inbound fax webhook receiver.
 *
 * Owns the order of operations that must not vary between vendors:
 * authenticate before anything is parsed, parse before anything is stored, and
 * ingest through the same FaxDocumentService path the polling ingest uses, so a
 * fax that arrives by push is indistinguishable downstream from one that
 * arrived by pull. Adding a vendor means supplying an authenticator and a
 * parser - never a second copy of this flow.
 *
 * Replay safety comes from the queue's unique (account, job_id) key: a fax
 * already in the queue is acknowledged and skipped, so a vendor's retries are
 * harmless rather than duplicating documents in a chart.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Webhook;

use OpenEMR\Modules\FaxSMS\Controller\FaxDocumentService;
use Psr\Log\LoggerInterface;

final readonly class InboundFaxReceiver
{
    /** Outcome codes returned to the endpoint, which maps them to HTTP status. */
    public const RESULT_ACCEPTED = 'accepted';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_IGNORED = 'ignored';
    public const RESULT_UNAUTHORIZED = 'unauthorized';
    public const RESULT_BAD_REQUEST = 'bad_request';
    public const RESULT_ERROR = 'error';

    public function __construct(
        private WebhookAuthenticatorInterface $authenticator,
        private InboundFaxPayloadParserInterface $parser,
        private FaxDocumentService $documents,
        private LoggerInterface $logger,
        private string $account = '',
        private ?InboundFaxContentFetcherInterface $contentFetcher = null,
    ) {
    }

    /**
     * @return string One of the RESULT_* constants.
     */
    public function handle(WebhookRequestContext $context): string
    {
        if (!$this->authenticator->authenticate($context)) {
            // Logged with the reason for the operator; the caller only ever
            // sees a bare 403, so a prober learns nothing about which layer
            // rejected it.
            $this->logger->warning('Inbound fax webhook rejected', [
                'reason' => $this->authenticator->lastFailureReason(),
                'remoteIp' => $context->remoteIp,
            ]);

            return self::RESULT_UNAUTHORIZED;
        }

        $payload = $this->parser->parse($context);
        if ($payload === null) {
            $this->logger->info('Inbound fax webhook carried no actionable event');

            return self::RESULT_BAD_REQUEST;
        }

        // Outbound completion events carry no document and have nothing to file;
        // record the terminal status if we are tracking that fax and stop.
        if (!$payload->isInbound()) {
            $this->updateOutboundStatus($payload);

            return self::RESULT_IGNORED;
        }

        try {
            if ($this->documents->getFaxDocument($payload->faxId) !== null) {
                $this->logger->info('Inbound fax webhook replayed an already-queued fax', [
                    'faxId' => $payload->faxId,
                ]);

                return self::RESULT_DUPLICATE;
            }

            $resolved = $this->ensureContent($payload);

            $this->documents->insertInboundFaxToQueue(
                $this->toQueueRecord($resolved),
                $this->account
            );

            $this->logger->info('Inbound fax stored from webhook', [
                'faxId' => $resolved->faxId,
                'hasContent' => $resolved->hasContent(),
            ]);

            return self::RESULT_ACCEPTED;
        } catch (\RuntimeException $e) {
            $this->logger->error('Inbound fax webhook ingest failed', [
                'exception' => $e,
                'faxId' => $payload->faxId,
            ]);

            return self::RESULT_ERROR;
        }
    }

    /**
     * When the vendor posted metadata only, pull the document over the API so
     * the queued fax is viewable without a later round trip.
     */
    private function ensureContent(InboundFaxPayload $payload): InboundFaxPayload
    {
        if ($payload->hasContent() || $this->contentFetcher === null) {
            return $payload;
        }

        $content = $this->contentFetcher->fetchFaxContent($payload->faxId);
        if ($content === null || $content === '') {
            $this->logger->warning('Inbound fax webhook carried no document and none could be fetched', [
                'faxId' => $payload->faxId,
            ]);

            return $payload;
        }

        return $payload->withContent($content, $payload->mimeType);
    }

    /**
     * Record the terminal status of a fax we sent, when it is one we queued.
     */
    private function updateOutboundStatus(InboundFaxPayload $payload): void
    {
        try {
            if ($this->documents->getFaxDocument($payload->faxId) === null) {
                return;
            }
            $this->documents->updateFaxStatus($payload->faxId, $payload->status);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Could not record outbound fax status from webhook', [
                'exception' => $e,
                'faxId' => $payload->faxId,
            ]);
        }
    }

    /**
     * Shape the payload the way FaxDocumentService::insertInboundFaxToQueue()
     * expects, so push and pull ingest share one storage routine.
     */
    private function toQueueRecord(InboundFaxPayload $payload): object
    {
        $record = new \stdClass();
        $record->JobId = $payload->faxId;
        $record->CallingNumber = $payload->from;
        $record->CalledNumber = $payload->to;
        $record->ReceivedOn = $payload->receivedOn !== '' ? $payload->receivedOn : gmdate('Y-m-d H:i:s');
        $record->PagesReceived = $payload->pages;
        // insertInboundFaxToQueue base64-decodes this field.
        $record->FaxImage = $payload->hasContent() ? base64_encode((string)$payload->content) : '';
        $record->DocumentParams = (object)['Type' => $payload->mimeType];

        return $record;
    }
}
