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

use Atro\Core\Exceptions\BadRequest;
use Atro\Jobs\JobInterface;
use Import\Entities\ImportFeed;

class ImportTypeHttp extends \Import\Jobs\ImportTypeSimple implements JobInterface
{
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
            $job = $this->getEntityManager()->getEntity('Job');
            $job->set([
                'name'     => "Create Import Jobs for {$importFeed->get("name")}",
                'type'     => 'ImportTypeHttpJobCreator',
                'priority' => 150,
                'payload'  => $jobData
            ]);
            $this->getEntityManager()->saveEntity($job);
        }

        return true;
    }

    protected function getImportTypeHttpJobCreator(): ImportTypeHttpJobCreator
    {
        return $this->getContainer()->get(ImportTypeHttpJobCreator::class);
    }
}
