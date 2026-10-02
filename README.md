# CCAT Telegram Bot

Бот бронирований CCA Tashkent переехал внутрь основного проекта **itdepacdf/ccat-2026** и переписан на [Nutgram](https://nutgram.dev). Этот репозиторий обновлён до той же версии (срез на 02.10.2026): код бота перенесён из ccat-2026 по тем же путям без изменений, в `composer.json` добавлен `nutgram/laravel`. Исключение — `lang/*/app.php`: из большого файла переводов сайта оставлены только ключи бота.

Сам бот работает только внутри ccat-2026: он напрямую ходит в модели броней, слотов и программ, общую базу, письма и события сайта. Здесь `composer install`, `artisan` и `PhoneNumberTest` работают, а `nutgram:run`, миграция `add_phone_numbers_for_telegram_lookup` и тесты `TelegramBotTest` / `TelegramAdminResourcesTest` требуют сайта.

## Что поменялось

| Было | Стало |
|---|---|
| Отдельное Laravel-приложение, на сайт ходит по HTTP API `CCAT_API_URL` (`/api/bot`) с токеном | Часть сайта: хендлеры вызывают модели и сервисы напрямую. API удалён — миграция `drop_bot_api_tables` сносит `telegram_booking_links`, `personal_access_tokens` и пользователя `bot-service@ccat.uz` |
| Свой `TelegramService` на HTTP-клиенте и `TelegramBotUpdateHandler` на 969 строк с ручным разбором текста и `callback_data` | Пакет `nutgram/laravel`: маршруты бота в `routes/telegram.php`, по классу на сценарий в `app/Telegram/Handlers` |
| Бронь привязывалась по токену из диплинка `t.me/<bot>?start=<token>` | Брони находятся по номеру телефона: пользователь делится своим контактом, номер нормализуется и сверяется с колонкой `phone_normalized` во всех трёх таблицах броней |
| Вебхук без проверки отправителя, апдейт уходил в очередь (`ProcessTelegramUpdate`) | Вебхук сверяет заголовок `X-Telegram-Bot-Api-Secret-Token` (HMAC от `APP_KEY`), чужие запросы получают 403; апдейт обрабатывается сразу |
| Свой цикл `telegram:poll` | Встроенный `php artisan nutgram:run` |
| `telegram:webhook {info\|set\|delete}`, `booking:check {token}` | `telegram:webhook` ставит вебхук с секретом и регистрирует меню команд на трёх языках, вызывается при деплое |
| Запись на программу можно было перенести на другое мероприятие | Переносятся только визит и библиотека, запись на программу можно отменить |
| Тексты в `lang/*/bot.php` и `pass.php`, часть английских — прямо в коде | Все тексты — секция `bot` в `lang/*/app.php` сайта |

Из старой версии убраны `TelegramService`, `TelegramBotUpdateHandler`, `CcatBookingService`, `TelegramPollCommand`, `TestBookingCommand`, `ProcessTelegramUpdate`, `config/telegram.php`, `lang/*/bot.php`, `lang/*/pass.php`, `CCAT_API_URL` из `.env.example` и JSON-статус на `/`.

## Как работает

1. После брони на сайте в окне подтверждения есть кнопка «Получить QR-код в Telegram» — она открывает `t.me/<bot>`.
2. `/start` — приветствие сразу на всех языках. Пока номер неизвестен, бот просит поделиться контактом кнопкой.
3. Принимается только собственный контакт пользователя. Номер приводится к виду с кодом страны (`PhoneNumber::normalize`) и сохраняется в `telegram_users.phone`; если этот номер был привязан к другому чату, там он снимается.
4. «📋 Мои брони» — до 10 предстоящих броней (визит, библиотека, программа) с этим номером. Карточка на всех языках, начиная с языка пользователя. Кнопки появляются, только если действие разрешено:
   - **QR-код** — для подтверждённых броней. PNG делает `QrCodeService` сайта, тот же, что для QR в письмах.
   - **Перенести** — визит и библиотека. Дни со свободными местами на 30 дней вперёд (показывается до 12), свободное время с учётом дневного лимита и пересечений со своими бронями, не больше 10 переносов в час. После переноса уходит письмо с новыми датой и временем (событие `BookingRequested`).
   - **Отменить** — через `VisitorCancellation`, тот же сервис, что у ссылки отмены из письма. Проверенную на входе или прошедшую бронь отменить нельзя.
5. «🗂 Прошедшие» — последние 10 прошедших и отменённых броней.
6. «🌐 Язык» — uz / ru / en, хранится в `telegram_users.language_code`.
7. Бот отвечает только в личных чатах. Ошибки уходят в `report()`, пользователь получает «что-то пошло не так».

В админке (Filament) есть раздел «Пользователи Telegram»: chat id, username, телефон, язык, число броней по номеру, последняя активность.

## Файлы

| Путь | Что это |
|---|---|
| `routes/telegram.php` | Команды, кнопки меню, `callback_data` → хендлеры, обработка ошибок |
| `bootstrap/app.php`, `routes/web.php` | Подключён `routes/api.php`, на `/` — стандартная welcome-страница |
| `app/Telegram/Handlers/*` | Сценарии: старт и меню, контакт, брони и история, QR, отмена, перенос, язык |
| `app/Telegram/Middleware/IdentifyVisitor.php` | На каждый апдейт находит или создаёт `TelegramUser` и ставит язык |
| `app/Telegram/Support/*` | Клавиатуры, карточка брони, поиск броней по телефону |
| `app/Telegram/TelegramWebhook.php` | URL и секрет вебхука |
| `app/Http/Controllers/TelegramWebhookController.php`, `routes/api.php` | `POST /api/telegram/webhook` |
| `app/Console/Commands/TelegramWebhookCommand.php` | `php artisan telegram:webhook` |
| `config/nutgram.php` | Настройки Nutgram |
| `app/Models/TelegramUser.php`, `database/migrations/*` | Таблица `telegram_users`, `phone_normalized` в бронях, удаление таблиц старого API |
| `app/Filament/Resources/TelegramUsers/*`, `app/Policies/TelegramUserPolicy.php` | Раздел в админке и права Shield |
| `app/Services/VisitorCancellation.php`, `app/Services/VisitorReschedule.php` | Отмена и перенос — то, что раньше делали эндпоинты `/api/bot` |
| `app/Support/PhoneNumber.php` | Нормализация номера |
| `lang/{uz,ru,en}/app.php` | Тексты бота (выборка из переводов сайта) |
| `tests/Feature/TelegramBotTest.php`, `tests/Feature/TelegramAdminResourcesTest.php`, `tests/Unit/PhoneNumberTest.php` | Тесты, бот гоняется через `FakeNutgram` |

## Что бот берёт из сайта (здесь этих файлов нет)

- модели `VisitBooking`, `LibraryBooking`, `ProgrammeBooking`, `Programme`, `Location`, слоты `VisitSlot` и `LibrarySlot`
- `app/Models/Concerns/IsBooking.php` — заполняет `phone_normalized` при сохранении брони, scope `withPhone()`
- `app/Enums/BookingStatus.php`, `app/Enums/BookingType.php` — статусы и типы `visit` / `library` / `programme`
- `app/Support/SlotAvailability.php` и наследники `VisitAvailability`, `LibraryAvailability` — свободные места, лимиты, пересечения
- `app/Services/QrCodeService.php` — QR-код брони
- `app/Support/MailLanguages.php` — порядок языков uz → ru → en, начиная с языка пользователя
- `app/Events/BookingRequested.php` → `app/Listeners/SendBookingReceivedEmail.php` — письмо после переноса
- хелпер `available_locales()` из `app/Helpers/functions.php`
- кнопка Telegram в `resources/views/livewire/booking/review.blade.php` (берёт `services.telegram.bot_username`)

## Пакеты

В `composer.json` добавлен один пакет — тот же, что в ccat-2026:

```bash
composer require nutgram/laravel:^1.7
```

В `composer.lock` зафиксированы те же версии, что на сайте: `nutgram/laravel` 1.7.1, `nutgram/nutgram` 4.50.1, `nutgram/hydrator` 7.2.0, `sergix44/container` 3.1.0. Остальные пакеты не трогались. QR-коды на сайте делает `chillerlan/php-qrcode` 5.x, он приходит вместе с Filament.

## Настройка и запуск (в ccat-2026)

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=ccat_booking_bot
```

- **Вебхук**: `php artisan telegram:webhook` ставит `<APP_URL>/api/telegram/webhook` с секретом и регистрирует команды. Ставить только этой командой — без секрета контроллер отвечает 403.
- **Polling**: `php artisan nutgram:hook:remove`, затем `php artisan nutgram:run` постоянным процессом. Пока стоит вебхук, Telegram не отдаёт апдейты через polling.

Тесты:

```bash
php artisan test --filter='TelegramBotTest|TelegramAdminResourcesTest|PhoneNumberTest'
```
