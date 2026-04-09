# Pally Payment Module for Magento 2

Модуль добавляет платёжный метод **Pally** в Magento 2 и интегрируется с API создания счёта (`/api/v1/bill/create`), обработкой webhook-уведомлений и fallback-проверкой статуса через cron.

## Что реализовано

- Создание платёжного счёта при оформлении заказа.
- Редирект покупателя на страницу оплаты Pally.
- Обработка callback/webhook с проверкой подписи `md5(OutSum:InvId:ApiToken)`.
- Идемпотентное обновление статуса заказа и автоматическое создание invoice при успешной оплате.
- Периодический cron-опрос `payment/status` и `bill/status` для заказов со статусом `pending_payment`.

## Настройки в Magento Admin

`Stores -> Configuration -> Sales -> Payment Methods -> Pally Payment`

Обязательные параметры:

- **Enabled**
- **API Token**
- **Shop ID**
- **API URL**

Рекомендуется включать **Debug Mode** только на тестовых/диагностических окружениях.

> **Поддерживаемые валюты:** Pally принимает только `RUB`, `USD`, `EUR`.
> Для магазинов с любой другой валютой Pally будет автоматически скрыт
> в чекауте (`canUseForCurrency`), а попытка создать счёт через REST/Admin
> отклонится с ошибкой.

## Настройка в кабинете Pally

В личном кабинете Pally (`https://pally.info`) → **API интеграции** → ваш магазин,
заполните три URL, заменив `<host>` на домен вашего Magento-магазина:

| Поле в кабинете Pally | URL |
| --- | --- |
| **Result URL** (postback) | `https://<host>/pally/webhook/index` |
| **Success URL** | `https://<host>/pally/callback/success` |
| **Fail URL** | `https://<host>/pally/callback/fail` |

Без правильно настроенных URL:
- статус заказа не будет обновляться (Result URL)
- покупатель не вернётся в магазин после оплаты (Success/Fail URL)

`Result URL` Pally вызывает методом `POST` с подписью `md5(OutSum:InvId:apiToken)`.
`Success/Fail URL` тоже вызываются `POST`-ом — модуль это поддерживает,
включая обход CSRF (см. `Controller\Callback\Success::validateForCsrf`).

## Важно по безопасности

- API token хранится в зашифрованном виде в конфиге Magento.
- Для запросов и webhook token автоматически расшифровывается на чтении.
- Сравнение подписи webhook выполняется через `hash_equals`.

## CI / GitHub Actions

Workflow `.github/workflows/ci.yml` проверяет:

- composer validate;
- PHP lint (8.3 / 8.4);
- PHPCS (Magento2 standard);
- PHPStan;
- PHPMD;
- XML validation;
- ESLint для frontend JS;
- базовые security-checks;
- PHP compatibility;
- проверку полноты i18n файлов.
