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

namespace SolParts\PayumNovaPay\Action\Api;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Sync;
use SolParts\PayumContracts\Request\Api\DoCapture;
use SolParts\PayumNovaPay\Api;

/**
 * Списання холду (двостадійна оплата): `POST /complete-hold`.
 *
 * Викликається окремо від checkout — коли магазин фіналізує захолдований платіж.
 * `amount` опційний: `null` → списати повну суму холду, задане значення —
 * часткове списання.
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class DoCaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        /** @var DoCapture $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $details->validateNotEmpty(['session_id']);

        $amount = isset($details['amount']) && '' !== $details['amount']
            ? (float) $details['amount']
            : null;

        $this->api->completeHold((string) $details['session_id'], $amount);

        $this->gateway->execute(new Sync($details));
    }

    public function supports($request)
    {
        return
            $request instanceof DoCapture
            && $request->getModel() instanceof \ArrayAccess;
    }
}
