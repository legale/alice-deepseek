# План рефакторинга бота для интеграции API OpenRouter и Яндекс Алиса

## overview

Система представляет собой PHP-бот для интеграции Яндекс Алисы с OpenRouter API. Бот обрабатывает голосовые запросы пользователей, отправляет их в LLM через OpenRouter, поддерживает контекст разговора, обрабатывает tool calls (поиск в интернете), управляет переключением моделей и обрабатывает таймауты через механизм pending states.

Вход: HTTP POST запросы от Яндекс Алисы в формате JSON.
Выход: JSON ответы в формате Яндекс Алисы.

## found issues

Основная проблема - монолитный класс AliceHandler на 1408 строк с 62 методами, смешивающий множество ответственностей. Поток выполнения сложно отследить из-за глубокой вложенности и разбросанной логики.

Логика обработки tool calls и получения финального ответа дублируется в двух местах: в processAliceRequest (строки 191-236) и continueBackgroundFetch (строки 528-552). Оба места содержат одинаковый цикл с проверкой tool_calls, обработкой функций и проверкой финального ответа.

Методы работы с хранилищем (loadConversation, saveConversation, loadPendingState, savePendingState) перемешаны с бизнес-логикой обработки запросов. Это усложняет понимание потока выполнения.

Обработка ошибок разбросана по нескольким методам (formatRequestError, formatConnectError, formatGenericError), но логика их вызова встроена в основной поток, что увеличивает цикломатическую сложность.

Конфигурация клиента OpenRouter и инициализация зависимостей занимают большую часть конструктора (строки 46-122), что затрудняет понимание структуры класса.

## before

Текущая структура:
```
index.php (1408 строк)
  - класс AliceHandler
    - конструктор: загрузка конфигурации, инициализация клиента, создание директорий
    - handleRequest: проверка метода, вызов processAliceRequest
    - processAliceRequest: парсинг входных данных, обработка pending state, обработка команд, основной цикл обработки AI
    - continueBackgroundFetch: фоновый цикл обработки при превышении таймаута
    - методы работы с моделями: loadModelList, syncModelState, switchToNextModel
    - методы работы с хранилищем: loadConversation, saveConversation, loadPendingState, savePendingState
    - методы работы с файлами: getSessionFilePath, readCompressedJson, writeCompressedJson
    - методы работы с сообщениями: buildMessages, createUserMessage, normalizeContentParts
    - методы работы с API: requestAiResponse, extractResponsePayload
    - методы обработки tool calls: processFunctionCalls, performGoogleSearch
    - методы форматирования ошибок: formatRequestError, formatConnectError
    - вспомогательные методы: cleanInput, truncateResponse, sanitizeSessionId
```

Поток выполнения:
1. HTTP POST запрос приходит в handleRequest
2. processAliceRequest парсит входные данные, извлекает session_id
3. Проверяется pending state, если есть - обрабатывается и возвращается ответ
4. Загружается история разговора
5. Если есть utterance - очищается, добавляется в историю
6. Проверяются команды помощи и переключения модели
7. Запускается цикл обработки AI (до 3 итераций):
   - Проверка таймаута, при превышении - создание pending state и фоновая обработка
   - Запрос к OpenRouter API
   - Сохранение ответа в историю
   - Если есть tool_calls - обработка функций, сохранение результатов, продолжение цикла
   - Если нет tool_calls - финальный ответ
8. Формирование и отправка ответа Алисе

## after

Целевая структура:
```
index.php (точка входа, ~50 строк)
  - создание зависимостей
  - вызов обработчика запроса

alice_handler.php (основной обработчик, ~200 строк)
  - handleRequest: точка входа
  - processAliceRequest: оркестрация обработки запроса
  - методы обработки команд: processHelpCommand, processModelSwitchCommand

ai_processor.php (обработка AI запросов, ~150 строк)
  - processAiRequest: единый метод обработки AI запроса с циклом tool calls
  - requestAiResponse: запрос к OpenRouter API
  - extractResponsePayload: извлечение ответа из API

storage.php (работа с хранилищем, ~200 строк)
  - методы работы с conversations: loadConversation, saveConversation
  - методы работы с pending states: loadPendingState, savePendingState
  - методы работы с файлами: getSessionFilePath, readCompressedJson, writeCompressedJson

message_builder.php (работа с сообщениями, ~100 строк)
  - buildMessages: построение массива сообщений для API
  - createUserMessage, createAssistantMessageFromText
  - normalizeContentParts, buildDisplayTextFromParts

tool_handler.php (обработка tool calls, ~100 строк)
  - processFunctionCalls: обработка вызовов функций
  - performGoogleSearch: выполнение поиска
  - buildToolsDefinition: определение доступных функций

model_manager.php (управление моделями, ~100 строк)
  - loadModelList, syncModelState, switchToNextModel
  - loadModelState, persistModelState

error_formatter.php (форматирование ошибок, ~80 строк)
  - formatRequestError, formatConnectError, formatGenericError

config.php (конфигурация, ~50 строк)
  - создание клиента OpenRouter
  - загрузка переменных окружения
```

Поток выполнения после рефакторинга:
1. HTTP POST запрос приходит в handleRequest (alice_handler.php)
2. processAliceRequest парсит входные данные, создает response template
3. Проверяется pending state через storage, если есть - обрабатывается
4. Загружается история через storage
5. Если есть utterance - обрабатываются команды или передается в ai_processor
6. ai_processor.processAiRequest выполняет единый цикл обработки:
   - запрос к API через requestAiResponse
   - обработка tool calls через tool_handler
   - повтор до получения финального ответа или лимита итераций
7. Формирование и отправка ответа

Преимущества:
- Поток выполнения становится линейным и понятным
- Дублирование логики обработки tool calls устранено
- Методы сгруппированы по ответственности
- Каждый файл читается как отдельный сценарий
- Легче тестировать отдельные компоненты

## tasks

- [ ] file=index.php scope=module change=вынести создание зависимостей и вызов обработчика в отдельные функции, оставить только точку входа reason=упростить точку входа, сделать зависимости явными risk=low test=проверить что запросы обрабатываются корректно

- [ ] file=index.php scope=func=AliceHandler::processAliceRequest block=строки 191-236 change=вынести цикл обработки AI запроса с tool calls в отдельный метод processAiRequestLoop reason=устранить дублирование, упростить читаемость processAliceRequest risk=med test=проверить обработку tool calls и финальных ответов

- [ ] file=index.php scope=func=AliceHandler::continueBackgroundFetch block=строки 528-552 change=использовать тот же метод processAiRequestLoop вместо дублированного кода reason=устранить дублирование логики обработки tool calls risk=med test=проверить фоновую обработку при таймаутах

- [ ] file=storage.php scope=module change=создать новый файл, вынести методы работы с хранилищем: loadConversation, saveConversation, loadPendingState, savePendingState, getSessionFilePath, readCompressedJson, writeCompressedJson, deleteSessionFiles, buildTimestampedFilePath, enforceStorageLimits, sanitizeSessionId, isLegacyJsonPath, getFileTimestamp reason=сгруппировать методы работы с хранилищем, упростить основной класс risk=med test=проверить сохранение и загрузку conversations и pending states

- [ ] file=index.php scope=module change=заменить прямые вызовы методов хранилища на использование storage.php reason=использовать выделенный модуль хранилища risk=med test=проверить что все операции с хранилищем работают

- [ ] file=message_builder.php scope=module change=создать новый файл, вынести методы работы с сообщениями: buildMessages, createUserMessage, createAssistantMessageFromText, createAssistantPayloadFromText, normalizeContentParts, buildDisplayTextFromParts reason=сгруппировать методы работы с форматом сообщений risk=low test=проверить построение сообщений для API и извлечение текста

- [ ] file=index.php scope=module change=заменить прямые вызовы методов сообщений на использование message_builder.php reason=использовать выделенный модуль работы с сообщениями risk=low test=проверить корректность форматирования сообщений

- [ ] file=tool_handler.php scope=module change=создать новый файл, вынести методы: processFunctionCalls, performGoogleSearch, buildToolsDefinition reason=сгруппировать логику обработки tool calls risk=low test=проверить обработку search_internet и других tool calls

- [ ] file=index.php scope=module change=заменить прямые вызовы методов tool calls на использование tool_handler.php reason=использовать выделенный модуль обработки tool calls risk=low test=проверить выполнение поиска и обработку результатов

- [ ] file=model_manager.php scope=module change=создать новый файл, вынести методы управления моделями: loadModelList, syncModelState, switchToNextModel, loadModelState, persistModelState, displayModelName reason=сгруппировать логику управления моделями risk=low test=проверить переключение моделей и сохранение состояния

- [ ] file=index.php scope=module change=заменить прямые вызовы методов моделей на использование model_manager.php reason=использовать выделенный модуль управления моделями risk=low test=проверить работу с моделями

- [ ] file=error_formatter.php scope=module change=создать новый файл, вынести методы форматирования ошибок: formatRequestError, formatConnectError, formatGenericError, extractErrorText, formatCode reason=сгруппировать методы форматирования ошибок risk=low test=проверить форматирование различных типов ошибок

- [ ] file=index.php scope=module change=заменить прямые вызовы методов форматирования ошибок на использование error_formatter.php reason=использовать выделенный модуль форматирования ошибок risk=low test=проверить обработку ошибок

- [ ] file=ai_processor.php scope=module change=создать новый файл, вынести методы работы с AI: requestAiResponse, extractResponsePayload, logAiRequest, processAiRequestLoop (новый метод из processAliceRequest) reason=сгруппировать логику работы с OpenRouter API risk=med test=проверить запросы к API и обработку ответов

- [ ] file=index.php scope=module change=заменить прямые вызовы методов AI на использование ai_processor.php reason=использовать выделенный модуль работы с AI risk=med test=проверить интеграцию с OpenRouter

- [ ] file=config.php scope=module change=создать новый файл, вынести создание клиента OpenRouter и загрузку конфигурации из конструктора reason=упростить конструктор, сделать конфигурацию явной risk=low test=проверить создание клиента и загрузку переменных окружения

- [ ] file=alice_handler.php scope=module change=переименовать index.php в alice_handler.php, оставить только методы handleRequest, processAliceRequest, processHelpCommand, processModelSwitchCommand, isHelpCommand, containsModelSwitchCommand, buildGreetingMessage, normalizeCommand, cleanInput, truncateResponse, sendResponse, releaseSession, handlePendingState, createPendingState, continueBackgroundFetch, saveExpiredState, isTimeoutException, isTimeoutErrno, getCurlErrno reason=оставить только оркестрацию обработки запросов Алисы risk=high test=проверить полный цикл обработки запроса от Алисы

- [ ] file=index.php scope=module change=создать новый index.php как точку входа: создание зависимостей, вызов alice_handler reason=упростить точку входа risk=low test=проверить что приложение запускается и обрабатывает запросы

