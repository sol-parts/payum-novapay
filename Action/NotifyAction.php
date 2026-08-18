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
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use Payum\Core\Request\Sync;

/**
 * Постбек NovaPay (`callback_url`).
 *
 * Трактуємо постбек лише як тригер синхронізації: реальний статус тягне
 * {@see SyncAction} через `get-status` — це джерело правди, захищене нашими
 * merchant-credentials (підробити його результат ззовні неможливо), а notify-URL
 * несе unguessable Payum-токен. Тіло постбека не парсимо, тож не гейтимо на
 * `x-sign` (публічний ключ NovaPay для acquiring видається в кабінеті мерчанта,
 * а не в доках).
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class NotifyAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /**
     * @param Notify $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $this->gateway->execute(new Sync($request->getModel()));

        throw new HttpResponse('OK', 200);
    }

    public function supports($request)
    {
        return
            $request instanceof Notify
            && $request->getModel() instanceof \ArrayAccess;
    }
}
