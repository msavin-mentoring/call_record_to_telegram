# call_record_to_telegram

PHP-воркер в Docker, который:
- ищет новые (`.mp4`) записи в папке Jibri;
- обрабатывает только "стабильные" файлы (старше порога и с неизменным размером);
- вырезает 10 секунд из середины видео через `ffmpeg`;
- отправляет клип в Telegram и ждет разметку от вас;
- принимает теги кнопками-подсказками (`мок/резюме/задачи/ревью/легенда/скрин/кроссмок/велком`) или вручную текстом;
- просит ники участников (`@username`), но теперь по умолчанию подтягивает актуальный список учеников из `platform`;
- если настроен доступ к БД `platform`, пытается предзаполнить участников по ближайшему `mentor_session` и вам остается подтвердить или поправить;
- сохраняет разметку и отправляет полный файл с подписью:
  - `теги: #мок, #резюме`
  - `участники: @msavin_dev, @asdfasdf`
  - `дата: 2025-12-03`
- после участников сразу завершает обычный флоу без лишнего вопроса про транскрибацию;
- по reply-команде `/summary` на сообщение бота с конкретным видео делает транскрипт и саммари через AI Tunnel;
- вместе с `/summary` отправляет полный транскрипт отдельным `.txt` файлом;
- поддерживает ручную команду переобработки файла: `/reprocess <имя_файла.mp4>`;
- если на этапе ввода нет ответа, отправляет напоминания с backoff;
- ночью напоминания не отправляются (по умолчанию `Europe/Moscow`, окно 23:00-09:00);
- помечает запись как обработанную в `state.json`, чтобы не отправлять повторно.

Код организован через Composer autoload (PSR-4), классы лежат в `src/App/`.
`vlucas/phpdotenv` используется для загрузки `.env` (если файл есть) и базовой валидации обязательных/типовых переменных.

## Быстрый старт

1. Создайте `.env` из шаблона:

```bash
cp .env.example .env
```

2. Заполните в `.env` минимум:
- `TELEGRAM_BOT_TOKEN`
- при необходимости `TELEGRAM_CHAT_ID` (если пусто, воркер попробует взять chat id из `getUpdates`)
- `TELEGRAM_API_BASE_URL` (по умолчанию `https://api.telegram.org`; endpoint для `getUpdates`, callback и текстовых сообщений)
- `TELEGRAM_UPLOAD_API_BASE_URL` (опционально, отдельный endpoint для `sendVideo/sendDocument`; если пусто, используется `TELEGRAM_API_BASE_URL`)
- `TELEGRAM_PARTICIPANT_PRESETS` (опционально, список ников для кнопок выбора участников)
- `TELEGRAM_UPLOAD_MAX_BYTES` (опционально, лимит одного отправляемого файла; по умолчанию ~49 MB)
- `AITUNNEL_API_KEY` (предпочтительно; можно оставить `OPENAI_API_KEY` как fallback для совместимого прокси-ключа)
- `PLATFORM_API_BASE_URL`, `PLATFORM_IDENTITY_EXCHANGE_SECRET` и `PLATFORM_API_TELEGRAM_USERNAME` для списка учеников из `platform`

3. Запустите:

```bash
docker compose up -d --build
```

После первого билда код из `./src` монтируется в контейнер, поэтому для применения изменений в `worker.php` достаточно:

```bash
docker compose up -d --force-recreate worker
```

4. Посмотреть логи:

```bash
docker compose logs -f worker
```

Однократный прогон (без демона):

```bash
RUN_ONCE=true docker compose up --build --abort-on-container-exit worker
```

## Параметры

- `RECORDINGS_HOST_PATH` — путь на хосте к записям Jibri (монтируется в контейнер как `/recordings`)
- `RECORDINGS_DIR` — путь внутри контейнера к записям (по умолчанию `/recordings`)
- `STATE_FILE` — файл состояния обработанных записей
- `TEMP_DIR` — временная папка под клипы
- `APP_TIMEZONE` — основная timezone приложения и дат записей (по умолчанию `Europe/Moscow`)
- `TELEGRAM_API_BASE_URL` — base URL для `getUpdates`/callback/text (рекомендуется `https://api.telegram.org`)
- `TELEGRAM_UPLOAD_API_BASE_URL` — отдельный base URL для `sendVideo`/`sendDocument` (например, локальный `http://bot-api:8081`)
- `TELEGRAM_PARTICIPANT_PRESETS` — fallback-пресеты участников, если `platform` API недоступен
- `TELEGRAM_UPLOAD_MAX_BYTES` — целевой лимит одного отправляемого файла; если полный файл больше, воркер автоматически шлет запись частями
- `POLL_INTERVAL_SECONDS` — интервал сканирования
- `FILE_MIN_AGE_SECONDS` — минимальный возраст файла перед обработкой
- `STABILITY_WAIT_SECONDS` — сколько ждать перед повторной проверкой размера
- `CLIP_DURATION_SECONDS` — длительность клипа (по умолчанию 10)
- `RUN_ONCE` — `true` для одного прохода и выхода (удобно для cron/ручного теста)
- `UPDATES_TIMEOUT_SECONDS` — timeout long polling для `getUpdates` (обычно 1-5 секунд)
- `AITUNNEL_API_KEY` — API ключ AI Tunnel
- `AITUNNEL_BASE_URL` — base URL API (по умолчанию `https://api.aitunnel.ru/v1/`)
- `AITUNNEL_TRANSCRIBE_MODEL` — модель транскрибации (по умолчанию `whisper-1`)
- `AITUNNEL_SUMMARY_MODEL` — модель саммари (по умолчанию `gpt-4o-mini`)
- `AITUNNEL_TRANSCRIBE_LANGUAGE` — язык для ASR (например `ru`)
- `OPENAI_API_KEY`, `OPENAI_BASE_URL`, `OPENAI_TRANSCRIBE_MODEL`, `OPENAI_SUMMARY_MODEL`, `OPENAI_TRANSCRIBE_LANGUAGE` — алиасы для обратной совместимости
- `OPENAI_AUDIO_CHUNK_SECONDS` — длина аудио-чанка для транскрибации (секунды)
- `OPENAI_SUMMARY_CHUNK_CHARS` — размер текстового чанка для map-reduce саммари
- `SEND_TRANSCRIPT_FILE` — отправлять ли `.txt` файл с полным транскриптом в авто-флоу совместимости
- `PLATFORM_API_BASE_URL` — base URL `platform` без суффикса `/api` (по умолчанию `http://host.docker.internal:8083`)
- `PLATFORM_IDENTITY_EXCHANGE_SECRET` — shared secret для `POST /api/auth/session/exchange`
- `PLATFORM_IDENTITY_EXCHANGE_ISSUER` — issuer exchange token (по умолчанию `identity-service`)
- `PLATFORM_IDENTITY_EXCHANGE_AUDIENCE` — audience exchange token (по умолчанию `platform`)
- `PLATFORM_API_ACTOR_SUBJECT_ID` — технический `sub` для admin-session exchange
- `PLATFORM_API_TELEGRAM_USERNAME` — технический telegram username для exchange
- `PLATFORM_API_DISPLAY_NAME` — displayName для exchange session
- `PLATFORM_STUDENTS_CACHE_SECONDS` — TTL кеша списка учеников из `platform`
- `PLATFORM_DB_HOST`, `PLATFORM_DB_PORT`, `PLATFORM_DB_NAME`, `PLATFORM_DB_USER`, `PLATFORM_DB_PASSWORD` — optional доступ к БД `platform` для автоподсказки по `mentor_session`
- `PLATFORM_SESSION_MATCH_WINDOW_MINUTES` — окно поиска ближайшей mentor-сессии
- `REMINDER_BASE_SECONDS` — базовый интервал первого напоминания
- `REMINDER_MAX_SECONDS` — максимальный интервал бэкоффа
- `REMINDER_TIMEZONE` — timezone для quiet hours (`Europe/Moscow`)
- `REMINDER_NIGHT_START_HOUR` — час начала «ночи» (0-23)
- `REMINDER_NIGHT_END_HOUR` — час окончания «ночи» (0-23)

## Примечания

- Бот должен иметь доступ к нужному чату.
- Для автоопределения `TELEGRAM_CHAT_ID` отправьте любое сообщение боту перед запуском воркера.
- Записи обрабатываются строго по одной: следующий файл пойдет только после ввода тегов и участников по текущему.
- Состояние хранится в `./data/state.json` (`processed`, `pending`, `last_update_id`, `chat_id`).
- Для длинных видео транскрибация делается чанками (аудио сегменты), затем собирается общий саммари.
- Команда `/summary` работает для новых сообщений с видео, которые бот отправил уже после этого обновления, потому что теперь в `state.json` сохраняются `message_id` отправленных записей.
- Если `platform` API доступен, кнопки участников больше не зависят от захардкоженного списка в `.env`: активные ученики подтягиваются автоматически.
- Если дополнительно настроен доступ к БД `platform`, шаг участников получает автоподсказку по ближайшему `mentor_session` относительно времени записи.

## Команда переобработки

Если нужно заново прогнать уже обработанную запись, отправьте боту:

```text
/reprocess cautiouswisdomsreachhigh_2026-02-21-09-59-21.mp4
```

Также поддерживаются варианты `reprocess ...` и `переобработать ...`.
В команде можно указывать либо имя файла, либо относительный путь внутри `RECORDINGS_DIR`.

## Ручной `/summary`

Чтобы получить транскрипт и саммари только для нужной записи, ответьте на сообщение бота с полным видео:

```text
/summary
```

Бот:
- прогонит транскрибацию через AI Tunnel;
- пришлет текстовое саммари;
- пришлет полный транскрипт отдельным `.txt`.

Также поддерживается alias `/transcript`.

## Ручная очистка processed записей

Есть утилита `scripts/cleanup_processed_recordings.php`, которая удаляет только те `.mp4`, что есть в `state.json` в `processed` и старше заданного порога по `processed_at`.

Сначала dry-run (ничего не удаляет):

```bash
php scripts/cleanup_processed_recordings.php --days=14 --recordings=/root/.jitsi-meet-cfg/jibri/recordings
```

Реальное удаление:

```bash
php scripts/cleanup_processed_recordings.php --days=14 --recordings=/root/.jitsi-meet-cfg/jibri/recordings --apply --prune-empty-dirs
```

## Локальный Bot API (опционально)

Если нужно отправлять большие файлы без агрессивной нарезки, можно поднять локальный `telegram-bot-api` только для upload:

1. Заполните в `.env`:
   - `TELEGRAM_API_ID`
   - `TELEGRAM_API_HASH`
   - `TELEGRAM_API_BASE_URL=https://api.telegram.org`
   - `TELEGRAM_UPLOAD_API_BASE_URL=http://bot-api:8081`
2. Запустите профиль:

```bash
docker compose --profile local-bot-api up -d --build
```

Если хотите вернуться на облачный endpoint Telegram:
- `TELEGRAM_API_BASE_URL=https://api.telegram.org`
- `TELEGRAM_UPLOAD_API_BASE_URL=` (пусто)
- запуск без профиля `local-bot-api`.

## Deploy

Продовый деплой сделан по тому же принципу, что и в `../mock`:
- GitHub Actions workflow `.github/workflows/deploy.yml`
- запуск по git tag или вручную через `workflow_dispatch`
- на сервере выполняется `make up-prod`

Нужные GitHub secrets:
- `DEPLOY_HOST`
- `DEPLOY_PORT`
- `DEPLOY_USER`
- `DEPLOY_SSH_KEY`
- `DEPLOY_PATH`
- `DEPLOY_HOST_FINGERPRINT`

Локально prod-подъем:

```bash
make up-prod
```

`docker-compose.prod.yml` убирает dev-монтирование `./src`, чтобы контейнер в проде запускался из собранного образа.
