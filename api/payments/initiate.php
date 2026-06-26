<?php
/**
 * api/payments/initiate.php
 * Initiate a payment request from the parent portal.
 * This creates a payment transaction and returns payment instructions.
 */
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// Must be logged in
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$pdo = get_db_connection();
$userId = current_user_id();
$role = current_role();

// Only parents can initiate payments via this endpoint
if ($role !== 'parent') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only parents can initiate payments.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$studentId = (int) ($input['student_id'] ?? 0);
$invoiceId = (int) ($input['invoice_id'] ?? 0);
$amount = (float) ($input['amount'] ?? 0);
$paymentMethod = $input['payment_method'] ?? 'online_gateway';
$phoneNumber = trim($input['phone_number'] ?? '');

// --- Validation ---
if ($studentId <= 0 || $invoiceId <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment parameters.']);
    exit;
}

// Verify this parent owns the student
require_once __DIR__ . '/../../includes/fee_functions.php';
$verifiedStudent = verify_guardian_owns_student($pdo, $userId, $studentId);
if (!$verifiedStudent) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this student.']);
    exit;
}

// Verify invoice belongs to this student and is valid
$stmt = $pdo->prepare(
    "SELECT invoice_id, total_amount, amount_paid, balance, status 
     FROM invoices WHERE invoice_id = :iid AND student_id = :sid AND status IN ('unpaid', 'partial', 'overdue')"
);
$stmt->execute(['iid' => $invoiceId, 'sid' => $studentId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invoice not found or already paid.']);
    exit;
}

// Amount cannot exceed balance
if ($amount > $invoice['balance']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Amount exceeds invoice balance.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Create payment transaction
    $stmt = $pdo->prepare(
        'INSERT INTO payment_transactions (invoice_id, student_id, amount, payment_method, status, initiated_by, phone_number)
         VALUES (:iid, :sid, :amt, :method, :status, :uid, :phone)'
    );
    $stmt->execute([
        'iid' => $invoiceId, 'sid' => $studentId, 'amt' => $amount,
        'method' => $paymentMethod, 'status' => 'pending',
        'uid' => $userId, 'phone' => $phoneNumber ?: null,
    ]);
    $transactionId = (int) $pdo->lastInsertId();

    $pdo->commit();

    // Get active payment gateway
    $gateway = $pdo->query("SELECT * FROM payment_gateways WHERE is_active = 1 LIMIT 1")->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'Payment initiated successfully.',
        'data' => [
            'transaction_id' => $transactionId,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'status' => 'pending',
            'payment_method' => $paymentMethod,
            'gateway' => $gateway ? $gateway['gateway_name'] : null,
            'instructions' => $gateway 
                ? "Please send {$amount} TZS via {$gateway['gateway_name']} using reference: INV-" . str_pad($invoiceId, 6, '0', STR_PAD_LEFT)
                : 'No active payment gateway. Please contact the school bursar for payment instructions.',
        ],
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[ASMS] Payment initiation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to initiate payment.']);
}