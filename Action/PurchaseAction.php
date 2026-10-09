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
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Sync;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use SolParts\PayumNovaPay\Api;

/**
 * Спільне тіло Capture/Authorize для NovaPay acquiring.
 *
 * Flow: `POST /session` (реквізити клієнта + redirect/callback-URL) → `POST /payment`
 * (сума, `use_hold`) → редірект браузера на `url` з відповіді. `use_hold=true`
 * (двостадійна оплата) підставляє {@see AuthorizeAction}.
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
abstract class PurchaseAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /** @var Api */
    protected $api;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        // Повторний візит, поки оплата ще в процесі: сесія й url уже є —
        // повертаємо клієнта на ту саму сторінку оплати NovaPay.
        $this->gateway->execute($status = new GetHumanStatus($details));
        if ($status->isPending() && !empty($details['url'])) {
            throw new HttpRedirect((string) $details['url']);
        }

        // Сесія вже створена (фінальний/невизначений статус) — лише синкнути.
        if (!empty($details['session_id'])) {
            $this->gateway->execute(new Sync($details));

            return;
        }

        if ($request->getToken()) {
            // Return-URL шлюзу вішаємо на `done`-токен (afterUrl capture-токена), а
            // не на сам одноразовий capture-токен: NovaPay зашиває success_url/fail_url
            // у сесію назавжди й переюзає сесію на resume, а capture-токен
            // інвалідовується на першому поверненні → друге/після-resume повернення
            // падало б у 404. `done`-токен Payum лишає повторно-запитуваним.
            if (empty($details['success_url'])) {
                $doneUrl = $request->getToken()->getAfterUrl();

                $details['success_url'] = $doneUrl;
                $details['fail_url'] = $doneUrl;
            }

            if (empty($details['callback_url'])) {
                $notifyToken = $this->tokenFactory->createNotifyToken(
                    $request->getToken()->getGatewayName(),
                    $request->getToken()->getDetails(),
                );

                $details['callback_url'] = $notifyToken->getTargetUrl();
            }
        }

        $session = $this->api->sessionCreate((array) $details);
        $details['session_id'] = $session['id'];
        $details['status'] = $session['status'] ?? Api::STATUS_CREATED;

        // Реквізити, які NovaPay відхилив і сесія створена без них (див. Api::sessionCreate).
        // Без сліду менеджеру не пояснити, чому шлюз не надіслав клієнту квитанцію на
        // email із замовлення. Ключ без `_`-префікса навмисно: це факт про сесію, як і
        // `session_id`, а службові `_`-ключі спільний Payum-extension хоста знімає перед
        // кожним новим Capture. У тіла запитів він не потрапляє — Api збирає їх за
        // явними списками полів.
        if (!empty($session['dropped_fields'])) {
            $details['dropped_fields'] = $session['dropped_fields'];
        }

        // Точковий мердж (не replace) — щоб зберегти session_id, реквізити клієнта та amount.
        $payment = $this->api->paymentCreate((array) $details);
        foreach ($payment as $key => $value) {
            $details[$key] = $value;
        }

        if (empty($details['url'])) {
            throw new LogicException('NovaPay: відповідь /payment не містить url для редіректу.');
        }

        throw new HttpRedirect((string) $details['url']);
    }
}
