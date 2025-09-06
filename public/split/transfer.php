<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

try {
  \Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

  $payload = json_decode(file_get_contents('php://input'), true) ?: [];
  $accountId = $payload['account_id'] ?? null;     // acct_...
  $amountBrl = $payload['amount_brl'] ?? null;     // ex.: 100.50
  $orderRef  = $payload['order_ref'] ?? null;      // opcional, p/ conciliação
  $idempKey  = $payload['idempotency_key'] ?? null;// obrig. no mundo real!

  if (!$accountId || !$amountBrl) {
    http_response_code(400);
    echo json_encode(['error' => 'account_id e amount_brl são obrigatórios']);
    exit;
  }

  // Valor em centavos
  $amount = (int) round(((float)$amountBrl) * 100);

  $opts = [];
  if ($idempKey) $opts['idempotency_key'] = $idempKey;

  $transfer = \Stripe\Transfer::create([
    'amount'      => $amount,
    'currency'    => 'brl',
    'destination' => $accountId,
    'transfer_group' => $orderRef, // opcional
    'metadata'    => [
      'source' => 'stripe-php-poc',
    ],
  ], $opts);

  echo json_encode(['success' => true, 'transfer' => $transfer]);
} catch (\Stripe\Exception\ApiErrorException $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
