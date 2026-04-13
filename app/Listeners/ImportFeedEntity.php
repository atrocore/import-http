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

namespace ImportHttp\Listeners;

use Atro\ConnectionType\HttpConnectionInterface;
use Atro\Core\EventManager\Event;
use Atro\Core\Exceptions\BadRequest;
use Atro\Listeners\AbstractListener;

class ImportFeedEntity extends AbstractListener
{
    public function beforeSave(Event $event): void
    {
        $entity = $event->getArgument('entity');

        if($entity->get('type') !== 'httpPro') {
            return;
        }

        if (!empty($entity->get('connectionId'))) {
            $connection = $this->getEntityManager()->getEntity('Connection', $entity->get('connectionId'));
            if (!empty($connection)) {
                $connectionClass = $this->getMetadata()->get(['app', 'connectionTypes', $connection->get('type')]);
                if (!is_a($connectionClass, HttpConnectionInterface::class, true)) {
                    throw new BadRequest('Wrong connection type.');
                }
            }
        }
    }
}
