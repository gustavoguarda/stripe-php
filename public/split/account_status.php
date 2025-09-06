<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

try {
    \Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $accountId = $payload['account_id'] ?? null;

    if (!$accountId) {
        http_response_code(400);
        echo json_encode(['error' => 'account_id é obrigatório']);
        exit;
    }

    // Busca os detalhes da conta
    $account = \Stripe\Account::retrieve($accountId);

    echo json_encode([
        'success' => true,
        'account' => $account
    ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $t) {
    http_response_code(500);
    echo json_encode(['error' => $t->getMessage()]);
}
