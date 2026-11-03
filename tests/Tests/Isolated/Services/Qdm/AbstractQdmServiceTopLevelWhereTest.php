<?php

/**
 * AbstractQdmService::hasTopLevelWhere(): executeQuery() appends the patient filter with AND only
 * when the query has a WHERE of its own, not one inside a subquery or a parenthesized join.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Services\Qdm;

use OpenEMR\Services\Qdm\Services\AbstractQdmService;
use OpenEMR\Services\Qdm\Services\QdmForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AbstractQdmServiceTopLevelWhereTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function queries(): array
    {
        $deleted = AbstractQdmService::notInDeletedForm('O.id', 'O.pid', QdmForm::ProcedureOrder);
        return [
            'no where' => ['SELECT * FROM t', false],
            'own where' => ['SELECT * FROM t WHERE t.a = 1', true],
            'own where, any case' => ['select * from t where t.a = 1', true],
            'where only in a join subquery' => ["SELECT * FROM t LEFT JOIN o O ON O.id = t.id AND $deleted", false],
            'where only in a column subquery' => ['SELECT (SELECT 1 FROM u WHERE u.id = t.id) AS x FROM t', false],
            'where only in a nested join' => ['SELECT * FROM t LEFT JOIN (a JOIN b ON (b.id = a.id)) ON a.t = t.id', false],
            'own where after a subquery' => ["SELECT * FROM t JOIN o O ON O.id = t.id WHERE $deleted", true],
            'a column named like where' => ['SELECT t.somewhere FROM t', false],
        ];
    }

    #[DataProvider('queries')]
    public function testOnlyAWhereOutsideParenthesesCounts(string $sql, bool $expected): void
    {
        $this->assertSame($expected, AbstractQdmService::hasTopLevelWhere($sql));
    }
}
