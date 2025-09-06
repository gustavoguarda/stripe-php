<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

try {
  \Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

  $payload = json_decode(file_get_contents('php://input'), true) ?: [];
  $email   = $payload['email']   ?? null;
  $expertId= $payload['expert_id'] ?? null;

  if (!$email || !$expertId) {
    http_response_code(400);
    echo json_encode(['error' => 'email e expert_id são obrigatórios']);
    exit;
  }

  // Cria conta EXPRESS no Brasil, pedindo apenas "transfers"
  $account = \Stripe\Account::create([
    'type' => 'express',
    'country' => 'BR',
    'email' => $email,
    'capabilities' => [
      'transfers' => ['requested' => true],
    ],
    'metadata' => [
      'expert_id' => (string)$expertId,
    ],
  ]);

  echo json_encode(['success' => true, 'account_id' => $account->id]);
} catch (\Stripe\Exception\ApiErrorException $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
