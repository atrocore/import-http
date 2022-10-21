<?php
/*
 * This file is part of premium software, which is NOT free.
 * Copyright (c) AtroCore UG (haftungsbeschränkt).
 *
 * This Software is the property of AtroCore UG (haftungsbeschränkt) and is
 * protected by copyright law - it is NOT Freeware and can be used only in one
 * project under a proprietary license, which is delivered along with this program.
 * If not, see <https://atropim.com/eula> or <https://atrodam.com/eula>.
 *
 * This Software is distributed as is, with LIMITED WARRANTY AND LIABILITY.
 * Any unauthorised use of this Software without a valid license is
 * a violation of the License Agreement.
 *
 * According to the terms of the license you shall not resell, sublicense,
 * rent, lease, distribute or otherwise transfer rights or usage of this
 * Software or its derivatives. You may modify the code of this Software
 * for your own needs, if source code is provided.
 */

declare(strict_types=1);

namespace ImportHttp\Listeners;

use Espo\Core\Utils\Json;
use Espo\Core\EventManager\Event;
use Espo\Listeners\AbstractListener;

class LayoutController extends AbstractListener
{
    public function afterActionRead(Event $event): void
    {
        $scope = $event->getArgument('params')['scope'];

        $name = $event->getArgument('params')['name'];

        $method = 'modify' . $scope . ucfirst($name);

        if (method_exists($this, $method)) {
            $this->{$method}($event);
        }
    }

    protected function modifyImportFeedDetail(Event $event): void
    {
        $result = Json::decode($event->getArgument('result'), true);

        $result[1]['rows'][] = [['name' => 'httpMethod'], ['name' => 'httpUrl']];
        $result[1]['rows'][] = [['name' => 'httpBody', 'fullWidth' => true]];

        $event->setArgument('result', Json::encode($result));
    }

    protected function modifyImportFeedRelationships(Event $event): void
    {
        $result = Json::decode($event->getArgument('result'), true);

        $result = array_merge([['name' => 'importHttpHeaders']], $result);

        $event->setArgument('result', Json::encode($result));
    }
}
