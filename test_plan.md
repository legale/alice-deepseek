# План создания юнит-тестов

## overview

Система состоит из модулей с чистыми функциями, которые обрабатывают данные без побочных эффектов или с изолируемыми зависимостями. Модули: error_formatter, message_builder, model_manager, storage (частично), tool_handler (частично), ai_processor (частично), config (частично).

Входы: строки, массивы, объекты исключений, пути к файлам.
Выходы: строки, массивы, объекты Client.

Цель тестирования: гарантировать корректность работы функций при различных входных данных, включая граничные случаи и ошибки.

## found issues

Отсутствуют тесты для всех модулей. Нет проверки корректности обработки граничных случаев, ошибок и редких данных. Нет гарантии, что рефакторинг не сломал функциональность.

Функции работают с файловой системой (storage, model_manager) и внешними API (tool_handler, ai_processor, config), что требует моков для изоляции тестов.

Некоторые функции имеют сложную логику ветвления (normalize_content_parts, extract_response_payload, process_ai_request_loop), которую нужно проверить на всех путях выполнения.

Функции форматирования ошибок работают с исключениями разных типов, нужно проверить все варианты.

## before

Текущее состояние:
- нет тестов
- нет тестового фреймворка
- нет структуры для тестов
- нет моков для внешних зависимостей

Проверка работоспособности: только ручное тестирование через HTTP запросы к Алисе.

## after

Целевая структура:
```
tests/
  unit/
    error_formatter_test.php
    message_builder_test.php
    model_manager_test.php
    storage_test.php (только чистые функции без файловой системы)
    tool_handler_test.php (только build_tools_definition)
    config_test.php (только валидация, без создания клиента)
  fixtures/
    sample_history.json
    sample_response.json
    sample_models.txt
  bootstrap.php (настройка окружения для тестов)
```

Каждый тест:
- изолирован, не зависит от других тестов
- использует моки для внешних зависимостей
- проверяет одну функцию или один путь выполнения
- содержит информативные ассерты
- покрывает позитивные и негативные сценарии

Преимущества:
- быстрая проверка корректности после изменений
- документация ожидаемого поведения функций
- выявление регрессий при рефакторинге
- уверенность в работе граничных случаев

## tasks

- [ ] file=tests/bootstrap.php scope=module change=создать bootstrap.php для настройки окружения тестов: загрузка автозагрузчика, установка тестовых переменных окружения, создание временных директорий reason=обеспечить изолированное окружение для тестов risk=low test=проверить что тесты запускаются в изолированном окружении

- [ ] file=tests/unit/error_formatter_test.php scope=func=format_code change=создать тесты для format_code: null, пустая строка, число, строка с числом, специальные символы reason=проверить форматирование кодов ошибок risk=low test=проверить что все варианты кодов форматируются корректно

- [ ] file=tests/unit/error_formatter_test.php scope=func=extract_error_text change=создать тесты для extract_error_text: пустая строка, строка с пробелами, многострочный текст, JSON строка reason=проверить извлечение текста ошибки risk=low test=проверить что текст корректно обрезается

- [ ] file=tests/unit/error_formatter_test.php scope=func=get_curl_errno change=создать тесты для get_curl_errno: исключение с errno, без errno, с неверным типом errno reason=проверить извлечение errno из исключения risk=low test=проверить что errno корректно извлекается или возвращается null

- [ ] file=tests/unit/error_formatter_test.php scope=func=is_timeout_errno change=создать тесты для is_timeout_errno: валидный timeout код, не timeout код, null reason=проверить определение timeout ошибок risk=low test=проверить что timeout коды определяются корректно

- [ ] file=tests/unit/error_formatter_test.php scope=func=is_timeout_exception change=создать тесты для is_timeout_exception: RequestException с timeout errno, без timeout errno, без errno reason=проверить определение timeout исключений risk=low test=проверить что timeout исключения определяются корректно

- [ ] file=tests/unit/error_formatter_test.php scope=func=format_request_error change=создать тесты для format_request_error: исключение с response и кодом, без response с errno, без response без errno, с пустым сообщением, с деталями reason=проверить форматирование ошибок запросов risk=low test=проверить что все варианты ошибок форматируются корректно

- [ ] file=tests/unit/error_formatter_test.php scope=func=format_connect_error change=создать тесты для format_connect_error: исключение с errno и сообщением, без errno, с пустым сообщением reason=проверить форматирование ошибок соединения risk=low test=проверить что все варианты ошибок соединения форматируются корректно

- [ ] file=tests/unit/error_formatter_test.php scope=func=format_generic_error change=создать тесты для format_generic_error: исключение с кодом и сообщением, без кода, с пустым сообщением, с нулевым кодом reason=проверить форматирование общих ошибок risk=low test=проверить что все варианты общих ошибок форматируются корректно

- [ ] file=tests/unit/message_builder_test.php scope=func=normalize_content_parts change=создать тесты для normalize_content_parts: строка, массив с текстовыми частями, массив со смешанными типами, пустой массив, массив с невалидными элементами reason=проверить нормализацию частей контента risk=low test=проверить что все варианты контента нормализуются корректно

- [ ] file=tests/unit/message_builder_test.php scope=func=build_messages change=создать тесты для build_messages: пустая история, история с user сообщениями, история с assistant сообщениями, история с tool сообщениями, история с tool_calls, история с невалидными записями reason=проверить построение массива сообщений для API risk=low test=проверить что все варианты истории преобразуются корректно

- [ ] file=tests/unit/message_builder_test.php scope=func=create_user_message change=создать тесты для create_user_message: обычный текст, пустая строка, текст с спецсимволами, длинный текст reason=проверить создание user сообщения risk=low test=проверить что сообщения создаются с правильной структурой

- [ ] file=tests/unit/message_builder_test.php scope=func=create_assistant_message_from_text change=создать тесты для create_assistant_message_from_text: обычный текст, пустая строка, текст с спецсимволами reason=проверить создание assistant сообщения risk=low test=проверить что сообщения создаются с правильной структурой

- [ ] file=tests/unit/message_builder_test.php scope=func=create_assistant_payload_from_text change=создать тесты для create_assistant_payload_from_text: проверка структуры payload с text и message reason=проверить создание payload для assistant risk=low test=проверить что payload содержит text и message

- [ ] file=tests/unit/message_builder_test.php scope=func=build_display_text_from_parts change=создать тесты для build_display_text_from_parts: массив с текстовыми частями, пустой массив, массив с не-текстовыми частями, массив с пустыми строками reason=проверить извлечение текста из частей risk=low test=проверить что текст корректно извлекается и объединяется

- [ ] file=tests/unit/model_manager_test.php scope=func=display_model_name change=создать тесты для display_model_name: модель с :free, без :free, пустая строка, только :free reason=проверить отображение имени модели risk=low test=проверить что :free корректно удаляется

- [ ] file=tests/unit/model_manager_test.php scope=func=load_model_list change=создать тесты для load_model_list: валидный файл, несуществующий файл, пустой файл, файл с невалидными строками, файл с пробелами reason=проверить загрузку списка моделей risk=low test=проверить что модели корректно парсятся из файла

- [ ] file=tests/unit/model_manager_test.php scope=func=load_model_state change=создать тесты для load_model_state: валидный JSON файл, несуществующий файл, пустой файл, файл с невалидным JSON, файл с простой строкой reason=проверить загрузку состояния модели risk=low test=проверить что состояние корректно загружается из файла

- [ ] file=tests/unit/model_manager_test.php scope=func=persist_model_state change=создать тесты для persist_model_state: сохранение валидной модели, пустая строка пути, проверка содержимого файла reason=проверить сохранение состояния модели risk=low test=проверить что состояние корректно сохраняется в файл

- [ ] file=tests/unit/model_manager_test.php scope=func=sync_model_state change=создать тесты для sync_model_state: синхронизация с сохраненной моделью, синхронизация с несуществующей моделью, пустой список моделей, модель не в списке reason=проверить синхронизацию состояния модели risk=low test=проверить что модель корректно синхронизируется

- [ ] file=tests/unit/model_manager_test.php scope=func=switch_to_next_model change=создать тесты для switch_to_next_model: переключение на следующую модель, зацикливание на последней, пустой список, модель не в списке reason=проверить переключение моделей risk=low test=проверить что модели корректно переключаются

- [ ] file=tests/unit/storage_test.php scope=func=sanitize_session_id change=создать тесты для sanitize_session_id: валидные символы, невалидные символы, пустая строка, только невалидные символы reason=проверить санитизацию session id risk=low test=проверить что невалидные символы заменяются на _

- [ ] file=tests/unit/storage_test.php scope=func=ends_with change=создать тесты для ends_with: строка заканчивается на needle, не заканчивается, пустая needle, пустая haystack reason=проверить проверку окончания строки risk=low test=проверить что окончание строки определяется корректно

- [ ] file=tests/unit/storage_test.php scope=func=is_legacy_json_path change=создать тесты для is_legacy_json_path: путь с .json, путь с .json.gz, путь без расширения reason=проверить определение legacy путей risk=low test=проверить что legacy пути определяются корректно

- [ ] file=tests/unit/storage_test.php scope=func=build_timestamped_file_path change=создать тесты для build_timestamped_file_path: путь с базовым временем, без базового времени, проверка формата timestamp reason=проверить построение пути с timestamp risk=low test=проверить что путь строится с правильным форматом

- [ ] file=tests/unit/tool_handler_test.php scope=func=build_tools_definition change=создать тесты для build_tools_definition: проверка структуры определения функций, наличие search_internet, проверка параметров reason=проверить построение определения tool calls risk=low test=проверить что определение имеет правильную структуру

- [ ] file=tests/unit/ai_processor_test.php scope=func=extract_response_payload change=создать тесты для extract_response_payload: валидный ответ с текстом, ответ с tool_calls, ответ без choices, ответ с пустым content, ответ с невалидной структурой reason=проверить извлечение payload из ответа API risk=low test=проверить что payload корректно извлекается из всех вариантов ответов

- [ ] file=tests/unit/ai_processor_test.php scope=func=log_ai_request change=создать тесты для log_ai_request: проверка что messages маскируются, payload логируется, пустой payload reason=проверить логирование запросов risk=low test=проверить что запросы корректно логируются с маскированием

- [ ] file=tests/unit/config_test.php scope=func=create_openrouter_client change=создать тесты для create_openrouter_client: проверка создания клиента с валидным ключом, без ключа выбрасывает исключение, с дополнительными заголовками reason=проверить создание клиента OpenRouter risk=low test=проверить что клиент создается с правильной конфигурацией

- [ ] file=composer.json scope=module change=добавить phpunit в dev зависимости, настроить autoload для tests reason=установить тестовый фреймворк risk=low test=проверить что phpunit устанавливается и запускается

- [ ] file=phpunit.xml scope=module change=создать конфигурацию phpunit: пути к тестам, bootstrap файл, настройки покрытия reason=настроить запуск тестов risk=low test=проверить что тесты запускаются через phpunit

