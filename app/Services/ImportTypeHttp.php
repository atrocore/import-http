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
use Espo\Core\QueueManager;
use Espo\ORM\Entity;
use Import\Entities\ImportFeed;

class ImportTypeHttp extends \Import\Services\ImportTypeSimple
{
    public function getNotificationMessage(Entity $queueItem): string
    {
        // disable notifications, because for big count of jobs it looks like spam
        return '';
    }

    public function runImport(ImportFeed $importFeed, string $attachmentId, \stdClass $payload = null): bool
    {
        /** @var ImportTypeHttpJobCreator $jobCreator */
        $jobCreator = $this->getService('ImportTypeHttpJobCreator');

        if(!empty($attachmentId)){
            $attachment = $this
                ->getEntityManager()
                ->getEntity('Attachment', $attachmentId);
            if(!empty($attachment)){
                $jobCreator->createJob($importFeed, $attachment, []);
            }
            return true;
        }

        /** @var QueueManager $queueManager */
        $queueManager = $this->getContainer()->get('queueManager');

        $offset = (int)$importFeed->getFeedField('httpOffset');
        $limit = (int)$importFeed->getFeedField('httpLimit');
        $total = (int)$importFeed->getFeedField('httpTotal');
        $httpUrl = trim((string)$importFeed->getFeedField('httpUrl'));
        $httpBody = (string)$importFeed->getFeedField('httpBody');

        if (strpos($httpUrl, '{{limit}}') !== false || strpos($httpBody, '{{limit}}') !== false) {
            if (empty($limit)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }
            $httpUrl = str_replace('{{limit}}', (string)$limit, $httpUrl);
            $httpBody = str_replace('{{limit}}', (string)$limit, $httpBody);
        }

        if (strpos($httpUrl, '{{total}}') !== false || strpos($httpBody, '{{total}}') !== false) {
            if (empty($total)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }
            $httpUrl = str_replace('{{total}}', (string)$total, $httpUrl);
            $httpBody = str_replace('{{total}}', (string)$total, $httpBody);
        }

        if (strpos($httpUrl, '{{offset}}') !== false || strpos($httpBody, '{{offset}}') !== false) {
            if (empty($limit) || empty($total)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }

            $jobData = [];
            while ($offset < $total) {
                $jobData[] = [
                    'importFeedId' => $importFeed->get('id'),
                    'payload'      => $payload,
                    'httpUrl'      => str_replace('{{offset}}', (string)$offset, $httpUrl),
                    'httpBody'     => str_replace('{{offset}}', (string)$offset, $httpBody)
                ];
                $offset = $offset + $limit;
            }

            $queueManager->push("Create Import Jobs for {$importFeed->get("name")}", 'ImportTypeHttpJobCreator', $jobData, 'High');

            return true;
        }

        if (strpos($httpUrl, '{{page}}') !== false || strpos($httpBody, '{{page}}') !== false) {
            if (empty($limit) || empty($total)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }

            $pages = ceil(($total - $offset) / $limit);

            if ($pages < 1) {
                $pages = 1;
            }

            $page = $offset > 0 ? ceil($offset / $limit) : 1;

            if ($pages == 1) {
                $jobCreator->run([
                    [
                        'importFeedId' => $importFeed->get('id'),
                        'payload'      => $payload,
                        'httpUrl'      => str_replace('{{page}}', (string)$page, $httpUrl),
                        'httpBody'     => str_replace('{{page}}', (string)$page, $httpBody)
                    ]
                ]);

                return true;
            } else {
                $jobData = [];
                $i = 1;
                while ($i <= $pages) {
                    $jobData[] = [
                        'importFeedId' => $importFeed->get('id'),
                        'payload'      => $payload,
                        'httpUrl'      => str_replace('{{page}}', (string)$page, $httpUrl),
                        'httpBody'     => str_replace('{{page}}', (string)$page, $httpBody)
                    ];

                    $i++;
                    $page++;
                }
                $queueManager->push("Create Import Jobs for {$importFeed->get("name")}", 'ImportTypeHttpJobCreator', $jobData, 'High');

                return true;
            }
        }

        $jobCreator->run([
            [
                'importFeedId' => $importFeed->get('id'),
                'payload'      => $payload,
                'httpUrl'      => $httpUrl,
                'httpBody'     => $httpBody
            ]
        ]);

        return true;
    }
}
