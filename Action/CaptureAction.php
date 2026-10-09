<?php

/*
 * This file is part of SOLPARTS
 *
 * (c) SOLPARTS LLC (EDRPOU 46143031) <mail@sol.parts>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SolParts\PayumNovaPay\Action;

use Payum\Core\Request\Capture;

/**
 * Одностадійна оплата (`use_hold=false`) — списання одразу.
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class CaptureAction extends PurchaseAction
{
    public function supports($request)
    {
        return
            $request instanceof Capture
            && $request->getModel() instanceof \ArrayAccess;
    }
}
