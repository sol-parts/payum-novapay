<?php

/*
 * This file is part of Sol.parts
 *
 * (c) SOLPARTS LLC (EDRPOU 46143031) <mail@sol.parts>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SolParts\PayumNovaPay\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;
use SolParts\PayumNovaPay\Api;

/**
 * Мапить статус сесії NovaPay у Payum-статуси. Як транслювати їх у стан
 * платежу (зокрема refunded-над-authorized → скасування) — вирішує хост за
 * попереднім станом платежу.
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class StatusAction implements ActionInterface
{
    /**
     * @param GetStatusInterface $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());
        $status = $details['status'] ?? null;

        if (null === $status) {
            $request->markNew();

            return;
        }

        switch ($status) {
            case Api::STATUS_PAID:
                $request->markCaptured();

                return;

            case Api::STATUS_HOLDED:
            case Api::STATUS_HOLD_CONFIRMED:
            case Api::STATUS_PROCESSING_HOLD_COMPLETION:
                $request->markAuthorized();

                return;

            case Api::STATUS_VOIDED:
                // NovaPay віддає той самий voided і для reversal холду, і для
                // повернення оплати: captured → refund, hold → скасування —
                // розрізняє хост за попереднім станом платежу.
                $request->markRefunded();

                return;

            case Api::STATUS_FAILED:
                $request->markFailed();

                return;

            case Api::STATUS_EXPIRED:
                // Окремий термінальний стан (клієнт не оплатив, сесія протухла) —
                // хост-застосунок розрізняє його з `failed`.
                $request->markExpired();

                return;

            case Api::STATUS_CREATED:
            case Api::STATUS_PROCESSING:
            case Api::STATUS_PROCESSING_VOID:
                $request->markPending();

                return;

            default:
                $request->markUnknown();
        }
    }

    public function supports($request)
    {
        return
            $request instanceof GetStatusInterface
            && $request->getModel() instanceof \ArrayAccess;
    }
}
