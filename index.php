<?php
// === DEBUG ===
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Api-Key");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
session_start();

// === CONFIG ===
$API_URL = 'https://cubebotcorewebapi2.azurewebsites.net/ChatbotLive/GetResponseFromBot?';
$API_KEY = 'ET_HFIHJDl1ufYWn3rDtDUvTNpJGC1FVu';
$BOT_GUID = 'd8e83ed2-652d-432c-b3c7-6e2b9ae6956f';

$db_host = 'localhost';
$db_name = 'castelfalfiaiass_chatbot';
$db_user = 'castelfalfiaiass_chatbot';
$db_pass = '1m0qS[jr?xed';

// === AUTENTICAZIONE ===
$headers = getallheaders();
if (($headers['X-Api-Key'] ?? '') !== $API_KEY) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// === INPUT ===
$input = json_decode(file_get_contents("php://input"), true);
$question = trim($input['chatText'] ?? '');
$language = trim($input['language'] ?? '');
$origin = trim($input['origin'] ?? '');
$chat_conversation_uuid = trim($input['chat_conversation_uuid'] ?? '');

if ($question === '') {
    http_response_code(400);
    echo json_encode(["error" => "Messaggio mancante"]);
    exit;
}

// === CHIAMATA A CUBEBOT ===
$url = $API_URL . "chatbotUuid=" . urlencode($BOT_GUID);
if (!empty($chat_conversation_uuid)) {
    $url .= "&conversationUuid=" . urlencode($chat_conversation_uuid);
}

$body = json_encode(["chatText" => $question]);
$options = [
    'http' => [
        'method' => 'POST',
        'header' => [
            "Authorization: Bearer $API_KEY",
            "Content-Type: application/json",
            "Accept: application/json"
        ],
        'content' => $body,
        'ignore_errors' => true
    ]
];

$response = file_get_contents($url, false, stream_context_create($options));

if ($response === false) {
    http_response_code(502);
    echo json_encode(["error" => "Errore di comunicazione con CubeBot"]);
    exit;
}

$responseDecoded = json_decode($response, true);
if (!is_array($responseDecoded)) {
    http_response_code(502);
    echo json_encode(["error" => "Risposta non valida da CubeBot"]);
    exit;
}

// === PRENDE chat_conversation_uuid DAL BOT ===
$newConversationUuid =
    $responseDecoded['chat_conversation_uuid']
    ?? ($responseDecoded['BotMessageResponse']['chat_conversation_uuid'] ?? null)
    ?? ($responseDecoded['conversationUuid'] ?? null)
    ?? ($responseDecoded['BotMessageResponse']['conversationUuid'] ?? null);

// 🔹 Se il bot ne ha restituito uno nuovo, aggiorna
if ($newConversationUuid) {
    $chat_conversation_uuid = $newConversationUuid;
}

// === RISULTATO DEL BOT ===
$botResponse = $responseDecoded['BotMessageResponse']['result'] ?? ($responseDecoded['result'] ?? '');

// === FORMATTAZIONE BASE ===
function cleanText($text) {
    $text = preg_replace(['/\\[\\d+\\]/', '/[ \\t]+/', '/\\s+([.,;:!?])/', '/\\s{2,}/'], ['', ' ', '$1', ' '], $text);
    return trim($text);
}
function convertLinksToHtml($text) {
    return preg_replace_callback(
        '/(?<!href="|">)(https?:\/\/[^\s<>"\'\)]+)/i',
        fn($m) => '<a href="' . htmlspecialchars(trim($m[1])) . '" target="_blank" style="color:#3565A7;text-decoration:underline;">link</a>',
        $text
    );
}

$botResponseFormatted = convertLinksToHtml(cleanText($botResponse));
$responseDecoded['BotMessageResponse']['result'] = $botResponseFormatted;
$responseDecoded['chat_conversation_uuid'] = $chat_conversation_uuid; // 🔹 fondamentale!

// === SALVATAGGIO SU DB ===
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare("
        INSERT INTO chat (chat_id, language, origin, quesion, response)
        VALUES (:chat_id, :language, :origin, :quesion, :response)
    ");
    $stmt->execute([
        ':chat_id' => $chat_conversation_uuid,
        ':language' => $language,
        ':origin' => $origin,
        ':quesion' => $question,
        ':response' => $botResponseFormatted
    ]);
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
}

// === RISPOSTA ===
echo json_encode($responseDecoded, JSON_UNESCAPED_UNICODE);