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
use Atro\Core\Exceptions\Error;
use Espo\Core\Utils\Metadata;
use Atro\Core\Utils\Util;
use Espo\ORM\Entity;
use Atro\Services\QueueManagerBase;
use Import\Entities\ImportFeed;
use Import\Services\ImportFeed as ImportFeedService;

class ImportTypeHttpJobCreator extends QueueManagerBase
{
    protected $importFeedService = null;

    public function run(array $data = []): bool
    {
        $GLOBALS['skipAssignmentNotifications'] = true;
        $GLOBALS['skipHooks'] = true;

        $data = json_decode(json_encode($data), true);

        $importFeedId = $data[0]['importFeedId'] ?? null;
        if (empty($importFeedId)) {
            throw new BadRequest('ImportFeedId is required.');
        }

        /** @var ImportFeed $importFeed */
        $importFeed = $this->getImportFeedService()->getEntity($importFeedId);
        if (empty($importFeed)) {
            throw new BadRequest("ImportFeed $importFeedId not found.");
        }

        if (!empty($importFeed->getFeedField('mergeResponses'))) {
            return $this->createCombinedJob($importFeed, $data);
        }

        foreach ($data as $item) {
            $payload = !empty($item['payload']) ? json_decode(json_encode($item['payload'])) : new \stdClass();
            try {
                $this->createJobs($importFeed, $item['httpUrl'], (string)$item['httpBody'], $payload);
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

    public function createJobs(ImportFeed $importFeed, string $httpUrl, string $httpBody, \stdClass $payload): void
    {
        if (empty($httpUrl)) {
            throw new BadRequest('Validation failed. URL is required.');
        }

        $attachment = $this->createAttachmentViaHttpRequest($importFeed, $httpUrl, $httpBody);

        if ($this->getImportFeedService()->hasParentJob($importFeed)) {
            $parentJob = $this->getImportFeedService()->createImportJob($importFeed,
                $importFeed->getFeedField('entity'), $attachment->get('id'), $payload);
            $payload->parentJobId = $parentJob->get('id');
        }

        $this->createJob($importFeed, $attachment, $payload);
    }

    public function createJob(ImportFeed $importFeed, Entity $attachment, \stdClass $payload): void
    {
        $jobData = $this->getContainer()->get('serviceFactory')->create('ImportTypeHttp')->prepareJobData($importFeed,
            $attachment->get('id'), true);
        $jobData['payload'] = $payload;
        $jobData['data']['importJobId'] = $this
            ->getImportFeedService()
            ->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'),
                $payload)->get('id');

        $this->getImportFeedService()->push($this->getImportFeedService()->getName($importFeed), 'ImportTypeHttp',
            $jobData);

        $this
            ->getEventManager()
            ->dispatch('ImportFeedService', 'afterImportJobsCreations',
                new Event(['importFeedId' => $importFeed->get('id')]));
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
            ->dispatch('ImportTypeHttpJobCreatorService', 'prepareAttachmentHeaders',
                new Event(['importFeed' => $importFeed, 'headers' => []]))
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

        $attachmentName = $this->createFileName($importFeed->get('name'), $ext);

        $response = $this
            ->createConnection($importFeed->getFeedField('httpConnectionId') ?? null)
            ->request($httpUrl, $httpMethod, $headers, $httpBody);

        $folder = $this->getImportFeedService()->createImportFileFolder($importFeed);
        return $this->createAttachment($attachmentName, $response->getOutput(), $folder->get('id'));
    }

    protected function createCombinedJob(ImportFeed $importFeed, array $data): bool
    {
        $format = $importFeed->getFeedField('format');
        if (!in_array($format, ['JSON', 'XML'])) {
            throw new Error('Combined job possible only with JSON or XML format.');
        }

        $tmpDir = ImportFeedService::TMP_DIR . DIRECTORY_SEPARATOR . Util::generateId();
        @mkdir($tmpDir, 0777, true);

        $delimiter = ";";
        $enclosure = '"';

        $files = [];
        foreach ($data as $v) {
            $attachment = $this->createAttachmentViaHttpRequest($importFeed, $v['httpUrl'], (string)$v['httpBody']);

            $fileParser = $this->getFileParser($format);
            $fileParser->setData([
                'excludedNodes'   => $importFeed->getFeedField('excludedNodes') ?? [],
                'keptStringNodes' => $importFeed->getFeedField('keptStringNodes') ?? [],
                'emptyValue'      => $importFeed->getFeedField('emptyValue'),
                'nullValue'       => $importFeed->getFeedField('nullValue'),
            ]);

            $parsedData = $fileParser->getFileData($attachment);

            $fileParser = $this->getFileParser('CSV');
            $fileParser->setData([
                'delimiter'  => $delimiter,
                'enclosure'  => $enclosure,
            ]);

            $contents = $fileParser->createFileContent($parsedData);
            if (empty($contents) || $contents === "\n\n") {
                continue;
            }

            $fileName = $tmpDir . DIRECTORY_SEPARATOR . Util::generateId() . '.csv';
            file_put_contents($fileName, $contents);
            $this->getEntityManager()->removeEntity($attachment);
            $files[] = $fileName;
        }

        if (empty($files)) {
            throw new BadRequest('Creating combined file failed.');
        }

        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $this->createFileName($importFeed->get('name'), 'csv');

        $this->combineCSVs($files, $tmpFile, $delimiter, $enclosure);

        $input = new \stdClass();
        $input->name = $this->createFileName($importFeed->get('name'), 'csv');
        $input->hidden = true;
        $input->folderId = $this->getImportFeedService()->createImportFileFolder($importFeed)->get('id');

        $file = $this->getService('File')->moveLocalFileToFileEntity($input, $tmpFile);
        $fileId = is_array($file) ? $file['id'] : $file->get('id');

        // delete all tmp files
        Util::removeDir($tmpDir);

        $payload = new \stdClass();
        $payload->delimiter = $delimiter;
        $payload->enclosure = $enclosure;
        $payload->format = 'CSV';

        $this->getImportFeedService()->pushJobs($importFeed, $fileId, $payload);

        return true;
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

    protected function combineCSVs(
        array $files,
        string $outputFile,
        string $delimiter = ',',
        string $enclosure = '"'
    ): void {
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

    protected function getFileParser(string $format): \Import\FileParsers\FileParserInterface
    {
        return $this->getContainer()->get(ImportFeed::getFileParserClass($format));
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
