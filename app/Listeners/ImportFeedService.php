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

namespace ImportHttp\Listeners;

use Espo\Core\EventManager\Event;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\FilePathBuilder;
use Espo\Listeners\AbstractListener;
use Import\Entities\ImportFeed;

class ImportFeedService extends AbstractListener
{
    public function beforeRunImport(Event $event): void
    {
        $importFeed = $this->getEntityManager()->getEntity('ImportFeed', $event->getArgument('importFeedId'));
        if (empty($importFeed) || $importFeed->get('type') !== 'http') {
            return;
        }

        $event->setArgument('attachmentId', $this->createAttachment($importFeed));
    }

    public function createAttachment(ImportFeed $importFeed): string
    {
        $httpUrl = trim((string)$importFeed->getFeedField('httpUrl'));
        $httpMethod = $importFeed->getFeedField('httpMethod');
        $httpBody = $importFeed->getFeedField('httpBody');
        $httpHeaders = $importFeed->get('importHttpHeaders')->toArray();
        $fileFormat = $importFeed->getFeedField('format');

        if (empty($httpUrl)) {
            throw new BadRequest('Validation failed. URL is required.');
        }

        if (empty($fileFormat)) {
            throw new BadRequest('Validation failed. Format is required.');
        }

        $attachmentName = (new \DateTime())->format('Y-m-d_H:i:s');

        $headers = [];
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

        $repository = $this->getEntityManager()->getRepository('Attachment');

        $attachment = $repository->get();
        $attachment->set('name', $attachmentName);
        $attachment->set('storageFilePath', $repository->getDestPath(FilePathBuilder::UPLOAD));
        $attachment->set('storageThumbPath', $repository->getDestPath(FilePathBuilder::UPLOAD));
        $attachment->set('relatedType', 'Asset');
        $attachment->set('field', 'file');

        $fullPath = $this->getConfig()->get('filesPath', 'upload/files/') . $attachment->get('storageFilePath');
        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0777, true);
        }

        $fileName = $fullPath . '/' . $attachmentName;

        file_put_contents($fileName, $output);

        $attachment->set('md5', md5_file($fileName));
        $attachment->set('size', filesize($fileName));
        $attachment->set('type', mime_content_type($fileName));

        $repository->save($attachment, ['skipAll' => true]);

        return $attachment->get('id');
    }
}
