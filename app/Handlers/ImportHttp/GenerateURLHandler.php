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
    path: '/ImportHttp/action/generateURL',
    methods: ['POST'],
    summary: 'Generate HTTP URL for import feed',
    description: 'Generates an endpoint URL for the specified import feed connection.',
    tag: 'ImportHttp',
    requestBody: ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['importFeedId'], 'properties' => ['importFeedId' => ['type' => 'string']]]]]],
    responses: [
        200 => ['description' => 'Generated URL', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']]]]]],
        400 => ['description' => 'importFeedId is required or feed not found'],
        403 => ['description' => 'Forbidden'],
    ],
)]
class GenerateURLHandler extends AbstractHandler
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

        $connectionType = $this->container->get(ImportTypeHttpJobCreator::class)->createConnection($importFeed->get('connectionId'));

        return new JsonResponse(['url' => $connectionType->generateUrlForEntity($importFeed->get('entity'))]);
    }
}
