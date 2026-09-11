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

namespace ImportHttp\Jobs;

use Atro\ConnectionType\ConnectionHttp;
use Atro\ConnectionType\HttpConnectionInterface;
use Atro\Core\EventManager\Event;
use Atro\Core\Exceptions\BadRequest;
use Atro\Core\Exceptions\Error;
use Atro\Entities\File;
use Atro\Entities\Job;
use Atro\Jobs\AbstractJob;
use Atro\Jobs\JobInterface;
use Atro\Core\Utils\Util;
use Espo\ORM\Entity;
use Import\Entities\ImportFeed;
use Import\ProcessingTypes\AbstractProcessingType;
use Import\Services\ImportFeed as ImportFeedService;

class ImportTypeHttpJobCreator extends AbstractJob implements JobInterface
{
    protected $importFeedService = null;
    protected ?string $importJobCreatorId = null;

    public function run(Job $job): void
    {
        $this->runNow($job->getPayload(), $job);
    }

    public function runNow(array $data, ?Job $job = null): void
    {
        $GLOBALS['skipAssignmentNotifications'] = true;
        $GLOBALS['skipHooks'] = true;

        if (!empty($job)) {
            $this->importJobCreatorId = $job->get('id');
        }

        if (empty($data['importFeedId'])) {
            throw new BadRequest('ImportFeedId is required.');
        }

        /** @var ImportFeed $importFeed */
        $importFeed = $this->getImportFeedService()->getEntity($data['importFeedId']);
        if (empty($importFeed)) {
            throw new BadRequest("ImportFeed {$data['importFeedId']} not found.");
        }

        $jobsData = $this->prepareJobsData($importFeed, $data);

        if (!empty($importFeed->getFeedField('mergeResponses'))) {
            if ($importFeed->get('processingType') === 'configurator') {
                $this->createCombinedJob($importFeed, $jobsData);
                return;
            } else {
                $entityName = $importFeed->getFeedField('entity');
                $entityLabel = $this->getLanguage()->translate($entityName, 'scopeNames');

                $parentJob = $this->getEntityManager()->getEntity('ImportJob');
                $parentJob->set('name', "{$entityLabel}: {$importFeed->get('name')}");
                $parentJob->set('importFeedId', $importFeed->get('id'));
                $parentJob->set('entityName', $entityName);
                $parentJob->set('sortOrder', time() - (new \DateTime('2023-01-01'))->getTimestamp());
                $this->getEntityManager()->saveEntity($parentJob);
            }
        }

        foreach ($jobsData as $item) {
            $payload = !empty($item['payload']) ? @json_decode(json_encode($item['payload'])) : new \stdClass();
            if (!empty($parentJob)) {
                $payload->parentJobId = $parentJob->get('id');
            }
            try {
                if (!empty($item['attachmentId'])) {
                    $this->createJobsForAttachment($importFeed, $item['attachmentId'], $payload);
                } else {
                    $this->createJobs($importFeed, $item['httpUrl'], (string)$item['httpBody'], $payload);
                }
            } catch (\Throwable $e) {
                if (!empty($parentJob)) {
                    $parentJob->set('state', 'Failed');
                    $parentJob->set('message', $e->getMessage());
                    $this->getEntityManager()->saveEntity($parentJob);
                }

                throw $e;
            }
        }
    }

    protected function prepareJobsData(ImportFeed $importFeed, array $data): array
    {
        $className = $this->getMetadata()->get(['app', 'processingTypes', $importFeed->get('processingType'), 'className']);

        if (!empty($className) && is_a($className, AbstractProcessingType::class, true) &&
            method_exists($className, 'prepareJobsData')) {
            return $this->getContainer()->get($className)->prepareJobsData($importFeed, $data, $this);
        }

        $res = [];

        $payload = $data['payload'] ?? null;
        $data['offset'] = $offset = (int)$importFeed->getFeedField('httpOffset');
        $data['limit'] = $limit = (int)$importFeed->getFeedField('httpLimit');
        $data['total'] = $total = $importFeed->getFeedField('httpTotal');
        $httpUrl = trim((string)$importFeed->getFeedField('httpUrl'));
        $httpBody = (string)$importFeed->getFeedField('httpBody');

        preg_match_all('/\{\{(.*?)}}/m', $httpUrl, $httpUrlExtractedExp, PREG_SET_ORDER, 0);
        preg_match_all('/\{\{(.*?)}}/m', $httpBody, $httpBodyExtractedExp, PREG_SET_ORDER, 0);
        $allExtractedExp = array_merge($httpUrlExtractedExp, $httpBodyExtractedExp);

        if ($this->containsVariable($allExtractedExp, 'offset')) {
            if ($total === null) {
                $iteration = 0;
                while (true) {
                    if ($iteration > 2000) {
                        // stop if too many iterations. maybe something wrong
                        break;
                    }

                    $data['offset'] = $offset;
                    try {
                        $attachment = $this->createAttachmentViaHttpRequest(
                            $importFeed,
                            $this->twig()->renderTemplate($httpUrl, $data),
                            $this->twig()->renderTemplate($httpBody, $data)
                        );
                    } catch (\Throwable $e) {
                        if (empty($res)) {
                            throw $e;
                        }
                        break;
                    }

                    $res[] = [
                        'importFeedId' => $importFeed->get('id'),
                        'payload'      => array_merge((array)$payload, ['httpRequest' => ['offset' => $offset, 'limit' => $limit]]),
                        'attachmentId' => $attachment->get('id')
                    ];

                    if (!$this->importFeedFileHasData($importFeed, $attachment)) {
                        // stop because no results
                        break;
                    } else if ($importFeed->get('processingType') === 'configurator') {
                        $parsedData = $this->parseImportFeedFile($importFeed, $attachment);
                        $identifiers = $this->getEntityManager()->getRepository('ImportConfiguratorItem')
                            ->where([
                                'importFeedId'     => $importFeed->get('id'),
                                'entityIdentifier' => true
                            ])
                            ->find();
                        foreach ($identifiers as $identifier) {
                            if (!empty($identifier->get('column')[0])) {
                                foreach ($parsedData as $row) {
                                    if (!array_key_exists($identifier->get('column')[0], $row)) {
                                        // stop because not identifier
                                        break 3;
                                    }
                                }
                            }
                        }
                    }

                    $offset = $offset + $limit;
                    $iteration++;
                }
            } else {
                while ($offset < $total) {
                    $data['offset'] = $offset;
                    $res[] = [
                        'importFeedId' => $importFeed->get('id'),
                        'payload'      => array_merge((array)$payload, ['httpRequest' => ['offset' => $offset, 'limit' => $limit]]),
                        'httpUrl'      => $this->twig()->renderTemplate($httpUrl, $data),
                        'httpBody'     => $this->twig()->renderTemplate($httpBody, $data)
                    ];
                    $offset = $offset + $limit;
                }
            }
        }

        if ($this->containsVariable($allExtractedExp, 'page')) {
            if (empty($limit) || empty($total)) {
                throw new BadRequest($this->translate('urlOrBodyCannotBeFormed', 'exceptions', 'ImportFeed'));
            }

            // @todo if total is null we need to import all. same as for offset and limit

            $pages = ceil(($total - $offset) / $limit);

            if ($pages < 1) {
                $pages = 1;
            }

            $page = $offset > 0 ? ceil($offset / $limit) : 1;

            if ($pages == 1) {
                $data['page'] = $page;
                $res[] = [
                    'importFeedId' => $importFeed->get('id'),
                    'payload'      => array_merge((array)$payload, ['httpRequest' => ['page' => $page, 'limit' => $limit]]),
                    'httpUrl'      => $this->twig()->renderTemplate($httpUrl, $data),
                    'httpBody'     => $this->twig()->renderTemplate($httpBody, $data)
                ];
            } else {
                $i = 1;
                while ($i <= $pages) {
                    $data['page'] = $page;
                    $res[] = [
                        'importFeedId' => $importFeed->get('id'),
                        'payload'      => array_merge((array)$payload, ['httpRequest' => ['page' => $page, 'limit' => $limit]]),
                        'httpUrl'      => $this->twig()->renderTemplate($httpUrl, $data),
                        'httpBody'     => $this->twig()->renderTemplate($httpBody, $data)
                    ];

                    $i++;
                    $page++;
                }
            }
        }

        if (empty($res)) {
            $res[] = [
                'importFeedId' => $importFeed->get('id'),
                'payload'      => $payload,
                'httpUrl'      => $httpUrl,
                'httpBody'     => $httpBody
            ];
        }

        return $res;
    }

    public function createJobsForAttachment(ImportFeed $importFeed, string $attachmentId, \stdClass $payload): void
    {
        $attachment = $this->getEntityManager()->getRepository('File')->get($attachmentId);

        if ($this->getImportFeedService()->hasParentJob($importFeed) && empty($payload->parentJobId)) {
            $parentJob = $this->getImportFeedService()->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'), $payload);
            $payload->parentJobId = $parentJob->get('id');
        }

        $this->createJob($importFeed, $attachment, $payload);
    }

    public function createJobs(ImportFeed $importFeed, string $httpUrl, string $httpBody, \stdClass $payload): void
    {
        if (empty($httpUrl)) {
            throw new BadRequest('Validation failed. URL is required.');
        }

        $attachment = $this->createAttachmentViaHttpRequest($importFeed, $httpUrl, $httpBody);

        if ($this->getImportFeedService()->hasParentJob($importFeed) && empty($payload->parentJobId)) {
            $parentJob = $this->getImportFeedService()->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'), $payload);
            $payload->parentJobId = $parentJob->get('id');
        }

        $this->createJob($importFeed, $attachment, $payload);
    }

    public function createJob(ImportFeed $importFeed, Entity $attachment, \stdClass $payload): void
    {
        $jobData = $this->getContainer()->get(ImportTypeHttp::class)
            ->prepareJobData($importFeed, $attachment->get('id'));
        $jobData['payload'] = $payload;
        $jobData['data']['importJobId'] = $this
            ->getImportFeedService()
            ->createImportJob($importFeed, $importFeed->getFeedField('entity'), $attachment->get('id'),
                $payload)->get('id');

        if (!empty($this->importJobCreatorId)) {
            $jobData['importJobCreatorId'] = $this->importJobCreatorId;
        }

        $this->getImportFeedService()->push($this->getImportFeedService()->getName($importFeed), 'ImportTypeHttp',
            $jobData);

        $this
            ->getEventManager()
            ->dispatch('ImportFeedService', 'afterImportJobsCreations',
                new Event(['importFeedId' => $importFeed->get('id')]));
    }

    public function createAttachmentViaHttpRequest(Entity $importFeed, string $httpUrl, string $httpBody): File
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
            ->createConnection($importFeed->get('connectionId') ?? null)
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
            if (!empty($v['attachmentId'])) {
                $attachment = $this->getEntityManager()->getRepository('File')->get($v['attachmentId']);
            } else {
                $attachment = $this->createAttachmentViaHttpRequest($importFeed, $v['httpUrl'], (string)$v['httpBody']);
            }

            $parsedData = $this->parseImportFeedFile($importFeed, $attachment);
            if (empty($parsedData)) {
                continue;
            }

            $fileParser = $this->getFileParser('CSV');
            $fileParser->setData([
                'delimiter' => $delimiter,
                'enclosure' => $enclosure,
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

        $combinedFileName = $this->createFileName($importFeed->get('name'), 'csv');
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $combinedFileName;

        $this->combineCSVs($files, $tmpFile, $delimiter, $enclosure);

        $input = new \stdClass();
        $input->name = $combinedFileName;
        $input->hidden = true;
        $input->folderId = $this->getImportFeedService()->createImportFileFolder($importFeed)->get('id');

        $fileId = $this->getService('File')->moveLocalFileToFileEntity($input, $tmpFile);

        // delete all tmp files
        Util::removeDir($tmpDir);

        $payload = isset($data[0]['payload']) ? json_decode(json_encode($data[0]['payload'])) : new \stdClass();
        $payload->delimiter = $delimiter;
        $payload->enclosure = $enclosure;
        $payload->format = 'CSV';
        if (empty($importFeed->get('maxPerJob')) || $importFeed->get('maxPerJob') < 1) {
            $payload->maxPerJob = 50000;
        }

        $this->getImportFeedService()->pushJobs($importFeed, $fileId, $payload);

        return true;
    }

    public function createAttachment(string $name, string $contents, string $folderId): File
    {
        $input = new \stdClass();
        $input->name = $name;
        $input->hidden = true;
        $input->folderId = $folderId;

        $fileId = $this->getService('File')->createFileViaContents($input, $contents);

        return $this->getEntityManager()->getRepository('File')->get($fileId);
    }

    protected function combineCSVs(
        array  $files,
        string $outputFile,
        string $delimiter = ',',
        string $enclosure = '"'
    ): void
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

    protected function getFileParser(string $format): \Import\FileParsers\FileParserInterface
    {
        return $this->getContainer()->get(ImportFeed::getFileParserClass($format));
    }

    protected function createFileName(string $str, string $ext): string
    {
        $base = preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', strtolower($str)));

        [$usec, $sec] = explode(' ', microtime());
        $timestamp = date('YmdHis', (int) $sec) . substr($usec, 2, 3);

        return sprintf('%s_%s.%s', $base, $timestamp, $ext);
    }

    protected function containsVariable(array $allExtractedExp, string $string): bool
    {
        foreach ($allExtractedExp as $exp) {
            if (strpos($exp[1], $string) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function importFeedFileHasData(ImportFeed $importFeed, File $file): bool
    {
        $fileParser = $this->getFileParser($importFeed->getFeedField('format'));
        $fileParser->setData([
            'rootNode'        => $importFeed->getFeedField('rootNode') ?? null,
            'excludedNodes'   => $importFeed->getFeedField('excludedNodes') ?? [],
            'keptStringNodes' => $importFeed->getFeedField('keptStringNodes') ?? [],
            'emptyValue'      => $importFeed->getFeedField('emptyValue'),
            'nullValue'       => $importFeed->getFeedField('nullValue'),
        ]);

        return $fileParser->hasFileData($file);
    }

    protected function parseImportFeedFile(ImportFeed $importFeed, File $file): array
    {
        $fileParser = $this->getFileParser($importFeed->getFeedField('format'));
        $fileParser->setData([
            'rootNode'        => $importFeed->getFeedField('rootNode') ?? null,
            'excludedNodes'   => $importFeed->getFeedField('excludedNodes') ?? [],
            'keptStringNodes' => $importFeed->getFeedField('keptStringNodes') ?? [],
            'emptyValue'      => $importFeed->getFeedField('emptyValue'),
            'nullValue'       => $importFeed->getFeedField('nullValue'),
        ]);

        return $fileParser->getFileData($file);
    }

    public function createConnection(?string $connectionId): HttpConnectionInterface
    {
        if (empty($connectionId)) {
            return $this->getContainer()->get(ConnectionHttp::class);
        }

        return $this->getContainer()->get('connectionFactory')->createById($connectionId);
    }
}
