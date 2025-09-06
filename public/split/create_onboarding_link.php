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

  // URLs de retorno/refresh da sua POC
  $base = rtrim((getenv('APP_URL') ?: 'http://localhost:4242'), '/');

  $link = \Stripe\AccountLink::create([
    'account' => $accountId,
    'type' => 'account_onboarding',
    'refresh_url' => $base . '/onboarding_refresh.html',
    'return_url'  => $base . '/onboarding_return.html',
  ]);

  echo json_encode(['success' => true, 'onboarding_url' => $link->url]);
} catch (\Stripe\Exception\ApiErrorException $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
