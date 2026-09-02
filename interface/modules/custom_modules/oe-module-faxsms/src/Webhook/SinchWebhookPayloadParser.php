<?php

/**
 * Parses a Sinch Fax API v3 callback into an {@see InboundFaxPayload}.
 *
 * Sinch posts either shape depending on the service's webhookContentType:
 *
 *   application/json      {"event":"INCOMING_FAX","eventTime":"...",
 *                          "fax":{...},"file":"<base64>","fileType":"PDF"}
 *   multipart/form-data   event / eventTime / fax form fields, where `fax` is a
 *                         JSON *string*, and the document as an attached part.
 *
 * Both INCOMING_FAX and FAX_COMPLETED are handled: the first is a received fax,
 * the second is the terminal status of one we sent, which is worth recording so
 * the sent list reflects delivery without an extra poll.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Webhook;

final class SinchWebhookPayloadParser implements InboundFaxPayloadParserInterface
{
    /** Events this parser recognizes; anything else is ignored, not an error. */
    private const HANDLED_EVENTS = ['INCOMING_FAX', 'FAX_COMPLETED'];

    public function parse(WebhookRequestContext $context): ?InboundFaxPayload
    {
        $envelope = $context->isJson()
            ? $this->decodeJsonEnvelope($context->rawBody)
            : $context->formFields;

        if ($envelope === null || $envelope === []) {
            return null;
        }

        $event = $this->stringOf($envelope['event'] ?? null);
        if (!in_array($event, self::HANDLED_EVENTS, true)) {
            return null;
        }

        // In multipart the `fax` field arrives as a JSON string rather than a
        // decoded structure, so accept both.
        $fax = $envelope['fax'] ?? null;
        if (is_string($fax)) {
            $decoded = json_decode($fax, true);
            $fax = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($fax)) {
            return null;
        }

        $faxId = $this->stringOf($fax['id'] ?? null);
        if ($faxId === '') {
            return null;
        }

        $direction = strtoupper($this->stringOf($fax['direction'] ?? 'INBOUND')) === 'OUTBOUND'
            ? 'outbound'
            : 'inbound';

        [$content, $mimeType] = $this->extractDocument($context, $envelope);

        return new InboundFaxPayload(
            faxId: $faxId,
            from: $this->stringOf($fax['from'] ?? null),
            to: $this->stringOf($fax['to'] ?? null),
            direction: $direction,
            status: strtolower($this->stringOf($fax['status'] ?? 'COMPLETED')),
            pages: is_numeric($fax['numberOfPages'] ?? null) ? (int)$fax['numberOfPages'] : 0,
            receivedOn: $this->normalizeTimestamp(
                $this->stringOf($fax['completedTime'] ?? $fax['createTime'] ?? $envelope['eventTime'] ?? null)
            ),
            content: $content,
            mimeType: $mimeType,
        );
    }

    /**
     * json_decode gives back an untyped array, and a hostile caller can send a
     * JSON array (integer keys) just as easily as an object. Declaring the key
     * type honestly keeps that ambiguity visible to callers rather than
     * asserting a shape the wire never guaranteed; every read in parse() is
     * already guarded by is_array()/stringOf().
     *
     * @return array<array-key, mixed>|null
     */
    private function decodeJsonEnvelope(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Pull the document out of whichever transport carried it.
     *
     * @param array<array-key, mixed> $envelope
     * @return array{0: string|null, 1: string}
     */
    private function extractDocument(WebhookRequestContext $context, array $envelope): array
    {
        // JSON: base64 in `file`, with `fileType` naming the format.
        $inlineFile = $envelope['file'] ?? null;
        if (is_string($inlineFile) && $inlineFile !== '') {
            $decoded = base64_decode($inlineFile, true);
            if (is_string($decoded) && $decoded !== '') {
                return [$decoded, $this->mimeForFileType($this->stringOf($envelope['fileType'] ?? 'PDF'))];
            }
        }

        // Multipart: an attached part, preferring one actually named "file".
        $files = $context->files;
        $candidate = $files['file'] ?? (reset($files) ?: null);
        if (is_array($candidate)) {
            $tmpName = $candidate['tmp_name'] ?? null;
            $error = $candidate['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_OK && is_string($tmpName) && is_readable($tmpName)) {
                $bytes = file_get_contents($tmpName);
                if (is_string($bytes) && $bytes !== '') {
                    return [$bytes, 'application/pdf'];
                }
            }
        }

        return [null, 'application/pdf'];
    }

    private function mimeForFileType(string $fileType): string
    {
        return match (strtoupper($fileType)) {
            'TIF', 'TIFF' => 'image/tiff',
            'PNG' => 'image/png',
            'JPG', 'JPEG' => 'image/jpeg',
            'TXT' => 'text/plain',
            default => 'application/pdf',
        };
    }

    /**
     * Normalize a vendor timestamp to the UTC 'Y-m-d H:i:s' the queue stores.
     * Falls back to now so a fax is never dropped over an unreadable date.
     */
    private function normalizeTimestamp(string $raw): string
    {
        if ($raw !== '') {
            $parsed = date_create_immutable($raw);
            if ($parsed instanceof \DateTimeImmutable) {
                return $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
