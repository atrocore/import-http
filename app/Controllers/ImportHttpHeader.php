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

namespace ImportHttp\Controllers;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Templates\Controllers\Base;

class ImportHttpHeader extends Base
{
    public function actionList($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function getActionListKanban($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function actionListLinked($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function actionMassUpdate($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function actionMassDelete($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function actionCreateLink($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function actionRemoveLink($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function actionFollow($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function actionUnfollow($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function actionMerge($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function postActionGetDuplicateAttributes($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function postActionMassFollow($params, $data, $request)
    {
        throw new Forbidden();
    }

    public function postActionMassUnfollow($params, $data, $request)
    {
        throw new Forbidden();
    }
}
