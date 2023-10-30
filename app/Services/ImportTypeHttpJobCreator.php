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

use Atro\ConnectionType\ConnectionOauth1;
use Atro\ConnectionType\ConnectionOauth2;
use Espo\Core\EventManager\Event;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\FilePathBuilder;
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
            // prepare payload
            $payload = empty($item['payload']) ? [] : json_decode(json_encode($item['payload']), true);

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

    public function createJobs(string $importFeedId, string $httpUrl, string $httpBody, array $payload = []): void
    {
        if (empty($httpUrl)) {
            throw new BadRequest('Validation failed. URL is required.');
        }

        $importFeed = $this->getImportFeedService()->getEntity($importFeedId);

        $httpMethod = $importFeed->getFeedField('httpMethod');
        $httpHeaders = $importFeed->get('importHttpHeaders')->toArray();
        $fileFormat = $importFeed->getFeedField('format');

        if (empty($fileFormat)) {
            throw new BadRequest('Validation failed. Format is required.');
        }

        $attachmentName = (new \DateTime())->format('Y-m-d_H:i:s');

        $headers = $this
            ->getContainer()
            ->get('eventManager')
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

        if (!empty($importFeed->getFeedField('httpConnectionId'))) {
            $connectionEntity = $this->getEntityManager()->getEntity('Connection', $importFeed->getFeedField('httpConnectionId'));

            if (!empty($connectionEntity)) {
                $type = $connectionEntity->get('type');
                if($type === 'oauth1'){
                    $connectionData = $this->getContainer()->get(ConnectionOauth1::class)->connect($connectionEntity, $httpUrl);
                }else{
                    $connectionTypeClassName= "Atro\ConnectionType\Connection".ucfirst($type);
                    $connectionData = $this->getContainer()->get($connectionTypeClassName)->connect($connectionEntity);
                }

                $headers[] = "Authorization: {$connectionData['token_type']} {$connectionData['access_token']}";
            }
        }

        if (!empty($httpHeaders)) {
            foreach ($httpHeaders as $v) {
                $headers[] = "{$v['name']}: {$v['value']}";
            }
        }
        $output = $this->sendRequest($httpUrl, $httpMethod, $headers, $httpBody);
        $attachment = $this->createAttachment($attachmentName, $output);

        $this->createJob($importFeed, $attachment, $payload);
    }

    protected function sendRequest(string $httpUrl, string $httpMethod, array $headers, string $httpBody = null): string
    {
        $ch = curl_init($httpUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, empty($httpMethod) ? 'GET' : $httpMethod);
        if (!empty($httpBody)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $httpBody);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $output = curl_exec($ch);
        if ($output === false) {
            throw new BadRequest('Curl error: ' . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!in_array($httpCode, [200, 201, 204])) {
            throw new BadRequest("Response Code: $httpCode Body: $output");
        }

        return $output;
    }

    public function createJob(ImportFeed $importFeed, Entity $attachment, array $payload = []): void
    {
        // prepare payload
        $payload = empty($payload) ? null : json_decode(json_encode($payload));

        $jobData = $this->getContainer()->get('serviceFactory')->create('ImportTypeHttp')->prepareJobData($importFeed, $attachment->get('id'), true);
        $jobData['data']['importJobId'] = $this
            ->getImportFeedService()
            ->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'), $payload)->get('id');

        $this->getImportFeedService()->push($this->getImportFeedService()->getName($importFeed), 'ImportTypeHttp', $jobData);

        $this
            ->getContainer()
            ->get('eventManager')
            ->dispatch('ImportFeedService', 'afterImportJobsCreations', new Event(['importFeedId' => $importFeed->get('id')]));
    }

    protected function createAttachment(string $name, string $contents): Entity
    {
        $repository = $this->getEntityManager()->getRepository('Attachment');

        $attachment = $repository->get();
        $attachment->set('name', $name);
        $attachment->set('storageFilePath', $repository->getDestPath(FilePathBuilder::UPLOAD));
        $attachment->set('storageThumbPath', $repository->getDestPath(FilePathBuilder::UPLOAD));
        $attachment->set('relatedType', 'Asset');
        $attachment->set('field', 'file');

        $fullPath = $this->getConfig()->get('filesPath', 'upload/files/') . $attachment->get('storageFilePath');
        while (!file_exists($fullPath)) {
            mkdir($fullPath, 0777, true);
            usleep(100);
        }

        $fileName = $fullPath . '/' . $name;

        file_put_contents($fileName, $contents);

        $attachment->set('md5', md5_file($fileName));
        $attachment->set('size', filesize($fileName));
        $attachment->set('type', mime_content_type($fileName));

        $repository->save($attachment, ['skipAll' => true]);

        return $attachment;
    }

    protected function getImportFeedService(): \Import\Services\ImportFeed
    {
        if (empty($this->importFeedService)) {
            $this->importFeedService = $this->getContainer()->get('serviceFactory')->create('ImportFeed');
        }

        return $this->importFeedService;
    }
}
