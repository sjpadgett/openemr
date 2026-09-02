<?php

/**
 * A vendor-neutral inbound fax notification.
 *
 * Each vendor's parser normalizes its own callback shape into this, so the
 * receiver, the queue ingest and the tests never learn a vendor's wire format.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Webhook;

final readonly class InboundFaxPayload
{
    /**
     * @param string      $faxId      Vendor's id for the fax; the queue's job_id.
     * @param string      $from       Sending fax number.
     * @param string      $to         Receiving fax number.
     * @param string      $direction  'inbound' or 'outbound'.
     * @param string      $status     Vendor status, already lower-cased.
     * @param int         $pages      Page count, 0 when unknown.
     * @param string      $receivedOn UTC 'Y-m-d H:i:s' timestamp.
     * @param string|null $content    Raw document bytes when the callback carried them.
     * @param string      $mimeType   Media type of $content.
     */
    public function __construct(
        public string $faxId,
        public string $from = '',
        public string $to = '',
        public string $direction = 'inbound',
        public string $status = 'received',
        public int $pages = 0,
        public string $receivedOn = '',
        public ?string $content = null,
        public string $mimeType = 'application/pdf',
    ) {
    }

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }

    public function hasContent(): bool
    {
        return $this->content !== null && $this->content !== '';
    }

    /** Same payload with document bytes attached, for a late content fetch. */
    public function withContent(string $content, string $mimeType = 'application/pdf'): self
    {
        return new self(
            $this->faxId,
            $this->from,
            $this->to,
            $this->direction,
            $this->status,
            $this->pages,
            $this->receivedOn,
            $content,
            $mimeType,
        );
    }
}
