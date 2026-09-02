<?php

/**
 * A fax record on its way into the local queue.
 *
 * The queue's older ingest path accepts a bare `object` because it was shaped
 * around etherFAX's own result class, which means every field read is untyped.
 * New callers hand over this instead, so the values are typed once at
 * construction rather than cast defensively at each use.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Service;

final readonly class OutboundFaxRecord
{
    /**
     * @param string $jobId  Vendor's fax id; the queue's job_id.
     * @param string $from   Sending fax number.
     * @param string $to     Receiving fax number.
     * @param string $status Vendor status, lower-cased.
     * @param int    $pages  Page count, 0 when unknown.
     * @param string $sentOn UTC 'Y-m-d H:i:s' timestamp.
     */
    public function __construct(
        public string $jobId,
        public string $from = '',
        public string $to = '',
        public string $status = 'queued',
        public int $pages = 0,
        public string $sentOn = '',
    ) {
    }

    public function sentOnOrNow(): string
    {
        return $this->sentOn !== '' ? $this->sentOn : gmdate('Y-m-d H:i:s');
    }
}
