<?php
// public/split/create_account_session.php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

try {
  \Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

  $payload   = json_decode(file_get_contents('php://input'), true) ?: [];
  $email     = $payload['email'] ?? null;       // email do expert
  $accountId = $payload['account_id'] ?? null;  // opcional: reutilizar acct_...
  $country   = $payload['country'] ?? 'BR';

  if (!$email && !$accountId) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe email (para criar) ou account_id (para reutilizar).']);
    exit;
  }

  // 1) Cria a Connected Account EXPRESS se não veio account_id
  if (!$accountId) {
    $account = \Stripe\Account::create([
      'type'       => 'express',
      'country'    => $country,
      'email'      => $email,
      'capabilities' => [
        'card_payments' => ['requested' => true],
        'transfers'     => ['requested' => true],
      ],
      'metadata' => [
        'source' => 'stripe-php-poc',
      ],
    ]);
    $accountId = $account->id;
  }

  // 2) Cria a Account Session com o componente "account_onboarding"
  //    Você pode habilitar outros componentes (payments, payouts, etc) se quiser
  $accountSession = \Stripe\AccountSession::create([
    'account' => $accountId,
    'components' => [
      'account_onboarding' => ['enabled' => true],
      // 'payments' => ['enabled' => true], // opcional
    ],
  ]);

  echo json_encode([
    'success'       => true,
    'account_id'    => $accountId,
    'client_secret' => $accountSession->client_secret,
  ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $t) {
  http_response_code(500);
  echo json_encode(['error' => $t->getMessage()]);
}
