<?php
/*
 * This file is part of premium software, which is NOT free.
 * Copyright (c) AtroCore GmbH.
 *
 * This Software is the property of AtroCore GmbH and is
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

use Atro\ConnectionType\ConnectionHttp;
use Atro\ConnectionType\HttpConnectionInterface;
use Espo\Core\EventManager\Event;
use Espo\Core\EventManager\Manager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\Services\QueueManagerBase;
use Import\Entities\ImportFeed;

class ImportTypeHttpJobCreator extends QueueManagerBase
{
    protected $importFeedService = null;

    public function run(array $data = []): bool
    {
        $GLOBALS['skipAssignmentNotifications'] = true;
        $GLOBALS['skipHooks'] = true;

        foreach ($data as $item) {
            $item = json_decode(json_encode($item), true);

            $payload = $item['payload'] ?? new \stdClass();

            try {
                $this->createJobs($item['importFeedId'], $item['httpUrl'], (string)$item['httpBody'], $payload);
            } catch (\Throwable $e) {
                $GLOBALS['log']->error('ImportTypeHttpJobCreator FAILED: ' . $e->getMessage());
            }
        }

        return true;
    }

    public function getNotificationMessage(Entity $queueItem): string
    {
        return '';
    }

    public function createJobs(string $importFeedId, string $httpUrl, string $httpBody, \stdClass $payload): void
    {
        if (empty($httpUrl)) {
            throw new BadRequest('Validation failed. URL is required.');
        }

        /** @var ImportFeed $importFeed */
        $importFeed = $this->getImportFeedService()->getEntity($importFeedId);

        $attachment = $this->createAttachmentViaHttpRequest($importFeed, $httpUrl, $httpBody);

        if ($this->getImportFeedService()->hasParentJob($importFeed)) {
            $parentJob = $this->getImportFeedService()->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'), $payload);
            $payload->parentJobId = $parentJob->get('id');
        }

        $this->createJob($importFeed, $attachment, $payload);
    }

    public function createJob(ImportFeed $importFeed, Entity $attachment, \stdClass $payload): void
    {
        $jobData = $this->getContainer()->get('serviceFactory')->create('ImportTypeHttp')->prepareJobData($importFeed, $attachment->get('id'), true);
        $jobData['payload'] = $payload;
        $jobData['data']['importJobId'] = $this
            ->getImportFeedService()
            ->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'), $payload)->get('id');

        $this->getImportFeedService()->push($this->getImportFeedService()->getName($importFeed), 'ImportTypeHttp', $jobData);

        $this
            ->getEventManager()
            ->dispatch('ImportFeedService', 'afterImportJobsCreations', new Event(['importFeedId' => $importFeed->get('id')]));
    }

    public function createAttachmentViaHttpRequest(Entity $importFeed, string $httpUrl, string $httpBody): Entity
    {
        $httpMethod = $importFeed->getFeedField('httpMethod');
        $httpHeaders = $importFeed->get('importHttpHeaders')->toArray();
        $fileFormat = $importFeed->getFeedField('format');

        if (empty($fileFormat)) {
            throw new BadRequest('Validation failed. Format is required.');
        }

        $attachmentName = preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', strtolower($importFeed->get('name'))));
        $attachmentName .= '_' . Util::generateId();

        $headers = $this
            ->getEventManager()
            ->dispatch('ImportTypeHttpJobCreatorService', 'prepareAttachmentHeaders', new Event(['importFeed' => $importFeed, 'headers' => []]))
            ->getArgument('headers');

        switch ($fileFormat) {
            case 'JSON':
                $headers[] = 'Content-Type: application/json';
                $attachmentName .= '.json';
                break;
            case 'XML':
                $headers[] = 'Content-Type: application/xml';
                $attachmentName .= '.xml';
                break;
            case 'CSV':
                $attachmentName .= '.csv';
                break;
            case 'Excel':
                $attachmentName .= '.xlsx';
                break;
        }

        if (!empty($httpHeaders)) {
            foreach ($httpHeaders as $v) {
                $headers[] = "{$v['name']}: {$v['value']}";
            }
        }

        $response = $this
            ->createConnection($importFeed->getFeedField('httpConnectionId') ?? null)
            ->request($httpUrl, $httpMethod, $headers, $httpBody);

        $folder = $this->getImportFeedService()->createImportFileFolder($importFeed);
        return $this->createAttachment($attachmentName, $response->getOutput(), $folder->get('id'));
    }

    protected function createAttachment(string $name, string $contents, string $folderId): Entity
    {
        $input = new \stdClass();
        $input->name = $name;
        $input->hidden = true;
        $input->folderId = $folderId;

        $fileData = $this->getService('File')->createFileViaContents($input, $contents);
        return $this->getEntityManager()->getRepository('File')->get($fileData['id']);
    }

    protected  function getService($serviceName) {
        return $this->getContainer()->get('serviceFactory')->create($serviceName);
    }

    protected function getImportFeedService(): \Import\Services\ImportFeed
    {
        if (empty($this->importFeedService)) {
            $this->importFeedService = $this->getService('ImportFeed');
        }

        return $this->importFeedService;
    }

    protected function getMetadata(): Metadata
    {
        return $this->getContainer()->get('metadata');
    }

    protected function getEventManager(): Manager
    {
        return $this->getContainer()->get('eventManager');
    }

    public function createConnection(?string $httpConnectionId): HttpConnectionInterface
    {
        if (empty($httpConnectionId)) {
            return $this->getContainer()->get(ConnectionHttp::class);
        }

        return $this->getContainer()->get('connectionFactory')->createById($httpConnectionId);
    }
}
