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
use Atro\Core\Http\Response\JsonResponse;
use Atro\Core\Routing\Route;
use Atro\Handlers\AbstractHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route(
    path: '/ImportHttp/generateSourceFields',
    methods: [
        'GET',
    ],
    summary: 'Generate source fields',
    description: 'Fetches a sample response from the configured HTTP source and returns the detected source fields for use in import feed column mapping.',
    tag: 'ImportHttp',
    parameters: [
        [
            'name'     => 'importFeedId',
            'in'       => 'query',
            'required' => true,
            'schema'   => [
                'type' => 'string',
            ],
        ],
    ],
    responses: [
        200 => [
            'description' => 'Detected source fields and the temporary file created from the HTTP response',
            'content'     => [
                'application/json' => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'fileId'       => [
                                'type' => 'string',
                            ],
                            'fileName'     => [
                                'type' => 'string',
                            ],
                            'sourceFields' => [
                                'type'  => 'array',
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        400 => [
            'description' => 'importFeedId is required',
        ],
        403 => [
            'description' => 'Access denied',
        ],
        404 => [
            'description' => 'Import feed not found',
        ],
    ],
)]
class GenerateSourceFieldsHandler extends AbstractHandler
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $importFeedId = $request->getQueryParams()['importFeedId'] ?? '';

        if (empty($importFeedId)) {
            throw new BadRequest("'importFeedId' is required.");
        }

        return new JsonResponse($this->getRecordService('ImportHttp')->generateSourceFields($importFeedId));
    }
}
