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

use Atro\Core\Twig\Twig;
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
        /** @var Twig $twig */
        $twig =  $this->getContainer()->get('twig');

        $offset = (int)$importFeed->getFeedField('httpOffset');
        $limit = (int)$importFeed->getFeedField('httpLimit');
        $total = (int)$importFeed->getFeedField('httpTotal');
        $httpUrl = trim((string)$importFeed->getFeedField('httpUrl'));
        $httpBody = (string)$importFeed->getFeedField('httpBody');
        $data = [];

         preg_match_all('/\{\{(.*?)}}/m', $httpUrl, $httpUrlExtractedExp, PREG_SET_ORDER, 0);
         preg_match_all('/\{\{(.*?)}}/m', $httpBody, $httpBodyExtractedExp, PREG_SET_ORDER, 0);
         $allExtractedExp = array_merge($httpUrlExtractedExp, $httpBodyExtractedExp);

        if ($this->containsVariable($allExtractedExp, 'limit')) {
            if (empty($limit)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }
            $data['limit'] = $limit;

        }


        if ($this->containsVariable($allExtractedExp, 'total')) {
            if (empty($total)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }
            $data['total'] = $total;
        }

        if ($this->containsVariable($allExtractedExp, 'offset')) {
            if (empty($limit) || empty($total)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }

            $jobData = [];
            while ($offset < $total) {
                $data['offset'] = $offset;
                $jobData[] = [
                    'importFeedId' => $importFeed->get('id'),
                    'payload'      => $payload,
                    'httpUrl'      => $twig->renderTemplate($httpUrl, $data),
                    'httpBody'     => $twig->renderTemplate($httpBody, $data)
                ];
                $offset = $offset + $limit;
            }

            $queueManager->push("Create Import Jobs for {$importFeed->get("name")}", 'ImportTypeHttpJobCreator', $jobData, 'High');

            return true;
        }

        if ($this->containsVariable($allExtractedExp, 'page')) {
            if (empty($limit) || empty($total)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }

            $pages = ceil(($total - $offset) / $limit);

            if ($pages < 1) {
                $pages = 1;
            }

            $page = $offset > 0 ? ceil($offset / $limit) : 1;

            if ($pages == 1) {
                $data['page'] = $page;
                $jobCreator->run([
                    [
                        'importFeedId' => $importFeed->get('id'),
                        'payload'      => $payload,
                        'httpUrl'      => $twig->renderTemplate($httpUrl, $data),
                        'httpBody'     =>$twig->renderTemplate($httpBody, $data)
                    ]
                ]);

                return true;
            } else {
                $jobData = [];
                $i = 1;
                while ($i <= $pages) {
                    $data['page'] = $page;
                    $jobData[] = [
                        'importFeedId' => $importFeed->get('id'),
                        'payload'      => $payload,
                        'httpUrl'      => $twig->renderTemplate($httpUrl, $data),
                        'httpBody'     =>$twig->renderTemplate($httpBody, $data)
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

    private function containsVariable(array $allExtractedExp, string $string)  : bool
    {
        foreach ($allExtractedExp as $exp){
            if(strpos($exp[1],$string)  !== false){
                return true;
            }
        }
        return false;
    }
}
