<?php
header('Content-Type: application/json');
session_start();

// CONFIG API
$API_URL = 'https://cubebotcorewebapi.azurewebsites.net/ChatbotLive/GetResponseFromBot?';
$API_KEY = 'ET_HFIHJDl1ufYWn3rDtDUvTNpJGC1FVu';
$BOT_GUID = 'd8e83ed2-652d-432c-b3c7-6e2b9ae6956f';

// CONFIG DB
$db_host = 'localhost';
$db_name = 'castelfalfiaiass_chatbot';
$db_user = 'castelfalfiaiass_chatbot';
$db_pass = '1m0qS[jr?xed';

// Funzione per sostituire l’URL target e rimuovere citazioni numeriche [1], [2]
function formatResult($text) {
    $targetUrl = 'https://be.synxis.com/?Hotel=76199';
    $newText   = 'https://booking.castelfalfi.com';

    $q = preg_quote($targetUrl, '/');

    $pattern = '/\[\s*<?\s*' . $q . '\s*>?\s*\]\(\s*<?\s*' . $q . '\s*>?\s*\)/i';
    $replacement = '[' . $newText . '](' . $targetUrl . ')';
    $text = preg_replace($pattern, $replacement, $text);

    $text = preg_replace('/\s*\[\d+\]\s*/', ' ', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\s+([.,;:!?])/', '$1', $text);

    return trim($text);
}

function formatElevenLabs($text) {
    $targetUrl = 'https://be.synxis.com/?Hotel=76199';
    $newUrl    = 'https://booking.castelfalfi.com';

    // 1. Rimuovi citazioni numeriche tipo [1]
    $text = preg_replace('/\[\d+\]/', '', $text);

    // 2. Trasforma i link markdown tenendo solo il contenuto tra []
    $text = preg_replace_callback('/\[(.*?)\]\(.*?\)/', function ($matches) use ($targetUrl, $newUrl) {
        $label = trim($matches[1]);
        // Se il contenuto nelle [] è il target, sostituiscilo con il nuovo
        if ($label === $targetUrl) {
            return $newUrl;
        }
        return $label;
    }, $text);

    // 3. Normalizza spazi
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
}

// funzione: trova email e le trasforma in [email](email)
function formatEmails($text) {
    $pattern = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i';
    return preg_replace_callback($pattern, function ($matches) {
        $email = $matches[0];
        return "[$email]($email)";
    }, $text);
}

// funzione: trova numeri di telefono anche con spazi e li trasforma in [numero](numero)
function formatPhones($text) {
    // regex: +, cifre, spazi o trattini, almeno 7-8 caratteri
    $pattern = '/\+?\s*(?:\d[\s\-]*){7,}\d/';
    return preg_replace_callback($pattern, function ($matches) {
        $phone = trim($matches[0]);
        // numero compatto: tolgo spazi e trattini
        $compact = preg_replace('/[\s\-]+/', '', $phone);
        return "[$compact]($compact)";
    }, $text);
}

// Autenticazione via header personalizzato X-Api-Key
$headers = getallheaders();
$clientKey = $headers['X-Api-Key'] ?? '';

if (trim($clientKey) !== $API_KEY) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

// Legge il corpo POST in formato JSON grezzo
$rawBody = file_get_contents("php://input");
$input = json_decode($rawBody, true);

// Estrazione dati con fallback a stringa vuota
$quesion = isset($input['chatText']) ? trim($input['chatText']) : '';
$language = isset($input['language']) && $input['language'] !== null ? trim($input['language']) : '';
$origin = isset($input['origin']) && $input['origin'] !== null ? trim($input['origin']) : '';
$conversationId = $input['conversationId'] ?? null;
$threadId = isset($input['threadId']) && $input['threadId'] !== null ? trim($input['threadId']) : '';

// Validazione
if (!$quesion) {
    http_response_code(400);
    echo json_encode(["error" => "Messaggio mancante"]);
    exit;
}

// Costruzione URL dell'API bot
$url = $API_URL . "chatbotUuid=" . $BOT_GUID;
if ($conversationId) {
    $url .= "&conversationUuid=" . urlencode($conversationId);
}

// Prepara la richiesta verso il bot
$bodyArr = ["chatText" => $quesion];
if ($conversationId) {
    $bodyArr["conversationId"] = $conversationId;
}
$body = json_encode($bodyArr);

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

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

// Controllo immediato di errore
if ($response === false) {
    http_response_code(502);
    echo json_encode([
        "error" => "Errore nella comunicazione con il bot.",
        "details" => error_get_last()
    ]);
    exit;
}

// Estraggo risposta del bot (testo)
$responseDecoded = json_decode($response, true);
$botResponse = $responseDecoded['BotMessageResponse']['result'] ?? null;

// Applica la formattazione personalizzata
$elevenLabsText = '';
if ($botResponse) {
    $botResponseFormatted = formatResult($botResponse);
    $botResponseFormatted = formatEmails($botResponseFormatted);
    $botResponseFormatted = formatPhones($botResponseFormatted);
    $elevenLabsText = formatElevenLabs($botResponse);

    // aggiorno il campo result
    $responseDecoded['BotMessageResponse']['result'] = $botResponseFormatted;
}

// ✅ Ripulisce tutte le stringhe del JSON
if (is_array($responseDecoded)) {
    array_walk_recursive($responseDecoded, function (&$value) {
        if (is_string($value)) {
            $value = preg_replace('/\[\d+\]/', '', $value);
            $value = preg_replace('/\s+/', ' ', $value);
            $value = trim($value);
        }
    });

    // aggiungo anche textElevenLabs
    $responseDecoded['textElevenLabs'] = $elevenLabsText ?? '';
}

// Salvataggio su file (debug)
file_put_contents(__DIR__ . '/risposta.txt', $response);

// Salva su DB
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
        ':response' => $responseDecoded['BotMessageResponse']['result'] ?? $botResponse
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Errore DB: " . $e->getMessage()]);
    exit;
}

// Preparo la risposta finale da inviare al client
$response = json_encode($responseDecoded, JSON_UNESCAPED_UNICODE);

// Imposto il codice HTTP in base a header remoto (se esiste)
if (isset($http_response_header[0])) {
    $statusParts = explode(" ", $http_response_header[0]);
    if (isset($statusParts[1]) && is_numeric($statusParts[1])) {
        http_response_code((int)$statusParts[1]);
    }
}

echo $response;

// Salvataggio anche del JSON arricchito e ripulito (debug)
file_put_contents(__DIR__ . '/risp.txt', $response);