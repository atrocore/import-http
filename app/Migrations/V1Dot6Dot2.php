<?php
/**
 * AtroCore Software
 *
 * This source file is available under GNU General Public License version 3 (GPLv3).
 * Full copyright and license information is available in LICENSE.txt, located in the root directory.
 *
 * @copyright  Copyright (c) AtroCore GmbH (https://www.atrocore.com)
 * @license    GPLv3 (https://www.gnu.org/licenses/)
 */

declare(strict_types=1);

namespace ImportHttp\Migrations;

use Atro\Core\Migration\Base;

class V1Dot6Dot2 extends Base
{
    public function getMigrationDateTime(): ?\DateTime
    {
        return new \DateTime('2026-03-12 15:00:00');
    }

    public function up(): void
    {
        $rows = $this->getDbal()->createQueryBuilder()
            ->select('id', 'data')
            ->from($this->getDbal()->quoteIdentifier('import_feed'))
            ->where('data IS NOT NULL')
            ->andWhere('type = :type')
            ->setParameter('type', 'http')
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $data = json_decode($row['data'], true);
            if (!is_array($data)) {
                continue;
            }

            $connectionId = $data['feedFields']['httpConnectionId'] ?? null;
            $hasName = isset($data['feedFields']['httpConnectionName']);

            if (!$connectionId && !$hasName) {
                continue;
            }

            unset($data['feedFields']['httpConnectionId'], $data['feedFields']['httpConnectionName']);

            $qb = $this->getDbal()->createQueryBuilder()
                ->update($this->getDbal()->quoteIdentifier('import_feed'))
                ->set('data', ':data')
                ->setParameter('data', json_encode($data))
                ->where('id = :id')
                ->setParameter('id', $row['id']);

            if ($connectionId) {
                $qb->set('connection_id', ':val')
                    ->setParameter('val', $connectionId);
            }

            $qb->executeStatement();
        }
    }

    protected function exec(string $sql): void
    {
        try {
            $this->getPDO()->exec($sql);
        } catch (\Throwable $e) {
        }
    }
}