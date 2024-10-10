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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;

class ImportHttp extends \Espo\Core\Controllers\Base
{
    public function actionGenerateURL($params, $data, $request): array
    {
        if (!$request->isPost()) {
            throw new BadRequest();
        }

        if (!$this->getAcl()->check('ImportFeed', 'edit')) {
            throw new Forbidden();
        }

        return $this->getService('ImportTypeHttp')->generateURL($data);
    }

    public function actionGenerateSourceFields($params, $data, $request): array
    {
        if (!$request->isPost()) {
            throw new BadRequest();
        }

        if (!$this->getAcl()->check('ImportFeed', 'edit')) {
            throw new Forbidden();
        }

        return $this->getService('ImportTypeHttp')->generateSourceFields($data);
    }
}
