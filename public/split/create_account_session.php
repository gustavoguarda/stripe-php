<?php
declare(strict_types=1);
// split/create_account_session.php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Utilidades locais mínimas (audit + respostas padronizadas)
function redact_array(array $data): array {
  $json = json_encode($data);
  if ($json === false) return [];
  // remove chaves / segredos
  $json = preg_replace('/sk_[A-Za-z0-9_]+/i', 'sk_***', (string)$json);
  $json = preg_replace('/pk_[A-Za-z0-9_]+/i', 'pk_***', (string)$json);
  $json = preg_replace('/whsec_[A-Za-z0-9]+/i', 'whsec_***', (string)$json);
  $arr = json_decode((string)$json, true);
  return is_array($arr) ? $arr : [];
}

function json_store_append_locked(string $path, array $entry): void {
  $fp = fopen($path, 'c+');
  if (!$fp) return;
  if (flock($fp, LOCK_EX)) {
    $contents = stream_get_contents($fp);
    $list = $contents ? json_decode($contents, true) : [];
    if (!is_array($list)) $list = [];
    $list[] = $entry;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($list, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
  }
  fclose($fp);
}

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

  $payload   = json_decode(file_get_contents('php://input'), true) ?: [];
  $email     = $payload['email'] ?? null;       // email do expert
  $accountId = $payload['account_id'] ?? null;  // opcional: reutilizar acct_...
  $country   = $payload['country'] ?? 'BR';

  // Debug: log completo do payload
  file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - PAYLOAD: ' . json_encode($payload) . "\n", FILE_APPEND);
  file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - email=' . ($email ?? 'null') . ', account_id=' . ($accountId ?? 'null') . "\n", FILE_APPEND);

  // Se tem email, sempre criar nova conta (ignorar account_id)
  if ($email) {
    file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - ANTES - accountId=' . ($accountId ?? 'null') . "\n", FILE_APPEND);
    $accountId = null; // Forçar criação de nova conta
    file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - DEPOIS - accountId=' . ($accountId ?? 'null') . "\n", FILE_APPEND);
  }

  if (!$email && !$accountId) {
    return errorResponse('Informe email (para criar) ou account_id (para reutilizar).', 400);
  }

  // Se veio account_id, verificar se existe antes de tentar usar
  $validAccountId = null;
  if ($accountId) {
    try {
      \Stripe\Account::retrieve($accountId);
      $validAccountId = $accountId; // Account existe, pode usar
    } catch (\Stripe\Exception\InvalidRequestException $e) {
      // Account não existe - se tem email, criar nova conta; senão, erro
      if (!$email) {
        return errorResponse('Account ID não existe: ' . $accountId, 400);
      }
      // Se tem email, vamos criar nova conta (validAccountId fica null)
    }
  }

  // 1) Cria a Connected Account EXPRESS se não temos um account válido
  if (!$validAccountId) {
    file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - Criando nova conta para email: ' . $email . "\n", FILE_APPEND);
    $createParams = [
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
    ];
    file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - Chamando Stripe Account::create...' . "\n", FILE_APPEND);
    $account = \Stripe\Account::create($createParams, [
      'idempotency_key' => 'acct:create:' . sha1(strtolower((string)$email)),
    ]);
    $validAccountId = $account->id;
    file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - Conta criada: ' . $validAccountId . "\n", FILE_APPEND);

    // Audit log: criação de conta
    json_store_append_locked(
      __DIR__ . '/../../data/transactions.json',
      [
        'id' => $validAccountId,
        'type' => 'account',
        'status' => 'created',
        'amount' => null,
        'currency' => 'brl',
        'connected_account' => $validAccountId,
        'application_fee_amount' => null,
        'request' => redact_array(['email' => $email, 'country' => $country]),
        'response' => redact_array(['account' => ['id' => $validAccountId]]),
        'webhook_type' => null,
        'created_at' => date('c'),
      ]
    );
  }

  // 2) Cria a Account Session com o componente "account_onboarding"
  file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - Criando AccountSession para: ' . $validAccountId . "\n", FILE_APPEND);
  $accountSession = \Stripe\AccountSession::create([
    'account' => $validAccountId,
    'components' => [
      'account_onboarding' => ['enabled' => true],
    ],
  ]);
  file_put_contents(__DIR__ . '/../../storage/log/debug.log', date('Y-m-d H:i:s') . ' - AccountSession criado com sucesso' . "\n", FILE_APPEND);

  // Audit log: session criada
  json_store_append_locked(
    __DIR__ . '/../../data/transactions.json',
    [
      'id' => $validAccountId,
      'type' => 'account',
      'status' => 'pending',
      'amount' => null,
      'currency' => 'brl',
      'connected_account' => $validAccountId,
      'application_fee_amount' => null,
      'request' => redact_array(['action' => 'account_session']),
      'response' => redact_array(['client_secret' => 'as_***']),
      'webhook_type' => null,
      'created_at' => date('c'),
    ]
  );

  return successResponse([
    'account_id' => $validAccountId,
    'client_secret' => $accountSession->client_secret,
  ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
  return errorResponse('Stripe API error', 400, ['message' => $e->getMessage()]);
} catch (\Throwable $t) {
  return errorResponse('Internal server error', 500, ['message' => $t->getMessage()]);
}
