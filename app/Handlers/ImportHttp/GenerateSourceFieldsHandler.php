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

namespace ImportHttp\Handlers\ImportHttp;

use Atro\Core\Exceptions\BadRequest;
use Atro\Core\Exceptions\Forbidden;
use Atro\Core\Http\Response\JsonResponse;
use Atro\Core\Routing\Route;
use Atro\Handlers\AbstractHandler;
use ImportHttp\Jobs\ImportTypeHttpJobCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route(
    path: '/ImportHttp/action/generateSourceFields',
    methods: ['POST'],
    summary: 'Generate source fields for HTTP import feed',
    description: 'Fetches sample data from the HTTP source and returns the detected source fields.',
    tag: 'ImportHttp',
    requestBody: ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['importFeedId'], 'properties' => ['importFeedId' => ['type' => 'string']]]]]],
    responses: [
        200 => ['description' => 'Source fields', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['fileId' => ['type' => 'string'], 'fileName' => ['type' => 'string'], 'sourceFields' => ['type' => 'array', 'items' => ['type' => 'string']]]]]]],
        400 => ['description' => 'importFeedId is required or feed not found'],
        403 => ['description' => 'Forbidden'],
    ],
)]
class GenerateSourceFieldsHandler extends AbstractHandler
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->getAcl()->check('ImportFeed', 'edit')) {
            throw new Forbidden();
        }

        $data = $this->getRequestBody($request);

        if (empty($data->importFeedId)) {
            throw new BadRequest('Import Feed ID is required');
        }

        $importFeed = $this->getRecordService('ImportFeed')->getEntity((string) $data->importFeedId);
        if (empty($importFeed)) {
            throw new BadRequest("Import Feed with ID '{$data->importFeedId}' does not exists.");
        }

        $httpUrl = $this->container->get('twig')
            ->renderTemplate((string) $importFeed->get('httpUrl'), ['total' => 5, 'limit' => 5, 'offset' => 0]);

        $attachment = $this->container->get(ImportTypeHttpJobCreator::class)->createAttachmentViaHttpRequest($importFeed, $httpUrl, '');

        $payload                  = new \stdClass();
        $payload->attachmentId    = $attachment->get('id');
        $payload->format          = $importFeed->get('format');
        $payload->delimiter       = $importFeed->get('fileFieldDelimiter');
        $payload->enclosure       = ($importFeed->get('fileTextQualifier') == 'singleQuote') ? "'" : '"';
        $payload->isFileHeaderRow = $importFeed->get('isFileHeaderRow');
        $payload->sheet           = $importFeed->get('sheet');
        $payload->excludedNodes   = $importFeed->get('excludedNodes');
        $payload->keptStringNodes = $importFeed->get('keptStringNodes');

        $sourceFields = $this->getRecordService('ImportFeed')->getFileColumns($payload);

        return new JsonResponse([
            'fileId'       => $attachment->get('id'),
            'fileName'     => $attachment->get('name'),
            'sourceFields' => $sourceFields,
        ]);
    }
}
