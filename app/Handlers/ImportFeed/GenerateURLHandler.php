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

namespace ImportHttp\Handlers\ImportFeed;

use Atro\Core\Http\Response\JsonResponse;
use Atro\Core\Routing\Route;
use Atro\Handlers\AbstractHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Route(
    path: '/ImportFeed/{id}/generateURL',
    methods: [
        'POST',
    ],
    summary: 'Generate connection URL',
    description: 'Builds and returns the resolved endpoint URL for the connection attached to the specified import feed.',
    tag: 'ImportFeed',
    parameters: [
        [
            'name'        => 'id',
            'in'          => 'path',
            'required'    => true,
            'description' => 'ID of the ImportFeed record.',
            'schema'      => [
                'type' => 'string',
            ],
        ],
    ],
    responses: [
        200 => [
            'description' => 'Resolved connection URL',
            'content'     => [
                'application/json' => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'url' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        403 => [
            'description' => 'Access denied',
        ],
        404 => [
            'description' => 'Import feed not found',
        ],
    ],
    entities: [
        'ImportFeed',
    ],
)]
class GenerateURLHandler extends AbstractHandler
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getAttribute('id');

        $url = $this->getRecordService('ImportHttp')->generateURL($id);

        return new JsonResponse(['url' => $url]);
    }
}
