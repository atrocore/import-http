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

namespace ImportHttp\Controllers;

use Atro\Controllers\AbstractController;
use Atro\Core\Exceptions\BadRequest;
use Atro\Core\Exceptions\Forbidden;
use ImportHttp\Jobs\ImportTypeHttpJobCreator;

class ImportHttp extends AbstractController
{
    public function actionGenerateURL($params, $data, $request): array
    {
        if (!$request->isPost()) {
            throw new BadRequest();
        }

        if (!$this->getAcl()->check('ImportFeed', 'edit')) {
            throw new Forbidden();
        }

        if (empty($data->importFeedId)) {
            throw new BadRequest('Import Feed ID is required');
        }

        $importFeed = $this->getService('ImportFeed')->getEntity($data->importFeedId);
        if (empty($importFeed)) {
            throw new BadRequest("Import Feed with ID '{$data->importFeedId}' does not exists.");
        }

        $connectionType = $this->getImportTypeHttpJobCreator()->createConnection($importFeed->get('httpConnectionId'));

        return ['url' => $connectionType->generateUrlForEntity($importFeed->get('entity'))];
    }

    public function actionGenerateSourceFields($params, $data, $request): array
    {
        if (!$request->isPost()) {
            throw new BadRequest();
        }

        if (!$this->getAcl()->check('ImportFeed', 'edit')) {
            throw new Forbidden();
        }

        if (empty($data->importFeedId)) {
            throw new BadRequest('Import Feed ID is required');
        }

        $importFeed = $this->getService('ImportFeed')->getEntity($data->importFeedId);
        if (empty($importFeed)) {
            throw new BadRequest("Import Feed with ID '{$data->importFeedId}' does not exists.");
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

    protected function getImportTypeHttpJobCreator(): ImportTypeHttpJobCreator
    {
        return $this->getContainer()->get(ImportTypeHttpJobCreator::class);
    }
}
