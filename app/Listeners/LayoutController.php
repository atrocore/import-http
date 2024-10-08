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

        $result[1]['rows'][] = [['name' => 'httpOffset'], ['name' => 'httpLimit']];
        $result[1]['rows'][] = [['name' => 'httpTotal'], false];
        $result[1]['rows'][] = [['name' => 'httpMethod'], ['name' => 'httpConnectionId']];
        $result[1]['rows'][] = [['name' => 'httpUrl', 'fullWidth' => true]];
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
