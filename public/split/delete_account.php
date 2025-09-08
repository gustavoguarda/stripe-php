<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

/*
curl -s -X POST http://localhost:4242/split/delete_account.php \
  -H "Content-Type: application/json" \
  -d '{"account_id":"acct_1S4u8RJ2c0CjK04l"}' | jq

RESULT:
  {
  "success": true,
  "deleted": {
    "id": "acct_1S3pfxBz6CwkThAe",
    "object": "account",
    "deleted": true
  }
}
*/

try {
    \Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $accountId = $payload['account_id'] ?? null;

    if (!$accountId) {
        http_response_code(400);
        echo json_encode(['error' => 'account_id é obrigatório']);
        exit;
    }

    // IMPORTANTE: só use em TEST. Em produção prefira "reject" ou encerrar relação.
    $deleted = \Stripe\Account::retrieve($accountId)->delete();

    echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $t) {
    http_response_code(500);
    echo json_encode(['error' => $t->getMessage()]);
}
