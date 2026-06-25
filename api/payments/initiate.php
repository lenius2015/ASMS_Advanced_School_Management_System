<?php
/**
 * api/payments/initiate.php
 * API Endpoint: Initiate a payment via gateway (GePG, M-Pesa, etc.)
 * POST /api/payments/initiate.php
 * 
 * Request body:
 * {
 *   "invoice_id": 123,
 *   "gateway": "gepg|mpesa|tigo_pesa|airtel_money|halopesa|bank",
 *   "amount": 500000,
 *   "phone": "2557XXXXXXXXX",
 *   "callback_url": "https://school.example.com/api/payments/callback.php"
 * }
 * 
 * Response:
 * {
 *   "success": true,
 *   "message": "Payment initiated",
 *   "transaction_id": "GEPG2026062512345678",
 *   "control_number": "SCH2026INV00001",
 *   "redirect_url": "https://gepg.example.com/pay/..."
 * }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/fee_functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$pdo = get_db_connection();

// Parse JSON body
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$invoiceId = (int) ($body['invoice_id'] ?? 0);
$gatewayName = $body['gateway'] ?? '';
$amount = (float) ($body['amount'] ?? 0);
$phone = $body['phone'] ?? '';
$callbackUrl = $body['callback_url'] ?? app_url('/api/payments/callback.php');

// Validate gateway
$allowedGateways = ['gepg', 'mpesa', 'tigo_pesa', 'airtel_money', 'halopesa', 'bank'];
if (!in_array($gatewayName, $allowedGateways)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid gateway. Must be one of: ' . implode(', ', $allowedGateways)]);
    exit;
}

// Validate invoice
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = :id AND status IN (:s1, :s2, :s3)');
$stmt->execute(['id' => $invoiceId, 's1' => 'pending', 's2' => 'partial', 's3' => 'overdue']);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Invoice not found or already paid.']);
    exit;
}

if ($amount <= 0) {
    $amount = (float) $invoice['balance'];
}

if ($amount > (float) $invoice['balance']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Amount exceeds outstanding balance.']);
    exit;
}

// Get payment gateway
$gateway = get_payment_gateway($gatewayName);
if (!$gateway) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Payment gateway not configured.']);
    exit;
}

// Initiate payment through gateway
$paymentData = [
    'invoice_id'     => $invoiceId,
    'invoice_no'     => $invoice['invoice_no'],
    'control_number' => $invoice['control_number'],
    'amount'         => $amount,
    'phone'          => $phone,
    'callback_url'   => $callbackUrl,
    'merchant_code'  => 'SCH001',
    'description'    => 'School fees payment for invoice ' . $invoice['invoice_no'],
];

$gatewayResponse = $gateway->initiatePayment($paymentData);

// Log the API call
log_payment_api_call(
    $pdo,
    null,
    $invoiceId,
    $gatewayName,
    json_encode($paymentData),
    json_encode($gatewayResponse),
    $gatewayResponse['success'] ? 'pending' : 'failed',
    $gatewayResponse['transaction_id'] ?? '',
    $gatewayResponse['transaction_id'] ?? ''
);

echo json_encode([
    'success'        => $gatewayResponse['success'],
    'message'        => $gatewayResponse['message'],
    'transaction_id' => $gatewayResponse['transaction_id'] ?? null,
    'control_number' => $invoice['control_number'],
    'redirect_url'   => $gatewayResponse['redirect_url'] ?? null,
]);