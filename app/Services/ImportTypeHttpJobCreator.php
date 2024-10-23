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

use Atro\ConnectionType\ConnectionHttp;
use Atro\ConnectionType\HttpConnectionInterface;
use Atro\Core\EventManager\Event;
use Atro\Core\EventManager\Manager;
use Atro\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Metadata;
use Atro\Core\Utils\Util;
use Espo\ORM\Entity;
use Atro\Services\QueueManagerBase;
use Import\Entities\ImportFeed;
use Import\Services\ImportTypeSimple;
use Import\Services\ImportFeed as ImportFeedService;

class ImportTypeHttpJobCreator extends QueueManagerBase
{
    protected $importFeedService = null;

    public function run(array $data = []): bool
    {
        $GLOBALS['skipAssignmentNotifications'] = true;
        $GLOBALS['skipHooks'] = true;

        $data = json_decode(json_encode($data), true);

        $combine = true;
        if ($combine) {
            $file = $this->createCombinedFile($data);
            echo '<pre>';
            print_r($file->get('id'));
            die();
        }

        foreach ($data as $item) {
            $payload = !empty($item['payload']) ? json_decode(json_encode($item['payload'])) : new \stdClass();
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

        $headers = $this
            ->getEventManager()
            ->dispatch('ImportTypeHttpJobCreatorService', 'prepareAttachmentHeaders', new Event(['importFeed' => $importFeed, 'headers' => []]))
            ->getArgument('headers');

        $ext = 'csv';
        switch ($fileFormat) {
            case 'JSON':
                $headers[] = 'Content-Type: application/json';
                $ext = 'json';
                break;
            case 'XML':
                $headers[] = 'Content-Type: application/xml';
                $ext = 'xml';
                break;
            case 'Excel':
                $ext = 'xlsx';
                break;
        }

        if (!empty($httpHeaders)) {
            foreach ($httpHeaders as $v) {
                $headers[] = "{$v['name']}: {$v['value']}";
            }
        }

        $attachmentName = $this->createFileName($importFeed->get('name') . '_' . Util::generateId(), $ext);

        $response = $this
            ->createConnection($importFeed->getFeedField('httpConnectionId') ?? null)
            ->request($httpUrl, $httpMethod, $headers, $httpBody);

        $folder = $this->getImportFeedService()->createImportFileFolder($importFeed);
        return $this->createAttachment($attachmentName, $response->getOutput(), $folder->get('id'));
    }

    protected function createCombinedFile(array $data): Entity
    {
        $importFeedId = $data[0]['importFeedId'] ?? null;
        if (empty($importFeedId)) {
            throw new BadRequest('Creating combined file failed. $importFeedId is empty.');
        }

        /** @var ImportFeed $importFeed */
        $importFeed = $this->getImportFeedService()->getEntity($importFeedId);
        if (empty($importFeed)) {
            throw new BadRequest("Creating combined file failed. ImportFeed $importFeedId not found.");
        }

        $files = [];
        foreach ($data as $v) {
            /** @var ImportTypeSimple $service */
            $service = $this->getService('ImportTypeSimple');
            try {
                $attachment = $this->createAttachmentViaHttpRequest($importFeed, $v['httpUrl'], (string)$v['httpBody']);
                $jobData = $service->prepareJobData($importFeed, $attachment->get('id'));
                $convertedFile = $service->createConvertedFile($importFeed, $jobData);
            } catch (\Throwable $e) {
                continue;
            }
            $files[] = $convertedFile->getFilePath();
        }

        if (empty($files)) {
            throw new BadRequest('Creating combined file failed.');
        }

        $tmpDir = ImportFeedService::TMP_DIR . DIRECTORY_SEPARATOR . Util::generateId();
        @mkdir($tmpDir, 0777, true);

        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $this->createFileName($importFeed->get('name'), 'csv');

        $this->combineCSVs($files, $tmpFile, $jobData['delimiter'], $jobData['enclosure']);

        $input = new \stdClass();
        $input->name = $this->createFileName($importFeed->get('name'), 'csv');
        $input->hidden = true;
        $input->folderId = $this->getImportFeedService()->createImportFileFolder($importFeed)->get('id');

        $file = $this->getService('File')->moveLocalFileToFileEntity($input, $tmpFile);

        if (is_array($file)) {
            $file = $this->getEntityManager()->getEntity('File', $file['id']);
        }

        return $file;
    }

    protected function createAttachment(string $name, string $contents, string $folderId): Entity
    {
        $input = new \stdClass();
        $input->name = $name;
        $input->hidden = true;
        $input->folderId = $folderId;

        $fileData = $this->getService('File')->createFileViaContents($input, $contents);

        return is_array($fileData) ? $this->getEntityManager()->getRepository('File')->get($fileData['id']) : $fileData;
    }

    protected function combineCSVs(array $files, string $outputFile, string $delimiter = ',', string $enclosure = '"'): void
    {
        // Collect all unique headers across all files
        $allHeaders = [];
        foreach ($files as $file) {
            if (($handle = fopen($file, 'r')) !== false) {
                $headers = fgetcsv($handle, 0, $delimiter, $enclosure);
                if ($headers) {
                    $allHeaders = array_unique(array_merge($allHeaders, $headers));
                }
                fclose($handle);
            }
        }

        // Open the output file and write the unified headers
        $outputHandle = fopen($outputFile, 'w');
        fputcsv($outputHandle, $allHeaders, $delimiter, $enclosure); // Write headers

        // Stream each file row-by-row to the output file
        foreach ($files as $file) {
            if (($handle = fopen($file, 'r')) !== false) {
                $headers = fgetcsv($handle, 0, $delimiter, $enclosure);

                while (($row = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false) {
                    $alignedRow = array_fill_keys($allHeaders, null);
                    $rowData = array_combine($headers, $row);

                    foreach ($rowData as $key => $value) {
                        $alignedRow[$key] = $value;
                    }

                    fputcsv($outputHandle, $alignedRow, $delimiter, $enclosure);
                }

                fclose($handle);
            }
        }

        fclose($outputHandle);
    }

    protected function getService(string $serviceName)
    {
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

    protected function createFileName(string $str, string $ext): string
    {
        return preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', strtolower($str))) . '.' . $ext;
    }

    public function createConnection(?string $httpConnectionId): HttpConnectionInterface
    {
        if (empty($httpConnectionId)) {
            return $this->getContainer()->get(ConnectionHttp::class);
        }

        return $this->getContainer()->get('connectionFactory')->createById($httpConnectionId);
    }
}
