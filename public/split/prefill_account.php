<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

try {
  \Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

  $p = json_decode(file_get_contents('php://input'), true) ?: [];
  $accountId = $p['account_id'] ?? null;
  if (!$accountId) { http_response_code(400); echo json_encode(['error'=>'account_id é obrigatório']); exit; }

  // Campos opcionais que ajudam a “desafogar” o KYC
  $update = [];

  // Perfil do negócio (aconselhável)
  if (!empty($p['business_profile'])) {
    $update['business_profile'] = array_filter([
      'product_description' => $p['business_profile']['product_description'] ?? null,
      'url'                 => $p['business_profile']['url'] ?? null,
      'mcc'                 => $p['business_profile']['mcc'] ?? null, // opcional
    ]);
  }

  // Se pessoa física:
  if (!empty($p['individual'])) {
    $update['individual'] = array_filter([
      'first_name' => $p['individual']['first_name'] ?? null,
      'last_name'  => $p['individual']['last_name'] ?? null,
      'email'      => $p['individual']['email'] ?? null,
      'id_number'  => $p['individual']['id_number'] ?? null, // CPF
      'dob'        => $p['individual']['dob'] ?? null,       // ['day'=>1,'month'=>1,'year'=>1990]
      'address'    => $p['individual']['address'] ?? null,   // ['line1'=>...,'city'=>...,'postal_code'=>...,'state'=>...,'country'=>'BR']
    ]);
  }

  // Se pessoa jurídica:
  if (!empty($p['company'])) {
    $update['company'] = array_filter([
      'name'    => $p['company']['name'] ?? null,   // razão social
      'tax_id'  => $p['company']['tax_id'] ?? null, // CNPJ
      'address' => $p['company']['address'] ?? null,
    ]);
  }

  // Atualiza a conta
  $acc = \Stripe\Account::update($accountId, $update);

  echo json_encode(['success'=>true, 'account'=>$acc]);
} catch (\Stripe\Exception\ApiErrorException $e) {
  http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
} catch (\Throwable $t) {
  http_response_code(500); echo json_encode(['error'=>$t->getMessage()]);
}
