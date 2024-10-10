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

namespace ImportHttp\Services;

use Atro\ConnectionType\HttpConnectionInterface;
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

    public function generateURL(\stdClass $input): array
    {
        if (empty($input->importFeedId)) {
            throw new BadRequest('Import Feed ID is required');
        }

        $importFeed = $this->getService('ImportFeed')->getEntity($input->importFeedId);
        if (empty($importFeed)) {
            throw new BadRequest("Import Feed with ID '{$input->importFeedId}' does not exists.");
        }

        /** @var HttpConnectionInterface $connectionType */
        $connectionType = $this->getService('ImportTypeHttpJobCreator')->createConnection($importFeed->get('httpConnectionId'));

        return ['url' => $connectionType->generateUrlForEntity($importFeed->get('entity'))];
    }

    public function generateSourceFields(\stdClass $input): array
    {
        if (empty($input->importFeedId)) {
            throw new BadRequest('Import Feed ID is required');
        }

        $importFeed = $this->getService('ImportFeed')->getEntity($input->importFeedId);
        if (empty($importFeed)) {
            throw new BadRequest("Import Feed with ID '{$input->importFeedId}' does not exists.");
        }

        $httpUrl = $this->getContainer()->get('twig')
            ->renderTemplate((string)$importFeed->get('httpUrl'), ['total' => 5, 'limit' => 5, 'offset' => 0]);

        $attachment = $this
            ->getService('ImportTypeHttpJobCreator')
            ->createAttachmentViaHttpRequest($importFeed, $httpUrl, '');

        $payload = new \stdClass();
        $payload->attachmentId = $attachment->get('id');
        $payload->format = $importFeed->get('format');
        $payload->delimiter = $importFeed->get('fileFieldDelimiter');
        $payload->enclosure = ($importFeed->get('fileTextQualifier') == 'singleQuote') ? "'" : '"';
        $payload->isFileHeaderRow = $importFeed->get('isFileHeaderRow');
        $payload->sheet = $importFeed->get('sheet');
        $payload->excludedNodes = $importFeed->get('excludedNodes');
        $payload->keptStringNodes = $importFeed->get('keptStringNodes');

        $sourceFields = $this->getService('ImportFeed')->getFileColumns($payload);

        return [
            'fileId'       => $attachment->get('id'),
            'fileName'     => $attachment->get('name'),
            'sourceFields' => $sourceFields
        ];
    }

    public function runImport(ImportFeed $importFeed, string $attachmentId, \stdClass $payload = null): bool
    {
        /** @var ImportTypeHttpJobCreator $jobCreator */
        $jobCreator = $this->getService('ImportTypeHttpJobCreator');
        /** @var \Import\Services\ImportFeed $service */
        $service = $this->getService('ImportFeed');

        if (!empty($attachmentId)) {
            $attachment = $this->getEntityManager()->getEntity('File', $attachmentId);

            if (!empty($attachment)) {
                $payload = new \stdClass();
                if ($service->hasParentJob($importFeed)) {
                    $parentJob = $service->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'), $payload);
                    $payload->parentJobId = $parentJob->get('id');
                    $jobCreator->createConvertedFileForParentJob($parentJob, $attachmentId);
                }

                $jobCreator->createJob($importFeed, $attachment, $payload);
            }
            return true;
        }

        /** @var QueueManager $queueManager */
        $queueManager = $this->getContainer()->get('queueManager');
        /** @var Twig $twig */
        $twig = $this->getContainer()->get('twig');

        $offset = (int)$importFeed->getFeedField('httpOffset');
        $limit = (int)$importFeed->getFeedField('httpLimit');
        $total = (int)$importFeed->getFeedField('httpTotal');
        $httpUrl = trim((string)$importFeed->getFeedField('httpUrl'));
        $httpBody = (string)$importFeed->getFeedField('httpBody');
        $data = ['payload' => $payload];

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

            if (!empty($payload) && !empty($payload->executeNow)) {
                $this->getService('ImportTypeHttpJobCreator')->run($jobData);
            } else {
                $queueManager->push("Create Import Jobs for {$importFeed->get("name")}", 'ImportTypeHttpJobCreator', $jobData, 'High');
            }

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
                        'httpBody'     => $twig->renderTemplate($httpBody, $data)
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
                        'httpBody'     => $twig->renderTemplate($httpBody, $data)
                    ];

                    $i++;
                    $page++;
                }

                if (!empty($payload) && !empty($payload->executeNow)) {
                    $this->getService('ImportTypeHttpJobCreator')->run($jobData);
                } else {
                    $queueManager->push("Create Import Jobs for {$importFeed->get("name")}", 'ImportTypeHttpJobCreator', $jobData, 'High');
                }

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

    private function containsVariable(array $allExtractedExp, string $string): bool
    {
        foreach ($allExtractedExp as $exp) {
            if (strpos($exp[1], $string) !== false) {
                return true;
            }
        }
        return false;
    }
}
