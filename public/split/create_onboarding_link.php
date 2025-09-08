<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function successResponse(array $data): void {
  http_response_code(200);
  echo json_encode([
    'success' => true,
    'data' => $data,
    'timestamp' => date('c'),
  ], JSON_UNESCAPED_UNICODE);
}

function errorResponse(string $message, int $code = 400, array $details = null): void {
  http_response_code($code);
  echo json_encode([
    'success' => false,
    'error' => $message,
    'details' => $details,
    'timestamp' => date('c'),
  ], JSON_UNESCAPED_UNICODE);
}

try {
  \Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

  $payload = json_decode(file_get_contents('php://input'), true) ?: [];
  $accountId = $payload['account_id'] ?? null;

  if (!$accountId) {
    return errorResponse('account_id é obrigatório', 400);
  }

  $base = rtrim((getenv('APP_URL') ?: 'http://localhost:4242'), '/');

  $link = \Stripe\AccountLink::create([
    'account' => $accountId,
    'type' => 'account_onboarding',
    'refresh_url' => $base . '/split/embedded_onboarding.html?status=refresh&account=' . urlencode($accountId),
    'return_url'  => $base . '/split/embedded_onboarding.html?status=return&account=' . urlencode($accountId),
  ]);

  return successResponse(['url' => $link->url]);
} catch (\Stripe\Exception\ApiErrorException $e) {
  return errorResponse('Stripe API error', 400, ['message' => $e->getMessage()]);
}
