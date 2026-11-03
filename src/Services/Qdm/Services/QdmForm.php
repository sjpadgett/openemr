<?php

/**
 * The encounter forms whose rows the QDM services read, by the directory forms.formdir records.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\Qdm\Services;

enum QdmForm: string
{
    case CarePlan = 'care_plan';
    case Observation = 'observation';
    case ProcedureOrder = 'procedure_order';
}
