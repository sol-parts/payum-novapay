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

/**
 * Контракт запиту до NovaPay: підпис, заголовки, склад тіла — і специфічна для цього
 * шлюзу семантика успішних відповідей.
 *
 * Найкрихкіше місце інтеграції — `x-sign`: NovaPay перевіряє RSA-SHA256-підпис ТОЧНОГО
 * рядка тіла, байт-у-байт. Будь-яка розбіжність (перекодований JSON, інший порядок
 * ключів, зайвий пробіл) дає відмову шлюзу, яку з боку магазину не діагностувати. Тест
 * верифікує підпис публічною половиною того самого ключа саме над рядком, що поїхав у body.
 *
 * Друга специфіка: успішні `void`/`complete-hold` відповідають порожнім 200-тілом або
 * JSON `null` — для цього шлюзу це штатний успіх, а не поломка контракту.
 *
 * @cmd vendor/bin/phpunit packages/PayumNovaPay/Tests/ApiTest.php -c phpunit.xml.dist
 */
#[CoversClass(Api::class)]
final class ApiTest extends TestCase
{
    /**
     * `x-sign` — валідний RSA-SHA256-підпис саме того рядка, що пішов у body;
     * `merchant_id` дублюється в заголовку і в тілі — так вимагає API.
     */
    public function testRequestIsSignedOverExactBodyBytes(): void
    {
        [$api, $client] = $this->createRecordingApi(new Response(200, [], '{"id":"sess-1","status":"created"}'));

        $api->sessionCreate(['client_phone' => '+380000000000']);

        self::assertCount(1, $client->requests);
        $request = $client->requests[0];

        $body = (string) $request->getBody();
        $signature = \base64_decode($request->getHeaderLine('x-sign'), true);
        self::assertNotFalse($signature);

        $privateKey = \openssl_pkey_get_private(self::privateKeyPem());
        self::assertNotFalse($privateKey);
        $details = \openssl_pkey_get_details($privateKey);
        self::assertNotFalse($details);
        self::assertSame(1, \openssl_verify($body, $signature, $details['key'], \OPENSSL_ALGO_SHA256));

        self::assertSame('test-merchant', $request->getHeaderLine('merchant_id'));

        $decoded = \json_decode($body, true);
        self::assertSame('test-merchant', $decoded['merchant_id']);
        self::assertSame('+380000000000', $decoded['client_phone']);
    }

    /**
     * У тіло /session їдуть лише заповнені опційні поля: порожній рядок і відсутній ключ
     * відкидаються, а не їдуть як `""` (NovaPay валідує формат кожного присутнього поля).
     */
    public function testSessionCreateDropsEmptyOptionalFields(): void
    {
        [$api, $client] = $this->createRecordingApi(new Response(200, [], '{"id":"sess-1"}'));

        $api->sessionCreate([
            'client_phone' => '+380000000000',
            'client_email' => '',
            'success_url' => 'https://example.com/done',
        ]);

        $decoded = \json_decode((string) $client->requests[0]->getBody(), true);
        self::assertArrayNotHasKey('client_email', $decoded);
        self::assertArrayNotHasKey('fail_url', $decoded);
        self::assertSame('https://example.com/done', $decoded['success_url']);
    }

    /** Відповідь /session без id — зламаний контракт: далі працювати нема з чим. */
    public function testSessionCreateWithoutIdIsAContractViolation(): void
    {
        [$api] = $this->createRecordingApi(new Response(200, [], '{"status":"created"}'));

        $this->expectException(LogicException::class);

        $api->sessionCreate(['client_phone' => '+380000000000']);
    }

    /** Порожнє 200-тіло від void — штатний успіх («операцію прийнято»), не виняток. */
    public function testEmptySuccessBodyIsAcceptedAsEmptyResult(): void
    {
        [$api] = $this->createRecordingApi(new Response(200, [], ''));

        self::assertSame([], $api->void('sess-1'));
    }

    /** JSON `null` на 200 — та сама безтільна відповідь, що й порожній body. */
    public function testJsonNullSuccessBodyIsAcceptedAsEmptyResult(): void
    {
        [$api] = $this->createRecordingApi(new Response(200, [], 'null'));

        self::assertSame([], $api->void('sess-1'));
    }

    /**
     * @return array{0: Api, 1: HttpClientInterface&object{requests: list<RequestInterface>}}
     */
    private function createRecordingApi(ResponseInterface $response): array
    {
        $psr17 = new Psr17Factory();

        $client = new class($response) implements HttpClientInterface {
            /** @var list<RequestInterface> */
            public array $requests = [];

            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function send(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;

                return $this->response;
            }
        };

        $api = new Api([
            'merchant_id' => 'test-merchant',
            'merchant_private_key' => self::privateKeyPem(),
            'sandbox' => true,
        ], $client, $psr17, $psr17);

        return [$api, $client];
    }

    /** Один ключ на прогін: фіксованого ключа в репозиторії не тримаємо. */
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
