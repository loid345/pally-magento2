# Pally / pal24.pro — API Reference (snapshot)

> Источник: <https://pally.info/reference/api> (русская версия).
> Файл сохранён в репозитории, чтобы не зависеть от JavaScript‑challenge
> на сайте Pally. Снимок сделан вручную — при изменении протокола
> документацию нужно обновить и сверить с модулем.
>
> **Источник истины для этого модуля** — именно этот файл.
> Всё, чего здесь нет, модуль не реализует по договорённости.

## Базовый URL и авторизация

| Параметр | Значение |
| --- | --- |
| Базовый URL | `https://pal24.pro` |
| Префикс путей | `/api/v1` |
| Авторизация | HTTP‑заголовок `Authorization: Bearer {api_token}` |
| Формат запросов | `application/x-www-form-urlencoded` (или `multipart/form-data`) |
| Формат ответов | JSON |

`api_token` берётся на странице «API интеграции» в личном кабинете Pally.
Тот же самый токен используется как секрет для проверки подписи postback —
**отдельного «пароля №2» в протоколе нет**.

Ошибка авторизации → HTTP `401 Unauthenticated`.

## Структура модели

### Bill (счёт)

| Поле | Тип | Значения | Описание |
| --- | --- | --- | --- |
| `id` | string | `jZqmaPvl9W` | Идентификатор счёта |
| `order_id` | string | `order-285394168` | Идентификатор заказа на стороне мерчанта |
| `active` | bool | `true`/`false` | Флаг активности |
| `status` | enum | `NEW` / `PROCESS` / `UNDERPAID` / `SUCCESS` / `OVERPAID` / `FAIL` | Статус счёта |
| `amount` | decimal | `24600.05` | Сумма счёта |
| `type` | enum | `MULTI` / `NORMAL` | Тип счёта |
| `currency_in` | enum | `USD` / `RUB` / `EUR` | Валюта счёта |
| `created_at` | datetime | `2020-10-19 17:00:00` | Дата создания |
| `ttl` | integer | `600` | Время жизни (сек) |

### Payment (платёж)

| Поле | Тип | Описание |
| --- | --- | --- |
| `id` | string | Идентификатор платежа |
| `bill_id` | string | Идентификатор родительского счёта |
| `status` | enum | `NEW` / `PROCESS` / `UNDERPAID` / `SUCCESS` / `OVERPAID` / `FAIL` |
| `amount` | decimal | Сумма платежа |
| `commission` | decimal | Комиссия |
| `account_amount` | decimal | Сумма зачисления на баланс |
| `account_currency_code` | string | Валюта зачисления |
| `refunded_amount` | decimal | Сумма возвратов |
| `from_card` | string | Маскированный номер карты |
| `account_bank` | string | Банк плательщика |
| `currency_in` | enum | `USD`/`RUB`/`EUR` |
| `created_at` | datetime | |
| `payer_phone` / `payer_email` / `payer_name` / `payer_comment` | string | Данные плательщика |
| `error_code` | int | Код ошибки (при FAIL) |
| `error_message` | string | Описание ошибки |

## Endpoints

### `POST /api/v1/bill/create` — создание счёта

**Обязательные параметры:**

| Поле | Тип | Пример | Описание |
| --- | --- | --- | --- |
| `amount` | decimal | `380.99` | Сумма |
| `shop_id` | string | `LXZv3R7Q8B` | **Обязателен**: без него не работают Success/Fail/Result URL |

**Опциональные параметры:**

| Поле | Тип | Значения | Описание |
| --- | --- | --- | --- |
| `order_id` | string | | ID заказа на стороне мерчанта, возвращается в postback |
| `description` | string | | Описание платежа |
| `type` | enum | `normal` / `multi` | Тип счёта |
| `locale` | enum | `ru` / `en` | Локаль платёжной формы |
| `currency_in` | enum | `RUB` / `USD` / `EUR` | Валюта. Если не передана — берётся из настроек магазина. **Другие валюты не принимаются — вернёт 422.** |
| `custom` | string | | Произвольное поле, возвращается в postback |
| `payer_pays_commission` | enum | `1` / `0` | Кто платит комиссию |
| `payer_email` | string | | Заполняет email плательщика на форме |
| `name` | string | | Название ссылки, отображается на форме оплаты |
| `ttl` | integer | `600` | Время жизни счёта в секундах |
| `return_url` | string | URL | Кнопка «Назад в магазин». Домен должен совпадать с доменом магазина |
| `success_url` | string | URL | Страница успешной оплаты |
| `fail_url` | string | URL | Страница неуспешной оплаты |
| `payment_method` | enum | `BANK_CARD` / `SBP` | Преднастроенный способ оплаты |
| `request_fields[email\|phone\|name\|comment]` | bool | | Обязательный запрос данных у плательщика |
| `items[n][name\|price\|quantity\|category\|extra[...]]` | array | | Список товаров |

> ⚠️ **Имя поля — `ttl`, а НЕ `lifetime`.**
> ⚠️ **`currency_in` принимает только RUB/USD/EUR.** При других — 422.
> ⚠️ **`shop_url` в параметрах запроса НЕ упоминается.** Его отправка,
> скорее всего, игнорируется Pally.

**Ответ (200 OK):**

```json
{
  "success": "true",
  "link_url": "https://pally.info/link/GkLWvKx3",
  "link_page_url": "https://pally.info/transfer/GkLWvKx3",
  "bill_id": "GkLWvKx3"
}
```

Обратите внимание: `success` в примерах документации приходит
**строкой** (`"true"`), но в других местах — булевым. Парсер должен
уметь обе формы.

**Коды ошибок:**

| Код | Ключ | Описание |
| --- | --- | --- |
| 400 | `api:error.transaction_rejected` | Транзакция отклонена |
| 401 | `Unauthenticated` | Неверный API токен |
| 403 | `api:error.invalid_amount` | Неверная сумма |
| 403 | `api:error.merchant_banned` | Доступ мерчанта запрещён |
| 403 | `api:error.merchant_not_found` | Мерчант не найден |
| 403 | `api:error.shop_not_found` | Магазин не найден |
| 403 | `api:error.shop_not_enabled` | Магазин неактивен |
| 403 | `api:error.access_denied` | Нет доступа к магазину |
| 403 | `api:error.ip_access_denied` | IP не в вайтлисте |
| 403 | `api:error.rate-not-found` | Направление недоступно |
| 422 | | Ошибка валидации |
| 500 | `api:error.general_error` | Внутренняя ошибка |

### `GET /api/v1/bill/status` — статус счёта

**Запрос:** `?id=<bill_id>`

**Ответ:**

```json
{
  "id": "LXZv3R7Q5B",
  "order_id": "order-285394168",
  "status": "NEW",
  "type": "MULTI",
  "amount": 100.05,
  "currency_in": "RUB",
  "created_at": "2020-11-11 14:46:20",
  "ttl": 600,
  "success": true
}
```

> В ответе **нет** поля `TrsId` — чтобы получить конкретные платежи по счёту,
> используйте `GET /api/v1/bill/payments?id=<bill_id>`.

### `GET /api/v1/payment/status` — статус платежа

**Запрос:** `?id=<payment_id>` (**не** bill_id).
`payment_id` — это то же значение, что приходит в postback как `TrsId`.

Опциональные:
- `refunds=1` — включить рефанды в ответ;
- `chargeback=1` — включить чарджбэки в ответ.

**Ответ:**

```json
{
  "id": "PQX2XD25a0",
  "bill_id": "do5G93m",
  "status": "SUCCESS",
  "amount": 35400,
  "commission": 400,
  "account_amount": 35000,
  "account_currency_code": "RUB",
  "refunded_amount": 0,
  "from_card": "676454******7272",
  "account_bank": "SBERBANK",
  "currency_in": "RUB",
  "created_at": "2025-10-19 17:00:00",
  "success": true,
  "refunds": [],
  "chargeback": []
}
```

> Имя поля статуса — **`status`** (нижний регистр).

### `GET /api/v1/bill/payments` — платежи по счёту (cursor‑пагинация)

Запрос: `?id=<bill_id>&per_page=<n>&cursor=<...>`

### Другие endpoints (не используются модулем сейчас)

- `POST /api/v1/bill/toggle_activity` — включить/выключить счёт (полезно для `MULTI`).
- `GET /api/v1/bill/search` — поиск счетов по периоду/магазину.
- `GET /api/v1/payment/search` — поиск платежей.
- `GET /api/v1/merchant/balance` — баланс мерчанта.
- `POST /api/v1/payout/personal/create`, `POST /api/v1/payout/regular/create`,
  `GET /api/v1/payout/search`, `GET /api/v1/payout/status`,
  `GET /api/v1/payout/dictionaries/sbp_banks` — выплаты.
- `POST /api/v1/refund/full/create`, `POST /api/v1/refund/partial/create`,
  `GET /api/v1/refund/search`, `GET /api/v1/refund/status` — рефанды.

## Postback (Result URL)

URL настраивается в личном кабинете Pally для каждого магазина.

- **Метод:** `POST`
- **Content‑Type:** `application/x-www-form-urlencoded`
- **Retry:** если сервер не ответил `200`, Pally повторит postback
  по стратегии `10^n` секунд (10 c, 100 c, 1000 c, …), всего до **5 попыток**.

**Параметры:**

| Поле | Обяз. | Тип | Значения | Описание |
| --- | --- | --- | --- | --- |
| `InvId` | да | string | | `order_id` из `bill/create` |
| `OutSum` | да | decimal | | Сумма платежа |
| `Commission` | да | decimal | | Комиссия |
| `TrsId` | да | string | | ID платежа (используется как `id` для `payment/status`) |
| `Status` | да | enum | `SUCCESS` / `UNDERPAID` / `OVERPAID` / `FAIL` | Статус платежа. `NEW`/`PROCESS` в postback **не приходят**. |
| `CurrencyIn` | да | enum | `USD` / `RUB` / `EUR` | Валюта платежа |
| `custom` | нет | string | | `custom` из `bill/create` |
| `AccountType` | нет | enum | `BANK_CARD` / `SBP` | Метод оплаты |
| `AccountNumber` | нет | string | `220220******7046` | Доп. информация о методе |
| `BalanceAmount` | нет | decimal | | Сумма зачисления на баланс |
| `BalanceCurrency` | нет | enum | `USD`/`RUB`/`EUR` | Валюта зачисления |
| `PayerPhone` | нет | string | | Телефон плательщика |
| `PayerEmail` | нет | string | | Email плательщика |
| `PayerName` | нет | string | | Имя плательщика |
| `PayerComment` | нет | string | | Комментарий плательщика |
| `ErrorCode` | нет | int | | Код ошибки (для `FAIL`) |
| `ErrorMessage` | нет | string | | Описание ошибки |
| `SignatureValue` | да | string | | Подпись |

**Формула подписи (именно она используется SignatureVerifier):**

```
SignatureValue = strtoupper( md5( OutSum . ":" . InvId . ":" . apiToken ) )
```

Только `OutSum` и `InvId` входят в подпись — остальные поля
(`Status`, `TrsId`, `Commission`, `custom`, …) протоколом не защищены.

## Success / Fail POST‑редиректы

После оплаты/отказа Pally отправляет покупателя на `success_url` / `fail_url`
**POST‑запросом** (не GET).

**Поля:**

| Поле | Обяз. | Тип | Описание |
| --- | --- | --- | --- |
| `InvId` | да | string | order_id |
| `OutSum` | да | decimal | Сумма |
| `CurrencyIn` | да | enum | `USD`/`RUB`/`EUR` |
| `custom` | нет | string | |
| `SignatureValue` | да | string | `strtoupper(md5(OutSum:InvId:apiToken))` |

> ⚠️ Контроллеры `Success` и `Fail` **должны** обрабатывать `POST`
> (а не `GET`) и уметь пропускать CSRF (`CsrfAwareActionInterface`).

## Refund / Chargeback / P2P Deal postback

Имеют собственные формулы подписи:

| Postback | Формула |
| --- | --- |
| Refund | `strtoupper(md5(Amount:Currency:BillId:PaymentId:Id:apiToken))` |
| Chargeback | `strtoupper(md5(BillId:PaymentId:Id:apiToken))` |
| Payout | `strtoupper(md5(Amount:TrsId:apiToken))` |
| P2P Deal | `strtoupper(md5(Amount:TrsId:apiToken))` |

Модуль эти postback сейчас не обрабатывает.

## Сводка полей, которые использует модуль

| Источник | Поля, которые читает/пишет модуль |
| --- | --- |
| `POST bill/create` (отправляет) | `shop_id`, `order_id`, `amount`, `type`, `custom`, `description`, `payer_pays_commission`, `success_url`, `fail_url`, `shop_url` *(не в доке)*, `currency_in` *(если не пустое)*, `lifetime` *(должно быть `ttl`!)* |
| `bill/create` response | `success`, `bill_id`, `link_page_url`, `link_url` |
| `GET bill/status` | запрос `id=<bill_id>`, читает `status`/`Status` |
| `GET payment/status` | запрос `id=<TrsId>`, читает `status`/`Status` |
| Postback (читает) | `InvId`, `OutSum`, `SignatureValue`, `custom`, `TrsId`, `Status`, `AccountType`, `Commission` |
| Postback (НЕ читает) | `CurrencyIn` (обязательное!), `AccountNumber`, `BalanceAmount`, `BalanceCurrency`, `Payer*`, `ErrorCode`, `ErrorMessage` |
