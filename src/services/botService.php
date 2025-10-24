<?php
// src/services/botService.php

function getBotResponse($apiUrl, $apiKey, $botGuid, $question, $conversationId = null) {
    $url = $apiUrl . "chatbotUuid=" . urlencode($botGuid);
    if ($conversationId) $url .= "&conversationUuid=" . urlencode($conversationId);

    $body = json_encode(["chatText" => $question]);

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => [
                "Authorization: Bearer $apiKey",
                "Content-Type: application/json",
                "Accept: application/json"
            ],
            'content' => $body,
            'ignore_errors' => true
        ]
    ];

    $response = file_get_contents($url, false, stream_context_create($options));
    return json_decode($response, true);
}
?>