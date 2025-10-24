<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require_once __DIR__ . '/../src/cors.php';
$config = require __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/middleware/auth.php';
require_once __DIR__ . '/../src/utils/formatters.php';
require_once __DIR__ . '/../src/services/botService.php';
require_once __DIR__ . '/../src/services/dbService.php';

// Autenticazione
checkAuth($config['API_KEY']);

// Input
$input = json_decode(file_get_contents("php://input"), true);
$question = trim($input['chatText'] ?? '');
$language = $input['language'] ?? '';
$origin = $input['origin'] ?? '';
$conversationId = $input['conversationId'] ?? null;
$threadId = trim($input['threadId'] ?? '');

if ($question === '') {
    http_response_code(400);
    echo json_encode(["error" => "Messaggio mancante"]);
    exit;
}

// Chiamata al bot
$responseDecoded = getBotResponse($config['API_URL'], $config['API_KEY'], $config['BOT_GUID'], $question, $conversationId);

// Elaborazione e formattazione
$botResponse = $responseDecoded['BotMessageResponse']['result'] ?? ($responseDecoded['result'] ?? null);
$formatted = $botResponse ? cleanText($botResponse) : '';
$formatted = formatEmails(formatPhones(convertLinksToHtml($formatted)));
$markdownText = htmlToMarkdownClean($formatted);
$elevenLabsText = formatElevenLabs($botResponse ?? '');

$responseDecoded['textElevenLabs'] = $elevenLabsText;
$responseDecoded['textMarkdown'] = $markdownText;

// Log su file
file_put_contents(__DIR__ . '/../logs/bot_response.txt',
    "=== " . date('Y-m-d H:i:s') . " ===\nDomanda: $question\nRisposta: $botResponse\n\n",
    FILE_APPEND | LOCK_EX
);

// Salvataggio DB
try {
    $pdo = getPDO($config['DB']);
    saveChat($pdo, $threadId, $language, $origin, $question, $formatted);
} catch (Exception $e) {
    error_log("DB Error: " . $e->getMessage());
}

// Output finale
echo json_encode($responseDecoded, JSON_UNESCAPED_UNICODE);