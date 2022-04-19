<?php

/**
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2021 Ken Chapple <ken@mi-squared.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU GeneralPublic License 3
 */

namespace OpenEMR\Services\Qdm\Services;

use OpenEMR\Cqm\Qdm\BaseTypes\DateTime;
use OpenEMR\Services\Qdm\Interfaces\QdmServiceInterface;

abstract class AbstractObservationService extends AbstractQdmService implements QdmServiceInterface
{
    /**
     * Types of observations in ob_type field
     */
    const OB_TYPE_ASSESSMENT = 'assessment';
    const OB_TYPE_DIAGNOSTIC_STUDY = 'procedure_diagnostic';
    const OB_TYPE_PHYSICAL_EXAM = 'physical_exam_performed';

    abstract public function getObservationType();

    abstract public function getModelClass();

    /**
     * Return the SQL query string that will retrieve these record types from the OpenEMR database
     *
     * @return string
     */
    public function getSqlStatement()
    {
        $observation_type = add_escape_custom($this->getObservationType());
        $sql = "SELECT pid, encounter, `date`, code, code_type, ob_value, ob_unit, description, ob_code, ob_type, ob_status,
                ob_reason_status, ob_reason_code
                FROM form_observation
                WHERE ob_type = '$observation_type'
                ";
        return $sql;
    }

    public function makeResult($record)
    {
        return $this->makeQdmCode($record['ob_code']);
    }

    /**
     * @param array $record
     * @return mixed
     * @throws \Exception
     *
     * Map an OpenEMR record into a QDM model
     */
    public function makeQdmModel(array $record)
    {
        $modelClass = $this->getModelClass();
        $qdmModel = new $modelClass([
            'relevantDatetime' => new DateTime([
                'date' => $record['date']
            ]),
            'authorDatetime' => new DateTime([
                'date' => $record['date']
            ]),
        ]);

        $qdmModel->result = $this->makeResult($record);

        // If the reason status is "negated" then add the code to negation rationale, otherwise add to reason
        if (!empty($record['ob_reason_code'])) {
            if ($record['ob_reason_status'] == parent::NEGATED) {
                $qdmModel->negationRationale = $this->makeQdmCode($record['ob_reason_code']);
            } else {
                $qdmModel->reason = $this->makeQdmCode($record['ob_reason_code']);
            }
        }

        $codes = $this->explodeAndMakeCodeArray($record['code']);
        foreach ($codes as $code) {
            $qdmModel->addCode($code);
        }

        return $qdmModel;
    }
}
