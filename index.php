<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// === CORS ===
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
header('Content-Type: application/json');
session_start();

// === INCLUDI CONFIG PRIVATO ===
$config = require __DIR__ . '/../../keys/config.php';

// === VARIABILI ===
$API_URL  = $config['API_URL'];
$API_KEY  = $config['API_KEY'];
$BOT_GUID = $config['BOT_GUID'];

$ELEVENLABS_API_KEY = $config['ELEVENLABS_API_KEY'];
$VOICE_ID_IT = $config['VOICE_ID_IT'];
$VOICE_ID_EN = $config['VOICE_ID_EN'];

$db_host = $config['DB_HOST'];
$db_name = $config['DB_NAME'];
$db_user = $config['DB_USER'];
$db_pass = $config['DB_PASS'];

// === INPUT ===
$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input) || empty(trim($input['chatText'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['error' => 'Messaggio mancante']);
    exit;
}

$text = trim($input['chatText']);
$language = trim($input['language'] ?? 'it');
$origin = trim($input['origin'] ?? 'default');
$threadId = trim($input['threadId'] ?? '');
$conversationUuid = trim($input['chat_conversation_uuid'] ?? '');

// === RICHIESTA A CUBEBOT ===
$url = $API_URL . "chatbotUuid=" . urlencode($BOT_GUID);
if ($conversationUuid) {
    $url .= "&conversationUuid=" . urlencode($conversationUuid);
}

$options = [
    'http' => [
        'method' => 'POST',
        'header' => [
            "Authorization: Bearer $API_KEY",
            "Content-Type: application/json",
            "Accept: application/json"
        ],
        'content' => json_encode(['chatText' => $text]),
        'ignore_errors' => true
    ]
];

$response = file_get_contents($url, false, stream_context_create($options));

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Errore nella comunicazione con il bot.']);
    exit;
}

$data = json_decode($response, true);
$botText = $data['BotMessageResponse']['result'] ?? ($data['result'] ?? '');
$newUuid = $data['BotMessageResponse']['chat_conversation_uuid'] ?? ($data['chat_conversation_uuid'] ?? '');

// === ELEVENLABS SERVER-SIDE ===
$voiceId = ($language === 'en') ? $VOICE_ID_EN : $VOICE_ID_IT;
$audioUrl = null;

if ($botText) {
    $ttsPayload = json_encode([
        'text' => $botText,
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

    $ttsRes = file_get_contents("https://api.elevenlabs.io/v1/text-to-speech/$voiceId", false, stream_context_create($ttsOpts));

    if ($ttsRes !== false) {
        $filename = "audio_" . uniqid() . ".mp3";
        $audioDir = __DIR__ . '/../audio';
        if (!is_dir($audioDir)) mkdir($audioDir, 0775, true);
        file_put_contents("$audioDir/$filename", $ttsRes);
        $audioUrl = "https://castelfalfiaiassistant.com/audio/$filename";
    }
}

// === SALVATAGGIO SU DB ===
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $stmt = $pdo->prepare("
        INSERT INTO chat (chat_id, language, origin, quesion, response)
        VALUES (:chat_id, :language, :origin, :quesion, :response)
    ");
    $stmt->execute([
        ':chat_id' => $threadId,
        ':language' => $language,
        ':origin' => $origin,
        ':quesion' => $text,
        ':response' => $botText
    ]);
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
}

// === RISPOSTA ===
echo json_encode([
    'botText' => $botText ?: 'Nessuna risposta disponibile',
    'audioUrl' => $audioUrl,
    'chat_conversation_uuid' => $newUuid
], JSON_UNESCAPED_UNICODE);