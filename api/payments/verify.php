<?php
/**
 * api/payments/verify.php
 * Payment status verification.
 * Check the status of a payment transaction.
 */
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$pdo = get_db_connection();
$transactionId = (int) ($_GET['transaction_id'] ?? 0);
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);

if ($transactionId <= 0 && $invoiceId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Provide transaction_id or invoice_id.']);
    exit;
}

try {
    if ($transactionId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE transaction_id = :tid");
        $stmt->execute(['tid' => $transactionId]);
        $transaction = $stmt->fetch();
        
        if ($transaction) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'transaction_id' => $transaction['transaction_id'],
                    'amount' => (float) $transaction['amount'],
                    'status' => $transaction['status'],
                    'gateway' => $transaction['gateway_name'],
                    'paid_at' => $transaction['paid_at'],
                    'created_at' => $transaction['created_at'],
                ],
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Transaction not found.']);
        }
    } elseif ($invoiceId > 0) {
        $stmt = $pdo->prepare(
            "SELECT * FROM payment_transactions WHERE invoice_id = :iid ORDER BY created_at DESC"
        );
        $stmt->execute(['iid' => $invoiceId]);
        $transactions = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $transactions,
        ]);
    }
} catch (Throwable $e) {
    error_log('[ASMS] Payment verification error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Verification failed.']);
}