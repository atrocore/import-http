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

use Atro\Core\Exceptions\Forbidden;
use Atro\Core\Exceptions\NotFound;
use Atro\Services\AbstractService;
use Import\Services\ImportFeed as ImportFeedService;
use ImportHttp\Jobs\ImportTypeHttpJobCreator;

class ImportHttp extends AbstractService
{
    public function generateSourceFields(string $importFeedId): array
    {
        if (!$this->getInjection('acl')->check('ImportFeed', 'edit')) {
            throw new Forbidden();
        }

        $importFeed = $this->getImportFeedService()->getEntity($importFeedId);

        if (empty($importFeed)) {
            throw new NotFound();
        }

        $httpUrl = $this->getInjection('twig')
            ->renderTemplate((string)$importFeed->get('httpUrl'), ['total' => 5, 'limit' => 5, 'offset' => 0]);

        $httpBody = $this->getInjection('twig')
            ->renderTemplate((string)$importFeed->get('httpBody'), ['total' => 5, 'limit' => 5, 'offset' => 0]);

        $attachment = $this->getInjection(ImportTypeHttpJobCreator::class)
            ->createAttachmentViaHttpRequest($importFeed, $httpUrl, $httpBody);

        $payload = new \stdClass();
        $payload->fileId = $attachment->get('id');
        $payload->format = $importFeed->get('format');
        $payload->delimiter = $importFeed->get('fileFieldDelimiter');
        $payload->enclosure = $importFeed->get('fileTextQualifier');
        $payload->headerRowNumber = $importFeed->get('headerRowNumber');
        $payload->sheet = $importFeed->get('sheet');
        $payload->excludedNodes = $importFeed->get('excludedNodes');
        $payload->keptStringNodes = $importFeed->get('keptStringNodes');

        $sourceFields = $this->getImportFeedService()->getFileColumns($payload);

        return [
            'fileId'       => $attachment->get('id'),
            'fileName'     => $attachment->get('name'),
            'sourceFields' => $sourceFields,
        ];
    }

    public function generateURL(string $importFeedId): string
    {
        if (!$this->getInjection('acl')->check('ImportFeed', 'edit')) {
            throw new Forbidden();
        }

        $importFeed = $this->getImportFeedService()->getEntity($importFeedId);
        if (empty($importFeed)) {
            throw new NotFound();
        }

        $connectionType = $this->getInjection(ImportTypeHttpJobCreator::class)
            ->createConnection($importFeed->get('connectionId'));

        return $connectionType->generateUrlForEntity($importFeed->get('entity'));
    }

    protected function init()
    {
        parent::init();
        $this->addDependency('twig');
        $this->addDependency('acl');
        $this->addDependency('serviceFactory');
        $this->addDependency('container');
        $this->addDependency(ImportTypeHttpJobCreator::class);
    }

    protected function getImportFeedService(): ImportFeedService
    {
        return $this->getInjection('serviceFactory')->create('ImportFeed');
    }
}
