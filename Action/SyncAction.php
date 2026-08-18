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
use Payum\Core\Request\Sync;
use SolParts\PayumNovaPay\Api;

/**
 * Підтягує актуальний стан сесії через `POST /get-status` (джерело правди).
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class SyncAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
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
        /** @var Sync $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        if (empty($details['session_id'])) {
            return;
        }

        $result = $this->api->getStatus((string) $details['session_id']);

        // Точковий мердж — зберігаємо session_id/url/реквізити, оновлюємо status/payments.
        foreach ($result as $key => $value) {
            $details[$key] = $value;
        }
    }

    public function supports($request)
    {
        return
            $request instanceof Sync
            && $request->getModel() instanceof \ArrayAccess;
    }
}
