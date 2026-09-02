<?php

/**
 * Whether the fax vendor retains the document after a fax completes.
 *
 * Sinch exposes this per fax service (saveInboundFaxDocuments /
 * saveOutboundFaxDocuments). Turning it off is the maximum-privacy posture:
 * nothing is retained vendor-side, so the callback payload is the only copy
 * that will ever exist. Turning it on lets a missed callback be recovered by
 * downloading the document later.
 *
 * Neither is more "HIPAA compliant" than the other — a signed BAA covers a
 * vendor holding PHI at rest, which is what a BAA is for. They are different
 * answers to a different question: how much do you want to depend on the
 * vendor's retention, and how much do you want to depend on your endpoint
 * being reachable at the moment a fax arrives.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\FaxSMS\Enums;

enum VendorDocumentStorage: string
{
    /**
     * The vendor keeps the document until we release it. Documents can be
     * downloaded on demand, so a missed webhook is fully recoverable and
     * polling alone is a complete delivery mechanism.
     */
    case RETAINED = 'retained';

    /**
     * The vendor keeps nothing. The document arrives in the callback or not at
     * all: there is no URL to fetch it from afterwards, and polling can only
     * ever learn that a fax existed.
     */
    case NONE = 'none';

    public function getTranslatedLabel(): string
    {
        return match ($this) {
            self::RETAINED => xlt('Sinch stores documents (recommended - missed faxes are recoverable)'),
            self::NONE => xlt('Sinch stores nothing (maximum privacy - requires webhook delivery)'),
        };
    }

    /**
     * Whether a document can be fetched from the vendor after the fact. When
     * false, every download, on-demand view and content-release call is
     * pointless and must be skipped rather than attempted and logged as a
     * failure.
     */
    public function isFetchable(): bool
    {
        return $this === self::RETAINED;
    }

    /**
     * Whether this storage posture can deliver documents without a webhook.
     * NONE cannot: polling learns that a fax arrived but can never retrieve it.
     */
    public function supportsPollOnlyDelivery(): bool
    {
        return $this === self::RETAINED;
    }

    /**
     * Default to RETAINED for anything unrecognized: it is the posture that
     * cannot silently lose a document, so an unset or corrupt value degrades
     * to the safer behaviour.
     */
    public static function fromValue(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::RETAINED : self::RETAINED;
    }

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
