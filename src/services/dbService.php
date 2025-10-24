<?php
function getPDO($config) {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
        $config['user'],
        $config['pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function saveChat($pdo, $threadId, $language, $origin, $question, $response) {
    $stmt = $pdo->prepare("
        INSERT INTO chat (chat_id, language, origin, quesion, response)
        VALUES (:chat_id, :language, :origin, :quesion, :response)
    ");
    $stmt->execute([
        ':chat_id' => $threadId,
        ':language' => $language,
        ':origin' => $origin,
        ':quesion' => $question,
        ':response' => $response
    ]);
}
?>