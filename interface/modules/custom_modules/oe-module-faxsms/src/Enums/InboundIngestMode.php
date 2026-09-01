<?php

/**
 * How a fax vendor's inbound faxes reach the local queue.
 *
 * The mode decides *when* ingest runs, never what the inbox is: both modes
 * write into `oe_faxsms_queue` and every renderer, row action and disposal path
 * reads from that queue. A site therefore gets identical behaviour either way,
 * and switching modes cannot orphan faxes that arrived under the other one.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Enums;

enum InboundIngestMode: string
{
    /**
     * Pull. The inbox view asks the vendor for recent faxes and ingests any it
     * has not seen. Needs no public endpoint and works on a site the internet
     * cannot reach; latency is bounded by how often someone opens the inbox or
     * the background task runs.
     */
    case POLL = 'poll';

    /**
     * Push. The vendor posts each fax to this site as it arrives. Near-instant
     * and makes no repeated API calls, but requires a publicly reachable
     * OpenEMR and correctly configured webhook credentials.
     *
     * Webhook mode still reconciles: the inbox runs the same ingest as POLL on
     * a throttle, so a missed, mis-configured or briefly unreachable webhook
     * degrades to polling instead of silently losing faxes.
     */
    case WEBHOOK = 'webhook';

    public function getTranslatedLabel(): string
    {
        return match ($this) {
            self::POLL => xlt('Polling (no public endpoint required)'),
            self::WEBHOOK => xlt('Webhook (vendor pushes faxes to this server)'),
        };
    }

    /**
     * True when the inbox view owns ingest for this mode. Webhook mode also
     * returns true, because its reconcile sweep uses the same pull path - the
     * caller decides how often to honour it.
     */
    public function ingestsOnView(): bool
    {
        return match ($this) {
            self::POLL => true,
            self::WEBHOOK => true,
        };
    }

    /**
     * Seconds between reconcile sweeps in this mode. POLL ingests on every
     * view; WEBHOOK only sweeps occasionally, since the push path is expected
     * to have already delivered the fax.
     */
    public function reconcileIntervalSeconds(): int
    {
        return match ($this) {
            self::POLL => 0,
            self::WEBHOOK => 900,
        };
    }

    /**
     * Create from stored configuration, defaulting to POLL for anything
     * unrecognized. POLL is the safe default: it needs no public endpoint, so a
     * missing or corrupt setting can never leave a site silently waiting on a
     * webhook that was never configured.
     */
    public static function fromValue(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::POLL : self::POLL;
    }

    /**
     * Render <option> elements for the inbound-mode dropdown.
     */
    public static function renderSelectOptions(self $selected): string
    {
        $html = '';
        foreach (self::cases() as $case) {
            $selectedAttr = $case === $selected ? ' selected' : '';
            $html .= '<option value="' . attr($case->value) . '"' . $selectedAttr . '>'
                . text($case->getTranslatedLabel()) . '</option>';
        }

        return $html;
    }
}
