# call_record_to_telegram

PHP-воркер в Docker, который:
- ищет новые (`.mp4`) записи в папке Jibri;
- обрабатывает только "стабильные" файлы (старше порога и с неизменным размером);
- вырезает 10 секунд из середины видео через `ffmpeg`;
- отправляет клип в Telegram и ждет разметку от вас;
- принимает теги кнопками-подсказками (`мок/резюме/задачи/ревью`) или вручную текстом;
- просит ники участников (`@username`);
- сохраняет разметку и отправляет полный файл с подписью:
  - `теги: #мок, #резюме`
  - `участники: @msavin_dev, @asdfasdf`
  - `дата: 2025-12-03`
- после участников спрашивает, нужно ли саммари (`Да/Нет`);
- только при ответе `Да` (и включенном OpenAI) делает транскрипт и отправляет саммари;
- опционально отправляет полный транскрипт отдельным `.txt` файлом;
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
- `OPENAI_API_KEY` (если хотите транскрипцию и саммари через OpenAI)

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
- `TELEGRAM_API_BASE_URL` — base URL для `getUpdates`/callback/text (рекомендуется `https://api.telegram.org`)
- `TELEGRAM_UPLOAD_API_BASE_URL` — отдельный base URL для `sendVideo`/`sendDocument` (например, локальный `http://bot-api:8081`)
- `TELEGRAM_PARTICIPANT_PRESETS` — пресеты участников для inline-кнопок (например: `@msavin_dev,@asdfasdf`)
- `TELEGRAM_UPLOAD_MAX_BYTES` — целевой лимит одного отправляемого файла; если полный файл больше, воркер автоматически шлет запись частями
- `POLL_INTERVAL_SECONDS` — интервал сканирования
- `FILE_MIN_AGE_SECONDS` — минимальный возраст файла перед обработкой
- `STABILITY_WAIT_SECONDS` — сколько ждать перед повторной проверкой размера
- `CLIP_DURATION_SECONDS` — длительность клипа (по умолчанию 10)
- `RUN_ONCE` — `true` для одного прохода и выхода (удобно для cron/ручного теста)
- `UPDATES_TIMEOUT_SECONDS` — timeout long polling для `getUpdates` (обычно 1-5 секунд)
- `OPENAI_API_KEY` — API ключ OpenAI (если пусто, шаг транскрибации/саммари пропускается)
- `OPENAI_TRANSCRIBE_MODEL` — модель транскрибации (по умолчанию `gpt-4o-mini-transcribe`)
- `OPENAI_SUMMARY_MODEL` — модель саммари (по умолчанию `gpt-4o-mini`)
- `OPENAI_TRANSCRIBE_LANGUAGE` — язык для ASR (например `ru`)
- `OPENAI_AUDIO_CHUNK_SECONDS` — длина аудио-чанка для транскрибации (секунды)
- `OPENAI_SUMMARY_CHUNK_CHARS` — размер текстового чанка для map-reduce саммари
- `SEND_TRANSCRIPT_FILE` — отправлять ли `.txt` файл с полным транскриптом
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
