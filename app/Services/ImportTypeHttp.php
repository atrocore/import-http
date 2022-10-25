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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\FilePathBuilder;
use Import\Entities\ImportFeed;

class ImportTypeHttp extends \Import\Services\ImportTypeSimple
{
    public function runImport(ImportFeed $importFeed, string $attachmentId): string
    {
        // https://webservicefiles-bergner.com/api/articles?pagination={"page":1,"pageLength":200}
        // https://webservicefiles-bergner.com/api/articles?pagination={"page":2,"pageLength":200}

        // https://webservicefiles-bergner.com/api/articles?pagination={"page":4,"pageLength":200}

        // https://webservicefiles-bergner.com/api/articles?pagination={"offset":0,"limit":200}
        // https://webservicefiles-bergner.com/api/articles?pagination={"offset":200,"limit":200}

        // https://webservicefiles-bergner.com/api/articles?pagination={"offset":3,"limit":200}
        // https://webservicefiles-bergner.com/api/articles?pagination={"offset":203,"limit":200}
        // https://webservicefiles-bergner.com/api/articles?pagination={"offset":403,"limit":200}

        // offset = 3           // offset = 999  page = 5
        // limit = 200          // limit = 200
        // total = 600          // total = 600


        /** @var ImportTypeHttpJobCreator $jobCreator */
        $jobCreator = $this->getService('ImportTypeHttpJobCreator');

        $queueManager = $this->getContainer()->get('queueManager');

        $offset = (int)$importFeed->getFeedField('httpOffset');
        $limit = (int)$importFeed->getFeedField('httpLimit');
        $total = (int)$importFeed->getFeedField('httpTotal');
        $httpUrl = trim((string)$importFeed->getFeedField('httpUrl'));

        if (strpos($httpUrl, '{{limit}}') !== false) {
            if (empty($limit)) {
                throw new BadRequest($this->translate('urlCannotBeFormed', 'exceptions', 'ImportFeed'));
            }
            $httpUrl = str_replace('{{limit}}', (string)$limit, $httpUrl);
        }

        if (strpos($httpUrl, '{{total}}') !== false) {
            if (empty($total)) {
                throw new BadRequest($this->translate('urlCannotBeFormed', 'exceptions', 'ImportFeed'));
            }
            $httpUrl = str_replace('{{total}}', (string)$total, $httpUrl);
        }

        if (strpos($httpUrl, '{{offset}}') !== false) {
            if (empty($limit) || empty($total)) {
                throw new BadRequest($this->translate('urlCannotBeFormed', 'exceptions', 'ImportFeed'));
            }

            while ($offset < $total) {
                $jobData = [
                    'importFeedId' => $importFeed->get('id'),
                    'httpUrl'      => str_replace('{{offset}}', (string)$offset, $httpUrl)
                ];
                $queueManager->push("Create Import Jobs for {$importFeed->get("name")}", 'ImportTypeHttpJobCreator', $jobData);

                $offset = $offset + $limit;
            }

            return '-';
        }

        if (strpos($httpUrl, '{{page}}') !== false) {
            if (empty($limit) || empty($total)) {
                throw new BadRequest($this->translate('urlCannotBeFormed', 'exceptions', 'ImportFeed'));
            }

            $pages = ceil(($total - $offset) / $limit);

            if ($pages < 1) {
                throw new BadRequest($this->translate('urlCannotBeFormed', 'exceptions', 'ImportFeed'));
            }

            $page = $offset > 0 ? ceil($offset / $limit) : 1;

            if ($pages == 1) {
                $jobCreator->run(['importFeedId' => $importFeed->get('id'), 'httpUrl' => str_replace('{{page}}', (string)$page, $httpUrl)]);
                return $jobCreator->importJobId;
            } else {
                $i = 1;
                while ($i <= $pages) {
                    $jobData = [
                        'importFeedId' => $importFeed->get('id'),
                        'httpUrl'      => str_replace('{{page}}', (string)$page, $httpUrl)
                    ];
                    $queueManager->push("Create Import Jobs for {$importFeed->get("name")}", 'ImportTypeHttpJobCreator', $jobData);
                    $i++;
                    $page++;
                }
                return '-';
            }
        }

        $jobCreator->run(['importFeedId' => $importFeed->get('id'), 'httpUrl' => $httpUrl]);

        return $jobCreator->importJobId;
    }
}
