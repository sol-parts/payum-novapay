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

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Authorize;

/**
 * Двостадійна оплата: `use_hold=true` — кошти блокуються на картці клієнта до
 * подальшого списання через complete-hold ({@see Api\DoCaptureAction}).
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class AuthorizeAction extends PurchaseAction
{
    public function execute($request): void
    {
        /** @var Authorize $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());
        $details['use_hold'] = true;

        parent::execute($request);
    }

    public function supports($request)
    {
        return
            $request instanceof Authorize
            && $request->getModel() instanceof \ArrayAccess;
    }
}
