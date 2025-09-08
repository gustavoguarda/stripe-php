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

function redact_array(array $data): array {
    $json = json_encode($data);
    if ($json === false) return [];
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

try {
    \Stripe\Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $accountId = $payload['account_id'] ?? null;
    $amountBrl = $payload['amount_brl'] ?? null;
    $orderRef = $payload['order_ref'] ?? null;

    if (!$accountId || !$amountBrl) {
        return errorResponse('account_id e amount_brl são obrigatórios', 400);
    }

    // Valor em centavos
    $amount = (int) round(((float)$amountBrl) * 100);

    if ($amount < 50) {
        return errorResponse('Valor mínimo é R$ 0,50', 400);
    }

    // Para o Brasil, precisamos simular um charge existente
    // Vamos criar um PaymentIntent de teste e depois fazer a transferência
    
    // 1. Criar Customer de teste
    $customer = \Stripe\Customer::create([
        'email' => 'test@stripe-connect-poc.com',
        'metadata' => [
            'source' => 'stripe-php-poc-simulation',
        ],
    ]);

    // 2. Criar PaymentIntent de teste (sem processar)
    $paymentIntent = \Stripe\PaymentIntent::create([
        'amount' => $amount,
        'currency' => 'brl',
        'customer' => $customer->id,
        'automatic_payment_methods' => [
            'enabled' => true,
            'allow_redirects' => 'never',
        ],
        'metadata' => [
            'source' => 'stripe-php-poc-simulation',
            'order_ref' => $orderRef ?? '',
            'connected_account' => $accountId,
            'simulation' => 'true',
        ],
    ], [
        'idempotency_key' => 'pi-sim:' . sha1($accountId . ':' . $amount . ':' . ($orderRef ?? '') . ':' . time()),
    ]);

    // 3. Criar PaymentMethod usando token de teste
    $paymentMethod = \Stripe\PaymentMethod::create([
        'type' => 'card',
        'card' => [
            'token' => 'tok_visa', // Token de teste do Stripe
        ],
    ]);

    // 4. Anexar PaymentMethod ao Customer
    $paymentMethod->attach(['customer' => $customer->id]);

    // 5. Atualizar PaymentIntent com PaymentMethod
    $paymentIntent = \Stripe\PaymentIntent::update($paymentIntent->id, [
        'payment_method' => $paymentMethod->id,
    ]);

    // 6. Confirmar PaymentIntent
    $paymentIntent = $paymentIntent->confirm();

    if ($paymentIntent->status !== 'succeeded') {
        return errorResponse('Falha ao processar pagamento simulado', 400, [
            'payment_intent_id' => $paymentIntent->id,
            'status' => $paymentIntent->status,
        ]);
    }

    // 7. Obter o charge ID do PaymentIntent
    $chargeId = $paymentIntent->latest_charge;
    if (!$chargeId) {
        return errorResponse('Charge não encontrado no PaymentIntent', 400);
    }

    // 8. Criar transferência usando source_transaction
    $transfer = \Stripe\Transfer::create([
        'amount' => $amount,
        'currency' => 'brl',
        'destination' => $accountId,
        'source_transaction' => $chargeId,
        'transfer_group' => $orderRef,
        'metadata' => [
            'source' => 'stripe-php-poc-simulation',
            'payment_intent_id' => $paymentIntent->id,
            'charge_id' => $chargeId,
        ],
    ], [
        'idempotency_key' => 'tr-sim:' . sha1($chargeId . ':' . $accountId . ':' . $amount . ':' . time()),
    ]);

    // Audit logs
    json_store_append_locked(
        __DIR__ . '/../../data/transactions.json',
        [
            'id' => $paymentIntent->id,
            'type' => 'payment_intent',
            'status' => 'simulated',
            'amount' => $amount,
            'currency' => 'brl',
            'connected_account' => $accountId,
            'application_fee_amount' => null,
            'request' => redact_array(['account_id' => $accountId, 'amount_brl' => $amountBrl, 'simulation' => true]),
            'response' => redact_array(['payment_intent' => ['id' => $paymentIntent->id, 'status' => 'simulated']]),
            'webhook_type' => null,
            'created_at' => date('c'),
        ]
    );

    json_store_append_locked(
        __DIR__ . '/../../data/transactions.json',
        [
            'id' => $chargeId,
            'type' => 'charge',
            'status' => 'succeeded',
            'amount' => $amount,
            'currency' => 'brl',
            'connected_account' => $accountId,
            'application_fee_amount' => null,
            'request' => redact_array(['amount' => $amount, 'simulation' => true]),
            'response' => redact_array(['charge' => ['id' => $chargeId, 'status' => 'succeeded']]),
            'webhook_type' => null,
            'created_at' => date('c'),
        ]
    );

    json_store_append_locked(
        __DIR__ . '/../../data/transactions.json',
        [
            'id' => $transfer->id,
            'type' => 'transfer',
            'status' => 'created',
            'amount' => $amount,
            'currency' => 'brl',
            'connected_account' => $accountId,
            'application_fee_amount' => null,
            'request' => redact_array(['account_id' => $accountId, 'amount_brl' => $amountBrl, 'source_transaction' => $chargeId]),
            'response' => redact_array(['transfer' => ['id' => $transfer->id, 'amount' => $amount]]),
            'webhook_type' => null,
            'created_at' => date('c'),
        ]
    );

    return successResponse([
        'simulation' => true,
        'customer_id' => $customer->id,
        'payment_intent_id' => $paymentIntent->id,
        'charge_id' => $chargeId,
        'transfer_id' => $transfer->id,
        'amount' => $amount,
        'currency' => 'brl',
        'destination' => $accountId,
        'status' => $transfer->status,
        'note' => 'Esta é uma simulação usando tokens de teste do Stripe',
    ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    return errorResponse('Stripe API error', 400, ['message' => $e->getMessage()]);
} catch (\Throwable $t) {
    return errorResponse('Internal server error', 500, ['message' => $t->getMessage()]);
}
