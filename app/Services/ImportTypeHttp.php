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

use Import\Entities\ImportFeed;
use Import\Services\ImportTypeSimple;
use ImportHttp\ImportAdapter\ImportAdapterInterface;

class ImportTypeHttp extends ImportTypeSimple
{
    private int $iterations = 0;

    public function prepareJobData(ImportFeed $feed, string $attachmentId): array
    {
        return [
            "name"    => $feed->get('name'),
            "httpUrl" => $feed->getFeedField('httpUrl'),
            "adapter" => $feed->getFeedField('adapter'),
            "action"  => $feed->get('fileDataAction'),
            "data"    => $feed->getConfiguratorData()
        ];
    }

    public function getAdapter(string $adapterName): ?ImportAdapterInterface
    {
        if (!empty($adapterName)) {
            $className = $this->getMetadata()->get(['app', 'importAdapters', $adapterName]);
            if (!empty($className) && is_a($className, ImportAdapterInterface::class, true)) {
                return new $className();
            }
        }

        return null;
    }

    public function httpRequest(string $httpUrl, string $adapterName): array
    {
        $httpUrl = trim($httpUrl);

        if (empty($httpUrl)) {
            return [];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $httpUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $adapter = $this->getAdapter($adapterName);

        if (!empty($adapter)) {
            $adapter->prepareRequest($ch);
        }

        $result = @json_decode(curl_exec($ch), true);

        if (!empty($adapter) && !empty($result)) {
            $result = $adapter->prepareResponse($result);
        }

        curl_close($ch);

        return empty($result) ? [] : $result;
    }

    protected function getInputData(array $data): array
    {
        if ($this->iterations > 0) {
            return [];
        }

        $this->iterations++;

        return $this->httpRequest($data['httpUrl'], $data['adapter']);
    }
}
