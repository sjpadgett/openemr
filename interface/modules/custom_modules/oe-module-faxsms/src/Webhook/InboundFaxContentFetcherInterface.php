<?php

/**
 * Fetches a fax document from the vendor when the callback did not carry it.
 *
 * A vendor can be configured to post metadata only, so the receiver needs a way
 * back to the API without knowing which API it is.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Webhook;

interface InboundFaxContentFetcherInterface
{
    /**
     * @return string|null Raw document bytes, or null when unavailable.
     */
    public function fetchFaxContent(string $faxId): ?string;
}
