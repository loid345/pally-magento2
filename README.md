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
