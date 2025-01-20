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

use Atro\Listeners\AbstractLayoutListener;
use Espo\Core\Utils\Json;
use Espo\Core\EventManager\Event;
use Espo\Listeners\AbstractListener;

class Layout extends AbstractLayoutListener
{
    protected function modifyImportFeedDetail(Event $event): void
    {
        $result = $event->getArgument('result');

        $result[1]['rows'][] = [['name' => 'httpOffset'], ['name' => 'httpLimit']];
        $result[1]['rows'][] = [['name' => 'httpTotal'], false];
        $result[1]['rows'][] = [['name' => 'httpMethod'], ['name' => 'httpConnectionId']];
        $result[1]['rows'][] = [['name' => 'httpUrl', 'fullWidth' => true]];
        $result[1]['rows'][] = [['name' => 'httpBody', 'fullWidth' => true]];
        $result[1]['rows'][] = [['name' => 'mergeResponses'], false];

        $event->setArgument('result',  $result);
    }

    protected function modifyImportFeedRelationships(Event $event): void
    {
        $result = $event->getArgument('result');

        $result = array_merge([['name' => 'importHttpHeaders', 'canClose' => false]], $result);

        $event->setArgument('result',  $result);
    }
}
