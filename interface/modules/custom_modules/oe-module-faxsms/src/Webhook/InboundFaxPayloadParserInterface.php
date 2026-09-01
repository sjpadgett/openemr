<?php

/**
 * Normalizes one vendor's webhook body into an {@see InboundFaxPayload}.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Webhook;

interface InboundFaxPayloadParserInterface
{
    /**
     * @return InboundFaxPayload|null Null when the body is unparseable or is an
     *                                event this receiver does not handle.
     */
    public function parse(WebhookRequestContext $context): ?InboundFaxPayload;
}
