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

use Espo\Core\EventManager\Event;

class ImportFeedService extends \Espo\Listeners\AbstractListener
{
    public function prepareEntityForOutput(Event $event): void
    {
        $entity = $event->getArgument('entity');
        if (!empty($entity->getFeedField('httpConnectionId'))) {
            $connection = $this->getEntityManager()->getEntity('Connection', $entity->getFeedField('httpConnectionId'));
            if (!empty($connection)) {
                $entity->set('httpConnectionName', $connection->get('name'));
            }
        }
    }
}
