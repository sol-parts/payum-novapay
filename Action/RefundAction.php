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

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Refund;
use Payum\Core\Request\Sync;
use SolParts\PayumNovaPay\Api;

/**
 * Повернення оплаченого платежу через `POST /void` (повна сума сесії).
 *
 * NovaPay має вікно: оплачене повертається лише до 23:59 того ж дня. Часткове
 * повернення робиться через `operations[]{id, refund_amount}` (id операції — з
 * `payments[]` у get-status/постбеку); поки не потрібне — повертаємо повну суму.
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class RefundAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /**
     * @param Refund $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (empty($details['session_id'])) {
            return;
        }

        $this->api->void((string) $details['session_id']);

        $this->gateway->execute(new Sync($details));
    }

    public function supports($request)
    {
        return
            $request instanceof Refund
            && $request->getModel() instanceof \ArrayAccess;
    }
}
