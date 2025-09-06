<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

try {
  $key = getenv('STRIPE_SECRET_KEY');
  if (!$key) throw new Exception('STRIPE_SECRET_KEY não definido');
  \Stripe\Stripe::setApiKey($key);

  $p = json_decode(file_get_contents('php://input'), true) ?: [];

  // Bloqueia uso indevido: este endpoint é apenas para CRIAR, não atualizar.
  if (!empty($p['account_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Não envie account_id aqui. Este endpoint só cria novas contas.']);
    exit;
  }

  $email        = $p['email']        ?? null;
  $country      = strtoupper($p['country'] ?? 'BR');
  $businessType = $p['business_type'] ?? 'individual'; // "individual" | "company"

  if (!$email) {
    http_response_code(400);
    echo json_encode(['error' => 'email é obrigatório']);
    exit;
  }

  // Monta payload base
  $payload = [
    'type'    => 'express',
    'country' => $country,
    'email'   => $email,
    'capabilities' => [
      'card_payments' => ['requested' => true],
      'transfers'     => ['requested' => true],
    ],
    'business_type' => $businessType,
    'metadata' => ['source' => 'stripe-php-poc'],
  ];

  // business_profile (recomendado)
  if (!empty($p['business_profile'])) {
    $payload['business_profile'] = array_filter([
      'product_description' => $p['business_profile']['product_description'] ?? null,
      'url'                 => $p['business_profile']['url'] ?? null,
      // 'mcc'               => $p['business_profile']['mcc'] ?? null, // opcional
    ]);
  }

  // Pré-preenchimento PF (individual)
  if ($businessType === 'individual' && !empty($p['individual'])) {
    $payload['individual'] = array_filter([
      'first_name' => $p['individual']['first_name'] ?? null,
      'last_name'  => $p['individual']['last_name'] ?? null,
      'email'      => $p['individual']['email'] ?? $email,
      'id_number'  => $p['individual']['id_number'] ?? null, // CPF
      'dob'        => $p['individual']['dob'] ?? null,       // ['day'=>1,'month'=>1,'year'=>1990]
      'address'    => $p['individual']['address'] ?? null,   // ['line1','city','state','postal_code','country'=>'BR']
      // Você pode enviar phone, etc., se tiver
    ]);
  }

  // Pré-preenchimento PJ (company)
  if ($businessType === 'company' && !empty($p['company'])) {
    $payload['company'] = array_filter([
      'name'    => $p['company']['name'] ?? null,   // razão social
      'tax_id'  => $p['company']['tax_id'] ?? null, // CNPJ
      'address' => $p['company']['address'] ?? null,
      // Para PJ, normalmente você também precisa do "representante" (controller) via persons API;
      // O Embedded Onboarding pedirá o que faltar.
    ]);
  }

  // (Opcional) já deixar payout manual, se você pretende disparar payouts via API
  if (!empty($p['payout_schedule'])) {
    $payload['settings']['payouts']['schedule'] = array_filter([
      'interval'       => $p['payout_schedule']['interval'] ?? null,        // manual|daily|weekly|monthly
      'weekly_anchor'  => $p['payout_schedule']['weekly_anchor'] ?? null,   // se weekly
      'monthly_anchor' => $p['payout_schedule']['monthly_anchor'] ?? null,  // se monthly (1..31)
      'delay_days'     => $p['payout_schedule']['delay_days'] ?? null,      // opcional
    ]);
  }

  // Cria a Connected Account já com os dados
  $account = \Stripe\Account::create($payload);

  // Em seguida, crie uma Account Session para abrir o Embedded (se quiser já voltar isso)
  $session = \Stripe\AccountSession::create([
    'account' => $account->id,
    'components' => [
      'account_onboarding' => ['enabled' => true],
    ],
  ]);

  echo json_encode([
    'success'       => true,
    'account_id'    => $account->id,
    'created'       => $account,
    'client_secret' => $session->client_secret, // já devolvo pra você montar o componente
  ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $t) {
  http_response_code(500);
  echo json_encode(['error' => $t->getMessage()]);
}
