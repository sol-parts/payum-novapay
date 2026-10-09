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

namespace SolParts\PayumNovaPay\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Exception\LogicException;
use Payum\Core\HttpClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SolParts\PayumNovaPay\Api;
use SolParts\PayumNovaPay\Exception\NovaPayApiException;

/**
 * Що саме потрапляє в текст винятку, коли NovaPay відповів помилкою.
 *
 * Ціна питання — не краса повідомлення, а діагностованість: без коду відповіді й тіла
 * у тексті винятку не відрізнити throttling від 5xx і від зламаного контракту.
 *
 * Помилку шлюз віддає не тільки як JSON: на 429/5xx приходить HTML балансувальника.
 * Якби `json_decode` стояв ПЕРЕД перевіркою статусу, кожна така відповідь давала б
 * «Syntax error for <url>» — без коду і без тіла. Порядок перевірок (статус ДО парсингу)
 * і закріплює цей тест.
 *
 * @cmd vendor/bin/phpunit Tests/ApiErrorReportingTest.php
 */
#[CoversClass(Api::class)]
final class ApiErrorReportingTest extends TestCase
{
    /**
     * Не-JSON тіло на помилковому статусі не ховає код: рішення «це throttling,
     * а не поломка інтеграції» ухвалюється саме за ним.
     */
    public function testNonJsonErrorResponseReportsStatusCode(): void
    {
        $exception = $this->captureSessionCreateFailure(
            new Response(429, [], '<html><head><title>429 Too Many Requests</title></head></html>'),
        );

        self::assertStringContainsString('with code "429"', $exception->getMessage());
        self::assertStringContainsString('429 Too Many Requests', $exception->getMessage());
    }

    /**
     * 200 з не-JSON тілом — окремий випадок від помилкового статусу: контракт зламано на
     * успішній відповіді. Код усе одно називаємо, інакше не відрізнити його від 5xx.
     */
    public function testBrokenJsonOnSuccessStatusStillReportsBody(): void
    {
        $exception = $this->captureSessionCreateFailure(new Response(200, [], 'not a json at all'));

        self::assertStringContainsString('Syntax error', $exception->getMessage());
        self::assertStringContainsString('with code "200"', $exception->getMessage());
        self::assertStringContainsString('not a json at all', $exception->getMessage());
    }

    /**
     * HTML-сторінка 5xx буває на десятки кілобайт — у лог помилок і в `details` транзакції
     * такий текст їхати не повинен.
     */
    public function testHugeBodyIsTruncated(): void
    {
        $exception = $this->captureSessionCreateFailure(
            new Response(502, [], \str_repeat('x', 50_000)),
        );

        self::assertLessThan(1500, \mb_strlen($exception->getMessage()));
        self::assertStringContainsString('…', $exception->getMessage());
    }

    /** Порожнє тіло на помилковому статусі теж має нести код: «(empty)» без коду не діагностується. */
    public function testEmptyBodyOnErrorStatusReportsStatusCode(): void
    {
        $exception = $this->captureSessionCreateFailure(new Response(503, [], ''));

        self::assertStringContainsString('with code "503"', $exception->getMessage());
        self::assertStringContainsString('(empty)', $exception->getMessage());
    }

    /**
     * Процесингову відмову NovaPay віддає без `errors[]` — з плоскими `error` і `code`.
     * Саме за `code` споживач розпізнає «сесії немає» (прострочена сесія на `get-status`),
     * тож він мусить доїхати у виняток, а не лишитись тільки в тексті повідомлення.
     */
    public function testProcessingEnvelopeExposesErrorCode(): void
    {
        $exception = $this->captureSessionCreateFailure(new Response(400, [], \json_encode([
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'type' => 'processing',
            'error' => 'database returned no rows',
            'description' => '',
            'code' => 'NotFoundError',
        ], \JSON_THROW_ON_ERROR)));

        self::assertInstanceOf(NovaPayApiException::class, $exception);
        self::assertSame('NotFoundError', $exception->errorCode);
        self::assertTrue($exception->isSessionNotFound());
    }

    private function captureSessionCreateFailure(ResponseInterface $response): LogicException
    {
        $psr17 = new Psr17Factory();

        $client = new class($response) implements HttpClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function send(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $api = new Api([
            'merchant_id' => 'test-merchant',
            'merchant_private_key' => self::privateKeyPem(),
            'sandbox' => true,
        ], $client, $psr17, $psr17);

        try {
            $api->sessionCreate(['client_phone' => '+380000000000']);
        } catch (LogicException $e) {
            return $e;
        }

        self::fail('Очікували LogicException від Api::sessionCreate()');
    }

    /**
     * Api підписує тіло ЩЕ ДО відправки запиту, тож навіть тестам про помилкові відповіді
     * потрібен справжній RSA-ключ. Генеруємо один на прогін — фіксованого ключа в репозиторії
     * не тримаємо.
     */
    private static function privateKeyPem(): string
    {
        static $pem = null;

        if (null === $pem) {
            $key = \openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            ]);
            self::assertNotFalse($key, 'Не вдалося згенерувати тестовий RSA-ключ');
            \openssl_pkey_export($key, $pem);
        }

        return $pem;
    }
}
