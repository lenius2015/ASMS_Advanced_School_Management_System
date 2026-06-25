<?php
/**
 * api/payments/verify.php
 * API Endpoint: Verify a payment transaction status.
 * GET /api/payments/verify.php?transaction_id=XXXXX
 * 
 * Response:
 * {
 *   "success": true,
 *   "status": "completed|pending|failed",
 *   "amount": 500000,
 *   "reference": "REF123",
 *   "message": "Payment verified successfully"
 * }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/fee_functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$pdo = get_db_connection();
$transactionId = $_GET['transaction_id'] ?? '';
$gatewayName = $_GET['gateway'] ?? 'gepg';

if (empty($transactionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'transaction_id is required.']);
    exit;
}

// Look up the API log
$stmt = $pdo->prepare("SELECT * FROM payment_api_logs WHERE transaction_id = :txn ORDER BY created_at DESC LIMIT 1");
$stmt->execute(['txn' => $transactionId]);
$log = $stmt->fetch();

$gateway = get_payment_gateway($gatewayName);
$result = $gateway ? $gateway->verifyPayment($transactionId) : ['success' => false, 'status' => 'unknown', 'amount' => 0, 'reference' => ''];

echo json_encode([
    'success'   => $result['success'],
    'status'    => $result['status'] ?? ($log ? $log['status'] : 'unknown'),
    'amount'    => $log ? ($log['amount'] ?? 0) : 0,
    'reference' => $log ? $log['reference_no'] : '',
    'message'   => $result['message'] ?? 'Transaction verified',
]);