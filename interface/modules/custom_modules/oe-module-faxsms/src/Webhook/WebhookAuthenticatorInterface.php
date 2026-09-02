<?php

/**
 * Authenticates an inbound fax webhook before any payload is parsed or stored.
 *
 * Vendors differ enough here that this has to be a strategy rather than one
 * routine: SignalWire signs its callbacks, while Sinch does not sign at all
 * (its own SDK's signature check is a hardcoded "return true"), leaving a
 * shared secret, HTTP Basic credentials and a network allowlist as the only
 * things a receiver can actually verify. Keeping the choice behind this
 * interface means the receiver, the queue ingest and the endpoint are shared
 * while each vendor brings the strongest check it supports.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Webhook;

interface WebhookAuthenticatorInterface
{
    /**
     * @return bool True when the request may be processed. Implementations must
     *              fail closed: an unconfigured or partially configured
     *              authenticator returns false rather than allowing the request.
     */
    public function authenticate(WebhookRequestContext $context): bool;

    /**
     * Why the last authenticate() call failed, for server-side logging only.
     * Never surfaced to the caller - a webhook client learns nothing beyond 403.
     */
    public function lastFailureReason(): string;
}
