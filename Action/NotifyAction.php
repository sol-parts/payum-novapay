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
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use Payum\Core\Request\Sync;
use SolParts\PayumNovaPay\Api;
use SolParts\PayumNovaPay\Exception\NovaPayApiException;

/**
 * Постбек NovaPay (`callback_url`).
 *
 * Трактуємо постбек лише як тригер синхронізації: реальний статус тягне
 * {@see SyncAction} через `get-status` — це джерело правди, захищене нашими
 * merchant-credentials (підробити його результат ззовні неможливо), а notify-URL
 * несе unguessable Payum-токен. Тому на `x-sign` не гейтимо (публічний ключ
 * NovaPay для acquiring видається в кабінеті мерчанта, а не в доках).
 *
 * Виняток — закінчення терміну дії сесії (30 днів від створення). Прострочену
 * сесію `get-status` уже не віддає, а відповідає «сесії немає», тож статус
 * `expired` приходить лише тілом постбека. Приймаємо його тільки разом із
 * відмовою API: постбек каже `expired` саме для цієї сесії, а шлюз її не
 * знаходить — обидва сигнали про одне.
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

        $details = ArrayObject::ensureArrayObject($request->getModel());

        try {
            $this->gateway->execute(new Sync($details));
        } catch (NovaPayApiException $e) {
            if (!$e->isSessionNotFound() || !$this->isExpiryPostbackFor($details)) {
                throw $e;
            }

            $details['status'] = Api::STATUS_EXPIRED;
        }

        throw new HttpResponse('OK', 200);
    }

    /**
     * Постбек про закінчення терміну дії саме цієї сесії, яку ми востаннє бачили
     * ще не оплаченою.
     *
     * Перехід у `expired` незворотний, тож без такого постбека «сесії немає» лишається
     * помилкою: інакше збій на боці шлюзу назавжди поховав би оплату, про яку
     * постбек `paid` щойно сповістив. Оплачену чи заблоковану сесію не чіпаємо
     * взагалі — термін дії обриває лише неоплачену.
     */
    private function isExpiryPostbackFor(ArrayObject $details): bool
    {
        if (!\in_array($details['status'] ?? null, [Api::STATUS_CREATED, Api::STATUS_PROCESSING], true)) {
            return false;
        }

        $this->gateway->execute($http = new GetHttpRequest());
        $postback = \json_decode((string) $http->content, true);

        return \is_array($postback)
            && Api::STATUS_EXPIRED === ($postback['status'] ?? null)
            && (string) $details['session_id'] === ($postback['id'] ?? null);
    }

    public function supports($request)
    {
        return
            $request instanceof Notify
            && $request->getModel() instanceof \ArrayAccess;
    }
}
