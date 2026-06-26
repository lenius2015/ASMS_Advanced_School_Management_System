<?php
/**
 * api/payments/callback.php
 * Payment gateway callback receiver.
 * External payment gateways send transaction status updates here.
 * Supports: SELCOM, NMB, CRDB, M-Pesa, Airtel Money
 */
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

$pdo = get_db_connection();
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// Log incoming callback for debugging
error_log('[ASMS] Payment callback received: ' . json_encode($input));

// Verify gateway - basic security check
$gatewayName = $input['gateway'] ?? $_GET['gateway'] ?? '';
$allowedGateways = ['selcom', 'nmb', 'crdb', 'mpesa', 'airtel_money'];

if (!$gatewayName || !in_array($gatewayName, $allowedGateways)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid gateway.']);
    exit;
}

// Extract transaction details (different gateways send different formats)
$gatewayTransactionId = $input['transaction_id'] ?? $input['trans_id'] ?? '';
$referenceNo = $input['reference'] ?? $input['ref'] ?? $input['msisdn'] ?? '';
$amount = (float) ($input['amount'] ?? $input['amt'] ?? 0);
$status = $input['status'] ?? $input['result'] ?? 'completed';
$controlNumber = $input['control_number'] ?? $input['bill_ref'] ?? '';
$phoneNumber = $input['phone'] ?? $input['msisdn'] ?? '';

// Map gateway status to our system status
$statusMap = [
    'completed' => 'completed',
    'success' => 'completed',
    'successful' => 'completed',
    'paid' => 'completed',
    'failed' => 'failed',
    'failure' => 'failed',
    'cancelled' => 'failed',
    'expired' => 'expired',
    'refunded' => 'refunded',
    'pending' => 'pending',
];

$ourStatus = $statusMap[strtolower($status)] ?? 'pending';

try {
    $pdo->beginTransaction();

    // Find matching transaction by reference or control number
    $transaction = null;
    if ($controlNumber) {
        $stmt = $pdo->prepare("SELECT * FROM control_numbers WHERE control_number = :cn AND status = 'active' LIMIT 1");
        $stmt->execute(['cn' => $controlNumber]);
        $cnRecord = $stmt->fetch();
        
        if ($cnRecord) {
            $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE transaction_id = :tid");
            $stmt->execute(['tid' => $cnRecord['payment_transaction_id']]);
            $transaction = $stmt->fetch();
            
            if (!$transaction) {
                // Create a transaction from the control number
                $stmt = $pdo->prepare(
                    "INSERT INTO payment_transactions (invoice_id, student_id, amount, payment_method, gateway_name, 
                     gateway_transaction_id, control_number, reference_no, phone_number, status, callback_received_at, callback_data)
                     VALUES (:iid, :sid, :amt, :method, :gw, :gtid, :cn, :ref, :phone, :status, NOW(), :data)"
                );
                $stmt->execute([
                    'iid' => $cnRecord['invoice_id'], 'sid' => $cnRecord['student_id'],
                    'amt' => $amount ?: $cnRecord['amount'], 'method' => 'online_gateway',
                    'gw' => $gatewayName, 'gtid' => $gatewayTransactionId,
                    'cn' => $controlNumber, 'ref' => $referenceNo, 'phone' => $phoneNumber,
                    'status' => $ourStatus, 'data' => json_encode($input),
                ]);
                $transactionId = (int) $pdo->lastInsertId();
                
                // Update control number
                $pdo->prepare("UPDATE control_numbers SET status = :s, payment_transaction_id = :tid WHERE control_number_id = :cid")
                    ->execute(['s' => $ourStatus === 'completed' ? 'paid' : 'expired', 'tid' => $transactionId, 'cid' => $cnRecord['control_number_id']]);
            }
        }
    }

    if (!$transaction && $referenceNo) {
        $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE reference_no = :ref LIMIT 1");
        $stmt->execute(['ref' => $referenceNo]);
        $transaction = $stmt->fetch();
    }

    if ($transaction) {
        // Update transaction
        $pdo->prepare(
            "UPDATE payment_transactions SET gateway_name = :gw, gateway_transaction_id = :gtid, 
             status = :status, paid_at = IF(:status = 'completed', NOW(), paid_at),
             callback_received_at = NOW(), callback_data = :data
             WHERE transaction_id = :tid"
        )->execute([
            'gw' => $gatewayName, 'gtid' => $gatewayTransactionId,
            'status' => $ourStatus, 'data' => json_encode($input), 'tid' => $transaction['transaction_id'],
        ]);

        // If completed, update the invoice
        if ($ourStatus === 'completed') {
            $payAmount = $amount ?: $transaction['amount'];
            $invoiceId = $transaction['invoice_id'];
            
            // Update invoice amounts
            $stmt = $pdo->prepare("SELECT amount_paid, total_amount, balance FROM invoices WHERE invoice_id = :iid FOR UPDATE");
            $stmt->execute(['iid' => $invoiceId]);
            $inv = $stmt->fetch();
            
            $newPaid = $inv['amount_paid'] + $payAmount;
            $newBalance = $inv['total_amount'] - $newPaid;
            $newStatus = $newBalance <= 0 ? 'paid' : 'partial';
            
            $pdo->prepare("UPDATE invoices SET amount_paid = :paid, balance = :bal, status = :s WHERE invoice_id = :iid")
                ->execute(['paid' => $newPaid, 'bal' => $newBalance, 's' => $newStatus, 'iid' => $invoiceId]);
            
            // Check if there's a corresponding payment record, if not create one
            $stmt = $pdo->prepare("SELECT payment_id FROM payments WHERE invoice_id = :iid AND reference_no = :ref LIMIT 1");
            $stmt->execute(['iid' => $invoiceId, 'ref' => $gatewayTransactionId]);
            if (!$stmt->fetch()) {
                $pdo->prepare(
                    "INSERT INTO payments (invoice_id, student_id, amount, payment_method, reference_no, payment_date, notes)
                     VALUES (:iid, :sid, :amt, :method, :ref, CURDATE(), :notes)"
                )->execute([
                    'iid' => $invoiceId, 'sid' => $transaction['student_id'],
                    'amt' => $payAmount, 'method' => 'online_gateway',
                    'ref' => $gatewayTransactionId, 'notes' => "Online payment via {$gatewayName}",
                ]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Callback processed.']);
    } else {
        $pdo->rollBack();
        error_log('[ASMS] Payment callback: No matching transaction found.');
        // Still acknowledge to avoid retries
        echo json_encode(['success' => true, 'message' => 'Received (no match).']);
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[ASMS] Payment callback error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error processing callback.']);
}