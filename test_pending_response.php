<?php
require 'vendor/autoload.php';
require_once 'alice_handler.php';

// Симулируем запрос, который займет больше 4.3 секунды
$sessionId = 'test_pending_' . uniqid();

// Первый запрос - должен вызвать промежуточный ответ
echo "=== TEST 1: Initial request (should timeout) ===\n";

$input1 = [
    'session' => [
        'session_id' => $sessionId,
        'user_id' => 'test_user'
    ],
    'request' => [
        'original_utterance' => 'Сгенерируй очень длинный ответ, который займет много времени',
        'command' => ''
    ],
    'version' => '1.0'
];

$GLOBALS['__PHP_INPUT_MOCK__'] = json_encode($input1);
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
try {
    $handler = new AliceHandler();
    $handler->handleRequest();
    $output1 = ob_get_clean();
    echo "Response 1: " . substr($output1, 0, 200) . "\n";
    $response1 = json_decode($output1, true);
    if (isset($response1['response']['text'])) {
        echo "Text 1: " . $response1['response']['text'] . "\n";
        if (strpos($response1['response']['text'], 'Готово') !== false) {
            echo "✓ Got WAITING_MESSAGE\n";
        }
    }
} catch (\Exception $e) {
    ob_end_clean();
    echo "Error 1: " . $e->getMessage() . "\n";
}

// Ждем несколько секунд, чтобы фоновый процесс завершился
echo "\n=== Waiting 6 seconds for background process ===\n";
sleep(6);

// Проверяем pending state
$pendingDir = __DIR__ . '/storage/pending';
$pendingState = load_pending_state($sessionId, $pendingDir);
if ($pendingState !== null) {
    echo "Pending state status: " . ($pendingState['status'] ?? 'none') . "\n";
    echo "Has response: " . (isset($pendingState['response']) && $pendingState['response'] !== null ? 'yes' : 'no') . "\n";
    if (isset($pendingState['response']) && $pendingState['response'] !== null) {
        echo "Response text: " . substr($pendingState['response']['text'] ?? 'no text', 0, 100) . "\n";
    }
} else {
    echo "No pending state found\n";
}

// Второй запрос - "готово"
echo "\n=== TEST 2: Saying 'готово' ===\n";

$input2 = [
    'session' => [
        'session_id' => $sessionId,
        'user_id' => 'test_user'
    ],
    'request' => [
        'original_utterance' => 'готово',
        'command' => ''
    ],
    'version' => '1.0'
];

$GLOBALS['__PHP_INPUT_MOCK__'] = json_encode($input2);

ob_start();
try {
    $handler = new AliceHandler();
    $handler->handleRequest();
    $output2 = ob_get_clean();
    echo "Response 2: " . substr($output2, 0, 200) . "\n";
    $response2 = json_decode($output2, true);
    if (isset($response2['response']['text'])) {
        echo "Text 2: " . $response2['response']['text'] . "\n";
        if (strpos($response2['response']['text'], 'Готово') === false && 
            strpos($response2['response']['text'], 'Надо подумать') === false) {
            echo "✓ Got final response (not WAITING_MESSAGE)\n";
        } else {
            echo "✗ Still got WAITING_MESSAGE\n";
        }
    }
} catch (\Exception $e) {
    ob_end_clean();
    echo "Error 2: " . $e->getMessage() . "\n";
}

echo "\n=== Test completed ===\n";

