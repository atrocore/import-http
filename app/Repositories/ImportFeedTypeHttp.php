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

namespace ImportHttp\Repositories;

use Atro\ConnectionType\HttpConnectionInterface;
use Atro\Core\Exceptions\BadRequest;
use Atro\Core\Templates\Repositories\Subtype;
use Espo\ORM\Entity;

/**
 * An HTTP import feed imports the responses of the requests of its connection.
 */
class ImportFeedTypeHttp extends Subtype
{
    protected function beforeSave(Entity $entity, array $options = [])
    {
        parent::beforeSave($entity, $options);

        if (!empty($entity->get('connectionId'))) {
            $connection = $this->getEntityManager()->getRepository('Connection')->get($entity->get('connectionId'));
            if (!empty($connection)) {
                $connectionClass = $this->getMetadata()->get(['app', 'connectionTypes', $connection->get('type')]);
                if (!is_a($connectionClass, HttpConnectionInterface::class, true)) {
                    throw new BadRequest('Wrong connection type.');
                }
            }
        }
    }
}
