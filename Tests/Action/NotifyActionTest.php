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

namespace SolParts\PayumNovaPay\Tests\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use Payum\Core\Request\Sync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SolParts\PayumNovaPay\Action\NotifyAction;
use SolParts\PayumNovaPay\Api;
use SolParts\PayumNovaPay\Exception\NovaPayApiException;

/**
 * Постбек про закінчення терміну дії сесії.
 *
 * Сесія NovaPay живе 30 днів. Коли неоплачена сесія спливає, шлюз шле постбек
 * `expired`, але `get-status` таку сесію вже не віддає — відповідає 400
 * `NotFoundError` «database returned no rows». Раніше Notify на цьому падав:
 * транзакція назавжди лишалась `pending`, у журнал помилок і в історію замовлення
 * летіла «помилка оплати», а шлюз отримував не 200.
 *
 * Перехід у `expired` незворотний, тож тест закріплює і зворотний бік: будь-яке
 * інше «сесії немає» (постбек не про expiry, чужа сесія, сесію вже оплачено) має
 * лишатися помилкою — інакше збій на боці шлюзу назавжди поховав би реальну оплату.
 *
 * @cmd vendor/bin/phpunit Tests/Action/NotifyActionTest.php
 */
#[CoversClass(NotifyAction::class)]
final class NotifyActionTest extends TestCase
{
    private const SESSION_ID = '0e01d8f7-0000-4000-8000-000000000001';

    /**
     * Штатний випадок, що стріляв у журнал: неоплачена сесія спливла, постбек
     * `expired` прийшов саме про неї, а API її вже не знаходить. Сесія має стати
     * `expired`, а шлюз — отримати 200, щоб не повторювати постбек.
     */
    public function testExpiryPostbackForVanishedUnpaidSessionMarksExpired(): void
    {
        $details = self::details(Api::STATUS_CREATED);

        $reply = $this->notify($details, self::sessionNotFound(), self::postback(Api::STATUS_EXPIRED, self::SESSION_ID));

        self::assertSame(200, $reply->getStatusCode());
        self::assertSame(Api::STATUS_EXPIRED, $details['status']);
    }

    /**
     * Клієнт почав вводити картку і кинув — остання відома стадія `processing`.
     * Термін дії обриває й таку сесію, тож вона теж має стати `expired`.
     */
    public function testExpiryPostbackForAbandonedProcessingSessionMarksExpired(): void
    {
        $details = self::details(Api::STATUS_PROCESSING);

        $this->notify($details, self::sessionNotFound(), self::postback(Api::STATUS_EXPIRED, self::SESSION_ID));

        self::assertSame(Api::STATUS_EXPIRED, $details['status']);
    }

    /**
     * Документований код `SessionNotFoundError` («сесію не знайдено або дозволений
     * час її використання сплив») означає те саме, що й фактичний `NotFoundError`.
     */
    public function testDocumentedSessionNotFoundCodeIsRecognisedToo(): void
    {
        $details = self::details(Api::STATUS_CREATED);

        $this->notify(
            $details,
            new NovaPayApiException('Bad Request', 'processing', [], 'SessionNotFoundError'),
            self::postback(Api::STATUS_EXPIRED, self::SESSION_ID),
        );

        self::assertSame(Api::STATUS_EXPIRED, $details['status']);
    }

    /**
     * Сценарії, де «сесії немає» лишається помилкою і стан не змінюється.
     *
     * @return iterable<string, array{string, NovaPayApiException, string}>
     */
    public static function notExpiryProvider(): iterable
    {
        // Головна причина двох сигналів: постбек `paid` + збій пошуку на боці шлюзу
        // не мають перетворити щойно оплачену сесію на прострочену.
        yield 'постбек про оплату, не про expiry' => [
            Api::STATUS_PROCESSING,
            self::sessionNotFound(),
            self::postback(Api::STATUS_PAID, self::SESSION_ID),
        ];

        yield 'постбек expired про чужу сесію' => [
            Api::STATUS_CREATED,
            self::sessionNotFound(),
            self::postback(Api::STATUS_EXPIRED, 'foreign-session'),
        ];

        // Термін дії обриває лише неоплачену сесію; оплачена в `expired` не переходить.
        yield 'сесія вже оплачена' => [
            Api::STATUS_PAID,
            self::sessionNotFound(),
            self::postback(Api::STATUS_EXPIRED, self::SESSION_ID),
        ];

        yield 'тіло постбека не JSON' => [
            Api::STATUS_CREATED,
            self::sessionNotFound(),
            'not a json',
        ];

        // Інша відмова шлюзу — не про відсутність сесії, тіло постбека тут нічого не вирішує.
        yield 'інша процесингова помилка' => [
            Api::STATUS_CREATED,
            new NovaPayApiException('Bad Request', 'processing', [], 'WrongAmountError'),
            self::postback(Api::STATUS_EXPIRED, self::SESSION_ID),
        ];
    }

    #[DataProvider('notExpiryProvider')]
    public function testOtherSessionNotFoundCasesStayErrors(string $knownStatus, NovaPayApiException $apiError, string $postback): void
    {
        $details = self::details($knownStatus);

        try {
            $this->notify($details, $apiError, $postback);
            self::fail('Очікували, що помилка get-status долетить нагору.');
        } catch (NovaPayApiException $e) {
            self::assertSame($apiError, $e);
        }

        self::assertSame($knownStatus, $details['status']);
    }

    /**
     * Звичайний постбек: Sync відпрацював — тіло не читаємо взагалі, стан приносить
     * `get-status`, шлюзу відповідаємо 200.
     */
    public function testSuccessfulSyncDoesNotReadPostbackBody(): void
    {
        $details = self::details(Api::STATUS_CREATED);

        $reply = $this->notify($details, null, null);

        self::assertSame(200, $reply->getStatusCode());
        self::assertSame(Api::STATUS_CREATED, $details['status']);
    }

    private static function details(string $status): ArrayObject
    {
        return new ArrayObject(['session_id' => self::SESSION_ID, 'status' => $status]);
    }

    /** Відповідь `get-status` на прострочену сесію, як її віддає NovaPay. */
    private static function sessionNotFound(): NovaPayApiException
    {
        return new NovaPayApiException('Bad Request for "/get-status" with code "400".', 'processing', [], 'NotFoundError');
    }

    private static function postback(string $status, string $sessionId): string
    {
        return \json_encode(['id' => $sessionId, 'status' => $status], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param NovaPayApiException|null $syncError null — Sync проходить
     * @param string|null $postback null — тіло читати не можна
     */
    private function notify(ArrayObject $details, ?NovaPayApiException $syncError, ?string $postback): HttpResponse
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            static function (object $request) use ($syncError, $postback): void {
                if ($request instanceof Sync && null !== $syncError) {
                    throw $syncError;
                }

                if ($request instanceof GetHttpRequest) {
                    self::assertNotNull($postback, 'Тіло постбека читається лише тоді, коли шлюз не знайшов сесію.');
                    $request->content = $postback;
                }
            },
        );

        $action = new NotifyAction();
        $action->setGateway($gateway);

        try {
            $action->execute(new Notify($details));
        } catch (HttpResponse $reply) {
            return $reply;
        }

        self::fail('Очікували HttpResponse-reply від NotifyAction.');
    }
}
