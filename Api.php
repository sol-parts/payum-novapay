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

namespace SolParts\PayumNovaPay;

use Payum\Core\Exception\LogicException;
use Payum\Core\HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Клієнт NovaPay eCommerce Acquiring (інтернет-еквайринг НоваПей).
 *
 * Кожен запит — POST JSON з заголовками `merchant_id` + `x-sign`, де `x-sign` —
 * RSA-SHA256-підпис ТОЧНОГО рядка тіла (той самий байт-у-байт JSON, що йде в body),
 * закодований у base64.
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 *
 * @see https://novapay.readme.io/reference/introduction-to-novapay-api-integration
 */
class Api
{
    /** Сесію створено, платник ще не почав оплату. */
    public const STATUS_CREATED = 'created';
    /** Платник вводить платіжні дані. */
    public const STATUS_PROCESSING = 'processing';
    /** Кошти заблоковано на рахунку платника (двостадійна оплата, чекає complete-hold). */
    public const STATUS_HOLDED = 'holded';
    /** Блокування підтверджено продавцем. */
    public const STATUS_HOLD_CONFIRMED = 'hold_confirmed';
    /** Триває списання заблокованих коштів. */
    public const STATUS_PROCESSING_HOLD_COMPLETION = 'processing_hold_completion';
    /** Сесію повністю оплачено. Фінальний успіх. */
    public const STATUS_PAID = 'paid';
    /** Оплата не пройшла. Фінальний неуспіх. */
    public const STATUS_FAILED = 'failed';
    /** Триває анулювання/повернення. */
    public const STATUS_PROCESSING_VOID = 'processing_void';
    /** Оплату анульовано або кошти повернено. Фінальний. */
    public const STATUS_VOIDED = 'voided';
    /** Термін дії сесії вичерпано. Фінальний неуспіх. */
    public const STATUS_EXPIRED = 'expired';

    private const ENDPOINT_SANDBOX = 'https://api-qecom.novapay.ua/v1';
    private const ENDPOINT_PRODUCTION = 'https://api-ecom.novapay.ua/v1';

    /**
     * З запасом покриває помилкову відповідь NovaPay — див. {@see contentSnippet()}.
     */
    private const CONTENT_SNIPPET_LENGTH = 500;

    /**
     * @var HttpClientInterface
     */
    protected $client;

    /**
     * @var array<mixed>
     */
    protected $options = [];

    /**
     * @param array<mixed> $options
     *
     * @throws \Payum\Core\Exception\InvalidArgumentException if an option is invalid
     */
    public function __construct(
        array $options,
        HttpClientInterface $client,
        protected RequestFactoryInterface $requestFactory,
        protected StreamFactoryInterface $streamFactory,
    ) {
        $this->options = $options;
        $this->client = $client;
    }

    /**
     * POST /session — ініціює платіжну сесію. Обов'язковий лише `client_phone`
     * (E.164, `+380…`); решта полів клієнта та redirect/callback-URL — опційні.
     *
     * @param array<mixed> $details
     *
     * @return array{id: string, status?: string}
     */
    public function sessionCreate(array $details): array
    {
        $body = ['merchant_id' => $this->merchantId()];

        foreach ([
            'client_phone', 'client_first_name', 'client_last_name', 'client_patronymic',
            'client_email', 'callback_url', 'success_url', 'fail_url', 'metadata',
        ] as $key) {
            if (isset($details[$key]) && '' !== $details[$key]) {
                $body[$key] = $details[$key];
            }
        }

        $response = $this->doRequest('/session', $body);
        if (!\is_string($response['id'] ?? null) || '' === $response['id']) {
            throw new LogicException('NovaPay: відповідь /session не містить id.');
        }

        $result = ['id' => $response['id']];
        if (\is_string($response['status'] ?? null)) {
            $result['status'] = $response['status'];
        }

        return $result;
    }

    /**
     * POST /payment — додає платіж до сесії. У відповіді — `url`, на який треба
     * зробити редірект браузера клієнта для введення картки. `amount` — у гривнях
     * (major units з копійками як дробова частина), НЕ в копійках. `use_hold=true`
     * робить оплату двостадійною (кошти блокуються до complete-hold).
     *
     * @param array<mixed> $details
     *
     * @return array<mixed>
     */
    public function paymentCreate(array $details): array
    {
        $body = [
            'merchant_id' => $this->merchantId(),
            'session_id' => $details['session_id'],
            'amount' => (float) $details['amount'],
            'use_hold' => (bool) ($details['use_hold'] ?? false),
        ];

        if (isset($details['external_id']) && '' !== $details['external_id']) {
            $body['external_id'] = (string) $details['external_id'];
        }

        return $this->doRequest('/payment', $body);
    }

    /**
     * POST /get-status — авторитетний стан сесії (джерело правди для Sync/Notify).
     *
     * @return array<mixed>
     */
    public function getStatus(string $sessionId): array
    {
        return $this->doRequest('/get-status', [
            'merchant_id' => $this->merchantId(),
            'session_id' => $sessionId,
        ]);
    }

    /**
     * POST /complete-hold — списання раніше заблокованих коштів (двостадійна оплата).
     * `$amount === null` → списати повну суму холду; інакше — часткове списання.
     *
     * @return array<mixed>
     */
    public function completeHold(string $sessionId, ?float $amount = null): array
    {
        $body = [
            'merchant_id' => $this->merchantId(),
            'session_id' => $sessionId,
        ];

        if (null !== $amount) {
            $body['amount'] = $amount;
        }

        return $this->doRequest('/complete-hold', $body);
    }

    /**
     * POST /void — один ендпоінт і для reversal холду, і для повернення оплачених
     * коштів. `$operations` (кожна — `{id, refund_amount}`) задають ЧАСТКОВЕ
     * повернення; порожній масив → повне анулювання/повернення сесії.
     *
     * @param list<array{id: string, refund_amount: string}> $operations
     *
     * @return array<mixed>
     */
    public function void(string $sessionId, array $operations = []): array
    {
        $body = [
            'merchant_id' => $this->merchantId(),
            'session_id' => $sessionId,
        ];

        if ([] !== $operations) {
            $body['operations'] = $operations;
        }

        return $this->doRequest('/void', $body);
    }

    /**
     * RSA-SHA256-підпис рядка тіла запиту, base64. Підписувати треба саме той
     * рядок, що йде в body ({@see doRequest()}), інакше NovaPay відхилить `x-sign`.
     */
    public function sign(string $body): string
    {
        $signature = '';

        if (!\openssl_sign($body, $signature, (string) $this->options['merchant_private_key'], \OPENSSL_ALGO_SHA256)) {
            throw new LogicException('NovaPay: не вдалося підписати запит приватним ключем мерчанта.');
        }

        return \base64_encode((string) $signature);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function doRequest(string $path, array $body): array
    {
        $json = \json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $request = $this->requestFactory
            ->createRequest('POST', $this->getApiEndpoint() . $path)
            ->withHeader('merchant_id', $this->merchantId())
            ->withHeader('x-sign', $this->sign($json))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($json));

        $response = $this->client->send($request);
        $content = $response->getBody()->getContents();

        // Статус — ДО парсингу. Помилку шлюз віддає не тільки як JSON: на 429/5xx
        // приходить HTML балансувальника, і json_decode падав би першим — діагностика
        // вироджувалась би у «Syntax error» без коду відповіді й без тіла, хоча реальна
        // причина (throttling, недоступність) стоїть саме в статусі.
        if ($response->getStatusCode() >= 400) {
            throw new LogicException(\sprintf(
                '%s for "%s" with code "%s". Content: %s.',
                $response->getReasonPhrase(),
                $request->getUri(),
                $response->getStatusCode(),
                '' === $content ? '(empty)' : self::contentSnippet($content),
            ));
        }

        // Успішні void/complete-hold/expire повертають порожнє 200-тіло АБО
        // JSON `null` — трактуємо як «операцію прийнято, структурованого тіла немає».
        if ('' === $content) {
            return [];
        }

        try {
            $result = \json_decode($content, true, 512, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // 2xx з не-JSON тілом: віддаємо його фрагмент, інакше причину не відновити.
            throw new LogicException(\sprintf(
                '%s for "%s" with code "%s". Content: %s.',
                $e->getMessage(),
                $request->getUri(),
                $response->getStatusCode(),
                self::contentSnippet($content),
            ));
        }

        // `null` (JSON null) на 2xx — не помилка, а безтільна відповідь void/complete-hold;
        // структуровані відповіді (session/payment/get-status) завжди декодуються в масив.
        if (!\is_array($result)) {
            return [];
        }

        return $result;
    }

    /**
     * Тіло відповіді для тексту винятку. Обрізаємо, бо на 5xx це буває HTML-сторінка на
     * десятки кілобайт, яка розпирає і лог помилок, і `details` транзакції.
     */
    private static function contentSnippet(string $content): string
    {
        return \mb_strlen($content) > self::CONTENT_SNIPPET_LENGTH
            ? \mb_substr($content, 0, self::CONTENT_SNIPPET_LENGTH) . '…'
            : $content;
    }

    protected function getApiEndpoint(): string
    {
        return ($this->options['sandbox'] ?? true) ? self::ENDPOINT_SANDBOX : self::ENDPOINT_PRODUCTION;
    }

    private function merchantId(): string
    {
        return (string) $this->options['merchant_id'];
    }
}
