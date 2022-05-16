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

namespace ImportHttp\Services;

use Espo\Core\Services\Base;

class ImportHttp extends Base
{
    public function getAllColumns(string $httpUrl, string $adapterName, string $importFeedId): array
    {
        $result = $this->getInjection('serviceFactory')->create('ImportTypeHttp')->httpRequest($httpUrl, $adapterName, 0, 20);

        $allColumns = isset($result[0]) ? array_keys($result[0]) : [];

        if (!empty($importFeedId)) {
            $importFeed = $this->getEntityManager()->getEntity('ImportFeed', $importFeedId);
            if (!empty($importFeed)) {
                if ($allColumns !== $importFeed->getFeedField('allColumns')) {
                    $importFeed->setFeedField('allColumns', $allColumns);
                    $this->getEntityManager()->saveEntity($importFeed);
                    $this->getInjection('serviceFactory')->create('ImportFeed')->removeItemsByAllColumns($importFeed, $allColumns);
                }
            }
        }

        return $allColumns;
    }

    protected function init()
    {
        parent::init();

        $this->addDependency('serviceFactory');
    }
}
