<?php
// === DEBUG TEMPORANEO ===
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt'); // log locale nella stessa cartella


// === CORS SEMPLIFICATO ===
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

// === CONFIG API ===
$API_URL = 'https://cubebotcorewebapi2.azurewebsites.net/ChatbotLive/GetResponseFromBot?';
$API_KEY = 'ET_HFIHJDl1ufYWn3rDtDUvTNpJGC1FVu';
$BOT_GUID = 'd8e83ed2-652d-432c-b3c7-6e2b9ae6956f';

// === CONFIG DB ===
$db_host = 'localhost';
$db_name = 'castelfalfiaiass_chatbot';
$db_user = 'castelfalfiaiass_chatbot';
$db_pass = '1m0qS[jr?xed';

// === FUNZIONI DI FORMATTAZIONE ===
function convertLinksToHtml($text) {
    if (!$text) return '';

    // Unity-style <link="">
    $text = preg_replace_callback('/<link="(.*?)">(.*?)<\/link>/i', function ($m) {
        $url = htmlspecialchars_decode($m[1]);
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="color:#3565A7;text-decoration:underline;">link</a>';
    }, $text);

    // Markdown-style [label](url)
    $text = preg_replace_callback('/\[(.*?)\]\((https?:\/\/[^)]+)\)/i', function ($m) {
        $label = trim($m[1]);
        $url = trim($m[2]);

        // se il testo tra [] è un URL → mostra "link"
        if (filter_var($label, FILTER_VALIDATE_URL)) {
            $label = 'link';
        }

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="color:#3565A7;text-decoration:underline;">' 
               . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }, $text);

    return trim($text);
}

function formatEmails($text) {
    return preg_replace_callback('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', function ($m) {
        $email = $m[0];
        return '<a href="mailto:' . $email . '" style="color:#3565A7;text-decoration:underline;">' . $email . '</a>';
    }, $text);
}

function formatPhones($text) {
    return preg_replace_callback('/\+?\s*(?:\d[\s\-]*){7,}\d/', function ($m) {
        $phone = trim($m[0]);
        $compact = preg_replace('/[\s\-]+/', '', $phone);
        return '<a href="tel:' . $compact . '" style="color:#3565A7;text-decoration:underline;">' . $phone . '</a>';
    }, $text);
}

function cleanText($text) {
    $text = preg_replace(['/\\[\\d+\\]/', '/[ \\t]+/', '/\\s+([.,;:!?])/', '/\\s{2,}/'], ['', ' ', '$1', ' '], $text);
    return trim($text);
}

function formatElevenLabs($text) {
    $text = preg_replace('/\\[\\d+\\]/', '', $text);
    $text = preg_replace_callback('/\\[(.*?)\\]\\((.*?)\\)/', fn($m) => $m[1], $text);
    return trim(strip_tags($text));
}

function convertHtmlLinksToMarkdown($text) {
    return preg_replace_callback(
        '/<a\s+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i',
        function ($m) {
            $url = html_entity_decode(trim($m[1]));
            $label = strip_tags(trim($m[2]));
            if ($label === $url) {
                return '[' . $url . '](' . $url . ')';
            } else {
                return '[' . $label . '](' . $url . ')';
            }
        },
        $text
    );
}

function htmlToMarkdownClean($text) {
    $text = convertHtmlLinksToMarkdown($text);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    return trim($text);
}

// === AUTENTICAZIONE ===
$headers = getallheaders();
$clientKey = $headers['X-Api-Key'] ?? '';
if (trim($clientKey) !== $API_KEY) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// === INPUT ===
$rawBody = file_get_contents("php://input");
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$quesion = trim($input['chatText'] ?? '');
$language = trim($input['language'] ?? '');
$origin = trim($input['origin'] ?? '');
$conversationId = $input['conversationId'] ?? null;
$threadId = trim($input['threadId'] ?? '');

if ($quesion === '') {
    http_response_code(400);
    echo json_encode(["error" => "Messaggio mancante"]);
    exit;
}

// === COSTRUZIONE RICHIESTA AL BOT ===
$url = $API_URL . "chatbotUuid=" . urlencode($BOT_GUID);
if ($conversationId) $url .= "&conversationUuid=" . urlencode($conversationId);

$body = json_encode(["chatText" => $quesion]);
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
    echo json_encode(["error" => "Errore nella comunicazione con il bot."]);
    exit;
}

$responseDecoded = json_decode($response, true);
if (!is_array($responseDecoded)) {
    http_response_code(502);
    echo json_encode(["error" => "Risposta non valida dal bot", "raw" => $response]);
    exit;
}

$botResponse = $responseDecoded['BotMessageResponse']['result'] ?? ($responseDecoded['result'] ?? null);

// === FORMATTAZIONE RISPOSTA ===
$elevenLabsText = '';
$markdownText = '';
if ($botResponse) {
    $botResponseFormatted = cleanText($botResponse);
    $botResponseFormatted = formatEmails($botResponseFormatted);
    $botResponseFormatted = formatPhones($botResponseFormatted);
    $botResponseFormatted = convertLinksToHtml($botResponseFormatted);

    // Nuova conversione: HTML -> Markdown
    $markdownText = htmlToMarkdownClean($botResponseFormatted);

    $elevenLabsText = formatElevenLabs($botResponse);
    $responseDecoded['BotMessageResponse']['result'] = $botResponseFormatted;
}

$responseDecoded['textElevenLabs'] = $elevenLabsText;
$responseDecoded['textMarkdown'] = $markdownText;


// === SALVATAGGIO RISPOSTA IN FILE TXT ===
try {
    $filePath = __DIR__ . '/bot_response.txt';
    $timestamp = date('Y-m-d H:i:s');
    $contentToSave = "=== " . $timestamp . " ===\n";
    $contentToSave .= "Domanda: " . $quesion . "\n";
    $contentToSave .= "Risposta: " . ($botResponse ?? '[nessuna risposta]') . "\n\n";

    file_put_contents($filePath, $contentToSave, FILE_APPEND | LOCK_EX);
} catch (Exception $e) {
    error_log("Errore salvataggio risposta TXT: " . $e->getMessage());
}


// === SALVATAGGIO SU DATABASE ===
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        INSERT INTO chat (chat_id, language, origin, quesion, response)
        VALUES (:chat_id, :language, :origin, :quesion, :response)
    ");
    $stmt->execute([
        ':chat_id' => $threadId,
        ':language' => $language,
        ':origin' => $origin,
        ':quesion' => $quesion,
        ':response' => $responseDecoded['BotMessageResponse']['result'] ?? ''
    ]);
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Errore DB", "details" => $e->getMessage()]);
    exit;
}

// === RISPOSTA ===
echo json_encode($responseDecoded, JSON_UNESCAPED_UNICODE);