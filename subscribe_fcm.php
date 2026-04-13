<?php
header('Content-Type: application/json');
require_once 'config.php';

// 1. Read the JSON payload sent from our JavaScript fetch()
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['token']) || !isset($data['topic'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token or topic']);
    exit();
}

$token = $data['token'];
$topic = $data['topic']; // e.g., 'all', 'student', or 'driver'

try {
    // 2. Subscribe the device token to the specific topic
    $messaging->subscribeToTopic($topic, $token);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => "Subscribed to $topic"]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>