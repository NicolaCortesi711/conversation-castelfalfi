<?php
// =========================
// Endpoint pubblico che riceve richieste dal frontend,
// chiama CubeBot e ElevenLabs lato server, formatta la risposta
// e salva la chat nel DB.
// Config sensibili sono letti da ../../keys/config.php (non pubblico).
// =========================

/** DEBUG / LOG **/
error_reporting(E_ALL);
ini_set('display_errors', 0); // 0 in produzione, 1 per debug
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

/** CORS (autorizza solo domini conosciuti) **/
$allowed_origins = [
    'http://localhost:5173',
    'https://castelfalfiaiassistant.com'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
session_start();

/** INCLUDI CONFIG PRIVATO (fuori dalla root web) **/
$configPath = __DIR__ . '/../../keys/config.php';
if (!file_exists($configPath)) {
    error_log("Config file non trovato: $configPath");
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}
$config = require $configPath;

/** ESTRAI VARIABILI DI CONFIG **/
$API_URL = $config['API_URL'] ?? '';
$API_KEY = $config['API_KEY'] ?? '';
$BOT_GUID = $config['BOT_GUID'] ?? '';

$ELEVENLABS_API_KEY = $config['ELEVENLABS_API_KEY'] ?? '';
$VOICE_ID_IT = $config['VOICE_ID_IT'] ?? '';
$VOICE_ID_EN = $config['VOICE_ID_EN'] ?? '';

$db_host = $config['DB_HOST'] ?? '';
$db_name = $config['DB_NAME'] ?? '';
$db_user = $config['DB_USER'] ?? '';
$db_pass = $config['DB_PASS'] ?? '';

/** COSTANTI DI STILE LINK (usate nelle funzioni di formattazione) **/
define('LINK_STYLE', 'color:#3565A7;text-decoration:underline;');

/** ===========================
 *  Funzioni di utilità e formattazione
 *  =========================== */

function convertLinksToHtml($text) {
    if (!$text) return '';
    // Rimuove placeholder tipo [#Document1]
    $text = preg_replace('/\[#Document\d+\]/i', '', $text);

    // <link="url">label</link> -> link
    $text = preg_replace_callback('/<link="(.*?)">(.*?)<\/link>/i', function ($m) {
        $url = htmlspecialchars_decode($m[1]);
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="' . LINK_STYLE . '">link</a>';
    }, $text);

    // Markdown [label](https://...)
    $text = preg_replace_callback('/\[(.*?)\]\((https?:\/\/[^)]+)\)/i', function ($m) {
        $label = trim($m[1]);
        $url = trim($m[2]);
        if (filter_var($label, FILTER_VALIDATE_URL)) $label = 'link';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="' . LINK_STYLE . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }, $text);

    // URL plain -> link
    $text = preg_replace_callback('/(?<!href="|">)(https?:\/\/[^\s<>"\'\)]+)/i', function ($m) {
        $url = trim($m[1]);
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="' . LINK_STYLE . '">link</a>';
    }, $text);

    // <a href="tel:...">label</a> -> format telefono compattato per il link
    $text = preg_replace_callback('/<a\s+href="tel:([^"]+)"[^>]*>(.*?)<\/a>/i', function ($m) {
        $href = $m[1];
        $label = preg_replace('/[^\d+]/', '', $m[2]);
        return '<a href="tel:' . htmlspecialchars($href, ENT_QUOTES) . '" style="' . LINK_STYLE . '">' . $label . '</a>';
    }, $text);

    return $text;
}

function formatEmails($text) {
    return preg_replace_callback('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', function ($m) {
        $email = $m[0];
        return '<a href="mailto:' . htmlspecialchars($email, ENT_QUOTES) . '" style="' . LINK_STYLE . '">' . htmlspecialchars($email, ENT_QUOTES) . '</a>';
    }, $text);
}

function formatPhones($text) {
    return preg_replace_callback('/\+?\s*(?:\d[\s\-]*){7,}\d/', function ($m) {
        $phone = trim($m[0]);
        $compact = preg_replace('/[\s\-]+/', '', $phone);
        return '<a href="tel:' . htmlspecialchars($compact, ENT_QUOTES) . '" style="' . LINK_STYLE . '">' . htmlspecialchars($phone, ENT_QUOTES) . '</a>';
    }, $text);
}

function cleanText($text) {
    if (!is_string($text)) return '';
    $text = preg_replace(['/\\[\\d+\\]/', '/[ \\t]+/', '/\\s+([.,;:!?])/', '/\\s{2,}/'], ['', ' ', '$1', ' '], $text);
    return trim($text);
}

function formatElevenLabs($text) {
    if (!$text) return '';
    $text = preg_replace('/\[\d+\]/', '', $text);
    // trasforma [label](url) in label
    $text = preg_replace_callback('/\[(.*?)\]\((https?:\/\/[^)]+)\)/', function ($m) {
        return trim($m[1]) ?: 'link';
    }, $text);
    // sostituisce URL con la parola 'link'
    $text = preg_replace('/https?:\/\/\S+/i', 'link', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/\s{2,}/', ' ', $text);
    return trim($text);
}

function convertHtmlLinksToMarkdown($text) {
    return preg_replace_callback(
        '/<a\s+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i',
        function ($m) {
            $url = html_entity_decode(trim($m[1]));
            $label = strip_tags(trim($m[2]));
            return '[' . ($label ?: $url) . '](' . $url . ')';
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

/** ===========================
 *  Autenticazione semplice (X-Api-Key opzionale)
 *  Se vuoi forzare il controllo delle richieste frontend,
 *  puoi inserire una chiave condivisa nel config e verificare qui.
 *  =========================== */
$headers = function_exists('getallheaders') ? getallheaders() : [];
$clientKey = $headers['X-Api-Key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
if (!empty($config['FRONTEND_API_KEY'])) {
    if (trim($clientKey) !== trim($config['FRONTEND_API_KEY'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

/** ===========================
 *  Lettura input JSON
 *  =========================== */
$rawBody = file_get_contents("php://input");
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$quesion = trim($input['chatText'] ?? '');
$language = trim($input['language'] ?? '');
$origin = trim($input['origin'] ?? '');
$threadId = trim($input['threadId'] ?? '');
$chat_conversation_uuid = trim($input['chat_conversation_uuid'] ?? '');

if ($quesion === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Messaggio mancante']);
    exit;
}

/** LOG DEBUG */
error_log("Chat convo uuid ricevuto: " . ($chat_conversation_uuid ?: '(vuoto)'));

/** ===========================
 *  Richiesta a CubeBot
 *  =========================== */
$cbUrl = rtrim($API_URL, '?') . '?chatbotUuid=' . urlencode($BOT_GUID);
if (!empty($chat_conversation_uuid)) {
    $cbUrl .= "&conversationUuid=" . urlencode($chat_conversation_uuid);
}

$cbOptions = [
    'http' => [
        'method' => 'POST',
        'header' => [
            "Authorization: Bearer $API_KEY",
            "Content-Type: application/json",
            "Accept: application/json"
        ],
        'content' => json_encode(['chatText' => $quesion]),
        'ignore_errors' => true
    ]
];

$cbResponse = @file_get_contents($cbUrl, false, stream_context_create($cbOptions));
if ($cbResponse === false) {
    error_log("Errore chiamata CubeBot: $cbUrl");
    http_response_code(502);
    echo json_encode(['error' => 'Errore nella comunicazione con il bot.']);
    exit;
}

$responseDecoded = json_decode($cbResponse, true);
if (!is_array($responseDecoded)) {
    error_log("Risposta non valida da CubeBot: " . substr($cbResponse,0,200));
    http_response_code(502);
    echo json_encode(['error' => 'Risposta non valida dal bot']);
    exit;
}

/** Estrai risultato e uuid */
$botResponse = $responseDecoded['BotMessageResponse']['result'] ?? ($responseDecoded['result'] ?? null);
$newUuid = $responseDecoded['BotMessageResponse']['chat_conversation_uuid'] ?? ($responseDecoded['chat_conversation_uuid'] ?? '');

/** Log */
error_log("chat_conversation_uuid restituito da CubeBot: " . ($newUuid ?: '(vuoto)'));

/** ===========================
 *  Formattazione testo per frontend e per ElevenLabs
 *  =========================== */
$elevenLabsText = '';
$markdownText = '';
$botResponseFormatted = '';
if ($botResponse) {
    $botResponseFormatted = cleanText($botResponse);
    $botResponseFormatted = formatEmails($botResponseFormatted);
    $botResponseFormatted = formatPhones($botResponseFormatted);
    $botResponseFormatted = convertLinksToHtml($botResponseFormatted);
    $markdownText = htmlToMarkdownClean($botResponseFormatted);
    $elevenLabsText = formatElevenLabs($botResponse);
}

/** Aggiungo campi utili alla risposta */
$responseDecoded['BotMessageResponse']['result'] = $botResponseFormatted;
$responseDecoded['textElevenLabs'] = $elevenLabsText;
$responseDecoded['textMarkdown'] = $markdownText;
$responseDecoded['chat_conversation_uuid'] = $newUuid;

/** ===========================
 *  ElevenLabs TTS (server-side) -> salva file audio e restituisci URL
 *  =========================== */
$audioUrl = null;
if (!empty($botResponse) && !empty($ELEVENLABS_API_KEY)) {
    $voiceId = ($language === 'en') ? $VOICE_ID_EN : $VOICE_ID_IT;

    $ttsPayload = json_encode([
        'text' => $elevenLabsText ?: $botResponse,
        'model_id' => 'eleven_turbo_v2_5',
        'language_code' => $language,
        'voice_settings' => [
            'stability' => 0.5,
            'similarity_boost' => 0.5,
            'style' => 0.5,
            'use_speaker_boost' => true
        ],
    ]);

    $ttsOpts = [
        'http' => [
            'method' => 'POST',
            'header' => [
                "xi-api-key: $ELEVENLABS_API_KEY",
                "Content-Type: application/json"
            ],
            'content' => $ttsPayload,
            'ignore_errors' => true
        ]
    ];

    $ttsRes = @file_get_contents("https://api.elevenlabs.io/v1/text-to-speech/$voiceId", false, stream_context_create($ttsOpts));

    if ($ttsRes !== false && strlen($ttsRes) > 0) {
        // Assicurati che la cartella audio esista e sia scrivibile
        $audioDir = realpath(__DIR__ . '/../audio') ?: (__DIR__ . '/../audio');
        if (!is_dir($audioDir)) {
            @mkdir($audioDir, 0775, true);
        }
        $filename = "audio_" . uniqid() . ".mp3";
        $saved = @file_put_contents("$audioDir/$filename", $ttsRes);
        if ($saved !== false) {
            // Costruisci l'URL pubblico corretto per l'audio (modifica se necessario)
            $audioUrl = 'https://castelfalfiaiassistant.com/audio/' . $filename;
        } else {
            error_log("Impossibile salvare audio: $audioDir/$filename");
        }
    } else {
        error_log("Errore ElevenLabs TTS: " . substr($ttsRes ?? 'NULL', 0, 300));
    }
}

/** ===========================
 *  Salvataggio su DB
 *  =========================== */
try {
    if (!empty($db_host) && !empty($db_name)) {
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
            ':response' => $botResponseFormatted
        ]);
    } else {
        error_log("Dati DB mancanti, salto salvataggio.");
    }
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
}

/** ===========================
 *  Output finale al frontend
 *  =========================== */
$output = [
    'botText' => $botResponseFormatted ?: ($language === 'en' ? 'No response from the bot.' : 'Nessuna risposta dal bot.'),
    'audioUrl' => $audioUrl,
    'chat_conversation_uuid' => $newUuid,
    // mantengo anche il raw di CubeBot se vuoi per debug (OPZIONALE)
    // 'cubeBotRaw' => $responseDecoded,
];

echo json_encode($output, JSON_UNESCAPED_UNICODE);
exit;