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

namespace SolParts\PayumNovaPay\Exception;

use Payum\Core\Exception\LogicException;

/**
 * Помилкова відповідь NovaPay із розібраним конвертом помилки.
 *
 * Кидається замість generic LogicException, коли тіло помилки — JSON-конверт
 * NovaPay. Форм дві: валідаційна `{uuid, type: validation, errors: [{message,
 * code, path}]}` і процесингова `{uuid, type: processing, error, code}`.
 * Споживачі гілкуються за `type`, полями з `errors[]` і `errorCode`, не парсячи
 * текст винятку: `Api::sessionCreate()` на валідаційній відмові по опційних
 * реквізитах клієнта створює сесію без них, `NotifyAction` розпізнає сесію,
 * якої шлюз уже не віддає.
 *
 * @author Andrii Didenko <andrii@didenko.dev>
 */
class NovaPayApiException extends LogicException
{
    /** Тіло запиту не пройшло валідацію; кожне відхилене поле — окремий елемент `errors[]`. */
    public const TYPE_VALIDATION = 'validation';

    /**
     * Коди «сесії немає»: задокументований `SessionNotFoundError` і фактичний
     * `NotFoundError` («database returned no rows»), яким `get-status` відповідає
     * на сесію після закінчення терміну дії.
     */
    private const SESSION_NOT_FOUND_CODES = ['SessionNotFoundError', 'NotFoundError'];

    /**
     * @param list<mixed> $errors елементи `errors[]` конверта як є — форму перевіряє споживач
     * @param string|null $errorCode `code` процесингового конверта (`SessionNotFoundError`, `WrongAmountError`, …)
     */
    public function __construct(
        string $message,
        public readonly ?string $type = null,
        public readonly array $errors = [],
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    /** Шлюз не знайшов сесію за `session_id` — не знає її або вже не віддає. */
    public function isSessionNotFound(): bool
    {
        return \in_array($this->errorCode, self::SESSION_NOT_FOUND_CODES, true);
    }

    /**
     * Поля ТІЛА ЗАПИТУ, які шлюз відхилив як невалідні, — `errors[].path`.
     *
     * Порожній список означає «нема чого чинити точково»: або відмова не
     * валідаційна (підпис, мерчант), або хоч один елемент `errors[]` має
     * незнайому форму. Здогадок тут не будуємо — краще віддати помилку нагору.
     *
     * @return list<string>
     */
    public function invalidFields(): array
    {
        if (self::TYPE_VALIDATION !== $this->type) {
            return [];
        }

        $fields = [];

        foreach ($this->errors as $error) {
            if (!\is_array($error) || !\is_string($error['path'] ?? null)) {
                return [];
            }

            $fields[] = $error['path'];
        }

        return $fields;
    }
}
