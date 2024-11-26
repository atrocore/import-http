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

        $connectionType = $this->getImportTypeHttpJobCreator()->createConnection($importFeed->get('httpConnectionId'));

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

        $attachment = $this->getImportTypeHttpJobCreator()->createAttachmentViaHttpRequest($importFeed, $httpUrl, '');

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
        /** @var \Import\Services\ImportFeed $service */
        $service = $this->getService('ImportFeed');

        if (!empty($attachmentId)) {
            $attachment = $this->getEntityManager()->getEntity('File', $attachmentId);

            if (!empty($attachment)) {
                $payload = new \stdClass();
                if ($service->hasParentJob($importFeed)) {
                    $parentJob = $service->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'), $payload);
                    $payload->parentJobId = $parentJob->get('id');
                }

                $this->getImportTypeHttpJobCreator()->createJob($importFeed, $attachment, $payload);
            }
            return true;
        }

        $jobData = [
            'importFeedId' => $importFeed->get('id'),
            'payload'      => $payload
        ];

        if (!empty($payload) && !empty($payload->executeNow)) {
            $this->getImportTypeHttpJobCreator()->run($jobData);
        } else {
            /** @var QueueManager $queueManager */
            $queueManager = $this->getContainer()->get('queueManager');
            $queueManager->push("Create Import Jobs for {$importFeed->get("name")}", 'ImportTypeHttpJobCreator', $jobData, 'High');
        }

        return true;
    }

    protected function getImportTypeHttpJobCreator(): ImportTypeHttpJobCreator
    {
        return $this->getService('ImportTypeHttpJobCreator');
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
