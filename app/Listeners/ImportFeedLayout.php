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
use Atro\Core\EventManager\Event;

class ImportFeedLayout extends AbstractLayoutListener
{
    public function detail(Event $event): void
    {
        $result = $event->getArgument('result');

        $result[1]['rows'][] = [['name' => 'httpOffset'], ['name' => 'httpLimit']];
        $result[1]['rows'][] = [['name' => 'httpMethod'], ['name' => 'httpTotal']];
        $result[1]['rows'][] = [['name' => 'httpUrl', 'fullWidth' => true]];
        $result[1]['rows'][] = [['name' => 'httpBody', 'fullWidth' => true]];
        $result[1]['rows'][] = [['name' => 'mergeResponses'], false];

        $event->setArgument('result', $result);
    }

    public function relationships(Event $event): void
    {
        $result = $event->getArgument('result');

        $result = array_merge([['name' => 'importHttpHeaders', 'canClose' => false]], $result);

        $event->setArgument('result', $result);
    }
}
