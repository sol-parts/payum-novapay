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
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Cancel;
use Payum\Core\Request\Sync;
use SolParts\PayumNovaPay\Api;

/**
 * Скасування захолдованого платежу (reversal) через `POST /void` — знімає
 * блокування коштів на картці клієнта.
 *
 * NovaPay не різнить void і refund на рівні API (той самий ендпоінт) —
 * cancel-from-hold vs refund-from-captured розрізняє хост за попереднім
 * станом платежу.
 * Для created/processing — NOOP: неоплачена сесія протухне сама (або expire).
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class CancelAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
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
     * @param Cancel $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (!\in_array($details['status'] ?? null, [Api::STATUS_HOLDED, Api::STATUS_HOLD_CONFIRMED], true)) {
            return;
        }

        if (empty($details['session_id'])) {
            return;
        }

        $this->api->void((string) $details['session_id']);

        $this->gateway->execute(new Sync($request->getFirstModel()));
    }

    public function supports($request)
    {
        return
            $request instanceof Cancel
            && $request->getModel() instanceof \ArrayAccess;
    }
}
