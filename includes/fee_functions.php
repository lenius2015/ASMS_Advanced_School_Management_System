<?php
/**
 * includes/fee_functions.php
 * Core business logic for the School Fee Management Module.
 * 
 * Provides:
 * - Control Number generation (SCH2026INV00001)
 * - Invoice status management
 * - Student fee account management
 * - Dashboard KPI calculations
 * - Report data aggregation
 * - PDF/Excel export helpers
 * - Payment gateway interface stubs
 */

// =====================================================================
// CONTROL NUMBER GENERATION
// =====================================================================

/**
 * Generate a unique Control Number for an invoice.
 * Format: SCH{year}INV{5-digit-sequence}
 * Example: SCH2026INV00001
 *
 * Uses row-level locking (FOR UPDATE) to prevent duplicates under concurrency.
 *
 * @param PDO    $pdo  Database connection
 * @param string $year 4-digit year (e.g. '2026')
 * @return string The generated control number
 */
function generate_control_number(PDO $pdo, string $year = ''): string
{
    if (empty($year)) {
        $year = date('Y');
    }

    try {
        $pdo->beginTransaction();

        // Ensure sequence row exists
        $stmt = $pdo->prepare(
            'INSERT INTO control_number_sequences (year_prefix, last_sequence) 
             VALUES (:year, 0) 
             ON DUPLICATE KEY UPDATE last_sequence = last_sequence'
        );
        $stmt->execute(['year' => $year]);

        // Lock and get next sequence
        $stmt = $pdo->prepare(
            'SELECT last_sequence + 1 AS next_seq 
             FROM control_number_sequences 
             WHERE year_prefix = :year 
             FOR UPDATE'
        );
        $stmt->execute(['year' => $year]);
        $row = $stmt->fetch();
        $nextSeq = (int) ($row['next_seq'] ?? 1);

        // Update sequence
        $stmt = $pdo->prepare(
            'UPDATE control_number_sequences SET last_sequence = :seq WHERE year_prefix = :year'
        );
        $stmt->execute(['seq' => $nextSeq, 'year' => $year]);

        $pdo->commit();

        return sprintf('SCH%sINV%05d', $year, $nextSeq);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ASMS] generate_control_number failed: ' . $e->getMessage());
        // Fallback: use timestamp-based unique number
        return 'SCH' . $year . 'INV' . date('His') . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
    }
}

// =====================================================================
// INVOICE STATUS MANAGEMENT
// =====================================================================

/**
 * Recalculate an invoice's paid amount, balance, and status.
 * Called after payment operations.
 *
 * @param PDO $pdo        Database connection
 * @param int $invoiceId  The invoice ID
 * @return array The updated invoice data
 */
function recalculate_invoice(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = :id');
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        return [];
    }

    // Sum all payments for this invoice
    $payStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE invoice_id = :id');
    $payStmt->execute(['id' => $invoiceId]);
    $totalPaid = (float) $payStmt->fetch()['total_paid'];

    $totalAmount = (float) $invoice['total_amount'];
    $balance = $totalAmount - $totalPaid;

    // Determine status
    if ($balance <= 0) {
        $newStatus = 'paid';
    } elseif ($totalPaid > 0) {
        $newStatus = 'partial';
    } else {
        $newStatus = 'pending';
    }

    // Check overdue
    if ($newStatus !== 'paid' && !empty($invoice['due_date']) && $invoice['due_date'] < date('Y-m-d')) {
        $newStatus = 'overdue';
    }

    $updateStmt = $pdo->prepare(
        'UPDATE invoices SET amount_paid = :paid, balance = GREATEST(:bal, 0), status = :status WHERE invoice_id = :id'
    );
    $updateStmt->execute([
        'paid'   => $totalPaid,
        'bal'    => max($balance, 0),
        'status' => $newStatus,
        'id'     => $invoiceId,
    ]);

    // Sync student fee account
    sync_student_fee_account($pdo, (int) $invoice['student_id']);

    return array_merge($invoice, [
        'amount_paid' => $totalPaid,
        'balance'     => max($balance, 0),
        'status'      => $newStatus,
    ]);
}

/**
 * Cancel an invoice.
 *
 * @param PDO    $pdo
 * @param int    $invoiceId
 * @param int    $cancelledBy User ID
 * @param string $reason      Cancellation reason
 * @return bool
 */
function cancel_invoice(PDO $pdo, int $invoiceId, int $cancelledBy, string $reason = ''): bool
{
    $stmt = $pdo->prepare(
        'UPDATE invoices SET status = :status, cancel_reason = :reason, cancelled_by = :by, cancelled_at = NOW() 
         WHERE invoice_id = :id AND status IN (:s1, :s2)'
    );
    $stmt->execute([
        'status' => 'cancelled',
        'reason' => $reason ?: 'Cancelled by bursar',
        'by'     => $cancelledBy,
        'id'     => $invoiceId,
        's1'     => 'pending',
        's2'     => 'partial',
    ]);

    if ($stmt->rowCount() > 0) {
        $invStmt = $pdo->prepare('SELECT student_id FROM invoices WHERE invoice_id = :id');
        $invStmt->execute(['id' => $invoiceId]);
        $studentId = (int) $invStmt->fetchColumn();
        if ($studentId) {
            sync_student_fee_account($pdo, $studentId);
        }
        return true;
    }
    return false;
}

// =====================================================================
// STUDENT FEE ACCOUNT MANAGEMENT
// =====================================================================

/**
 * Get or create a student fee account.
 *
 * @param PDO $pdo
 * @param int $studentId
 * @return array
 */
function get_student_fee_account(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare('SELECT * FROM student_fee_accounts WHERE student_id = :id');
    $stmt->execute(['id' => $studentId]);
    $account = $stmt->fetch();

    if (!$account) {
        // Auto-create if missing
        $stmt = $pdo->prepare('INSERT INTO student_fee_accounts (student_id) VALUES (:id)');
        $stmt->execute(['id' => $studentId]);
        sync_student_fee_account($pdo, $studentId);

        $stmt->execute(['id' => $studentId]);
        $account = $stmt->fetch();
    }

    return $account ?: [
        'student_id'     => $studentId,
        'total_fees'     => 0,
        'total_paid'     => 0,
        'balance'        => 0,
        'payment_status' => 'pending',
    ];
}

/**
 * Sync the aggregated student fee account from invoices table.
 *
 * @param PDO $pdo
 * @param int $studentId
 */
function sync_student_fee_account(PDO $pdo, int $studentId): void
{
    $stmt = $pdo->prepare('CALL sync_student_fee_account(:sid)');
    $stmt->execute(['sid' => $studentId]);
}

/**
 * Get a comprehensive fee summary for a student.
 *
 * @param PDO $pdo
 * @param int $studentId
 * @return array
 */
function get_student_fee_summary(PDO $pdo, int $studentId): array
{
    $account = get_student_fee_account($pdo, $studentId);

    // Get student info
    $stmt = $pdo->prepare(
        "SELECT s.admission_no, s.class_id, u.first_name, u.last_name, 
                cl.level_name, c.stream_name, y.year_name
         FROM students s
         JOIN users u ON u.user_id = s.user_id
         LEFT JOIN classes c ON c.class_id = s.class_id
         LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         LEFT JOIN academic_years y ON y.year_id = c.year_id
         WHERE s.student_id = :sid"
    );
    $stmt->execute(['sid' => $studentId]);
    $student = $stmt->fetch() ?: [];

    // Get invoices
    $invStmt = $pdo->prepare(
        "SELECT i.*, t.term_name, y.year_name 
         FROM invoices i
         JOIN terms t ON t.term_id = i.term_id
         JOIN academic_years y ON y.year_id = t.year_id
         WHERE i.student_id = :sid
         ORDER BY i.created_at DESC"
    );
    $invStmt->execute(['sid' => $studentId]);
    $invoices = $invStmt->fetchAll();

    // Get payment history
    $payStmt = $pdo->prepare(
        "SELECT p.*, u.first_name AS recorder_fn, u.last_name AS recorder_ln
         FROM payments p
         LEFT JOIN users u ON u.user_id = p.received_by
         WHERE p.student_id = :sid
         ORDER BY p.payment_date DESC, p.created_at DESC"
    );
    $payStmt->execute(['sid' => $studentId]);
    $payments = $payStmt->fetchAll();

    return [
        'account'  => $account,
        'student'  => $student,
        'invoices' => $invoices,
        'payments' => $payments,
    ];
}

// =====================================================================
// BURSAR DASHBOARD KPIs
// =====================================================================

/**
 * Get all KPI data for the bursar dashboard.
 *
 * @param PDO $pdo
 * @param int $termId Current term ID
 * @return array
 */
function get_bursar_dashboard_kpis(PDO $pdo, int $termId): array
{
    // 1. Total Expected Revenue (all invoices for current term)
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount), 0) AS total_expected,
                COALESCE(SUM(amount_paid), 0) AS total_collected,
                COALESCE(SUM(balance), 0) AS total_outstanding,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) AS partial_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
         FROM invoices WHERE term_id = :term"
    );
    $stmt->execute(['term' => $termId]);
    $finance = $stmt->fetch();

    // 2. Today's Collections
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS today_collected,
                COUNT(*) AS today_count
         FROM payments WHERE DATE(payment_date) = CURDATE()"
    );
    $stmt->execute();
    $today = $stmt->fetch();

    // 3. This Month Collections
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS month_collected,
                COUNT(*) AS month_count
         FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) 
           AND YEAR(payment_date) = YEAR(CURDATE())"
    );
    $stmt->execute();
    $month = $stmt->fetch();

    // 4. Overdue Summary
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(balance), 0) AS overdue_amount,
                COUNT(*) AS overdue_count
         FROM invoices 
         WHERE status = 'overdue' AND term_id = :term"
    );
    $stmt->execute(['term' => $termId]);
    $overdue = $stmt->fetch();

    // 5. Collection Rate
    $expected = (float) $finance['total_expected'];
    $collected = (float) $finance['total_collected'];
    $collectionRate = $expected > 0 ? round(($collected / $expected) * 100, 1) : 0;

    // 6. Total Students Billed
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) AS c FROM invoices WHERE term_id = :term");
    $stmt->execute(['term' => $termId]);
    $billedStudents = (int) $stmt->fetch()['c'];

    return [
        'total_expected'    => (float) $finance['total_expected'],
        'total_collected'   => (float) $finance['total_collected'],
        'total_outstanding' => (float) $finance['total_outstanding'],
        'collection_rate'   => $collectionRate,
        'billed_students'   => $billedStudents,
        'paid_count'        => (int) $finance['paid_count'],
        'partial_count'     => (int) $finance['partial_count'],
        'pending_count'     => (int) $finance['pending_count'],
        'overdue_count'     => (int) $finance['overdue_count'],
        'cancelled_count'   => (int) $finance['cancelled_count'],
        'today_collected'   => (float) $today['today_collected'],
        'today_count'       => (int) $today['today_count'],
        'month_collected'   => (float) $month['month_collected'],
        'month_count'       => (int) $month['month_count'],
        'overdue_amount'    => (float) $overdue['overdue_amount'],
        'overdue_inv_count' => (int) $overdue['overdue_count'],
    ];
}

/**
 * Get monthly collection trend data.
 *
 * @param PDO    $pdo
 * @param int    $months Number of months to look back
 * @return array ['months' => [...], 'collected' => [...], 'counts' => [...]]
 */
function get_monthly_collection_trend(PDO $pdo, int $months = 12): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym,
                DATE_FORMAT(payment_date, '%b %Y') AS month_label,
                COALESCE(SUM(amount), 0) AS collected,
                COUNT(*) AS payment_count
         FROM payments
         WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
         GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%b %Y')
         ORDER BY MIN(payment_date)"
    );
    $stmt->execute(['months' => $months]);
    $rows = $stmt->fetchAll();

    $months = [];
    $collected = [];
    $counts = [];

    foreach ($rows as $row) {
        $months[] = $row['month_label'];
        $collected[] = (float) $row['collected'];
        $counts[] = (int) $row['payment_count'];
    }

    return [
        'months'    => $months,
        'collected' => $collected,
        'counts'    => $counts,
    ];
}

/**
 * Get outstanding balance by class level.
 *
 * @param PDO $pdo
 * @param int $termId
 * @return array
 */
function get_outstanding_by_class(PDO $pdo, int $termId): array
{
    $stmt = $pdo->prepare(
        "SELECT cl.level_name, 
                COALESCE(SUM(i.balance), 0) AS outstanding,
                COUNT(DISTINCT i.student_id) AS student_count
         FROM invoices i
         JOIN students s ON s.student_id = i.student_id
         JOIN classes c ON c.class_id = s.class_id
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE i.term_id = :term AND i.balance > 0
         GROUP BY cl.class_level_id, cl.level_name
         ORDER BY cl.sort_order"
    );
    $stmt->execute(['term' => $termId]);
    return $stmt->fetchAll();
}

/**
 * Get top defaulters (students with highest outstanding balances).
 *
 * @param PDO $pdo
 * @param int $termId
 * @param int $limit
 * @return array
 */
function get_top_defaulters(PDO $pdo, int $termId, int $limit = 10): array
{
    $stmt = $pdo->prepare(
        "SELECT s.student_id, u.first_name, u.last_name, s.admission_no,
                cl.level_name, c.stream_name,
                COALESCE(SUM(i.balance), 0) AS total_balance,
                COUNT(i.invoice_id) AS invoice_count
         FROM invoices i
         JOIN students s ON s.student_id = i.student_id
         JOIN users u ON u.user_id = s.user_id
         JOIN classes c ON c.class_id = s.class_id
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE i.term_id = :term AND i.balance > 0
         GROUP BY s.student_id
         ORDER BY total_balance DESC
         LIMIT :lim"
    );
    $stmt->bindValue('term', $termId, PDO::PARAM_INT);
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// =====================================================================
// INVOICE GENERATION
// =====================================================================

/**
 * Generate invoices for all active students in a class level for a term.
 *
 * @param PDO $pdo
 * @param int $classLevelId
 * @param int $termId
 * @return array{created:int,skipped:int,errors:string[]}
 */
function generate_bulk_invoices(PDO $pdo, int $classLevelId, int $termId): array
{
    $result = ['created' => 0, 'skipped' => 0, 'errors' => []];

    // Get fee structures for this class level and term
    $structStmt = $pdo->prepare(
        'SELECT fs.*, fc.category_name 
         FROM fee_structures fs
         JOIN fee_categories fc ON fc.fee_category_id = fs.fee_category_id
         WHERE fs.class_level_id = :cl AND fs.term_id = :term'
    );
    $structStmt->execute(['cl' => $classLevelId, 'term' => $termId]);
    $feeItems = $structStmt->fetchAll();

    if (empty($feeItems)) {
        $result['errors'][] = 'No fee structures defined for this class level and term.';
        return $result;
    }

    $totalAmount = array_sum(array_column($feeItems, 'amount'));

    // Get active students in this class level
    $studentsStmt = $pdo->prepare(
        "SELECT s.student_id FROM students s 
         JOIN classes c ON c.class_id = s.class_id
         WHERE c.class_level_id = :cl 
           AND c.year_id = (SELECT year_id FROM terms WHERE term_id = :term)
           AND s.status = 'active'"
    );
    $studentsStmt->execute(['cl' => $classLevelId, 'term' => $termId]);
    $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

    $year = date('Y');

    foreach ($studentIds as $sid) {
        // Check for existing invoice
        $exists = $pdo->prepare('SELECT invoice_id FROM invoices WHERE student_id = :sid AND term_id = :term');
        $exists->execute(['sid' => $sid, 'term' => $termId]);
        if ($exists->fetch()) {
            $result['skipped']++;
            continue;
        }

        try {
            $pdo->beginTransaction();

            // Generate control number
            $controlNo = generate_control_number($pdo, (string) $year);

            // Generate invoice number
            $invoiceNo = 'INV-' . $year . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            // Create invoice
            $invStmt = $pdo->prepare(
                'INSERT INTO invoices (student_id, term_id, invoice_no, control_number, total_amount, amount_paid, balance, due_date, status)
                 VALUES (:sid, :term, :no, :ctrl, :total, 0, :total2, :due, :status)'
            );
            $invStmt->execute([
                'sid'    => $sid,
                'term'   => $termId,
                'no'     => $invoiceNo,
                'ctrl'   => $controlNo,
                'total'  => $totalAmount,
                'total2' => $totalAmount,
                'due'    => (new DateTime())->modify('+30 days')->format('Y-m-d'),
                'status' => 'pending',
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            // Create invoice items
            foreach ($feeItems as $item) {
                $itemStmt = $pdo->prepare(
                    'INSERT INTO invoice_items (invoice_id, fee_category_id, description, amount) 
                     VALUES (:iid, :fc, :desc, :amt)'
                );
                $itemStmt->execute([
                    'iid'  => $invoiceId,
                    'fc'   => $item['fee_category_id'],
                    'desc' => $item['category_name'],
                    'amt'  => $item['amount'],
                ]);
            }

            $pdo->commit();
            $result['created']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['errors'][] = "Student ID {$sid}: " . $e->getMessage();
            error_log('[ASMS] generate_bulk_invoices error: ' . $e->getMessage());
        }
    }

    return $result;
}

// =====================================================================
// PAYMENT RECORDING
// =====================================================================

/**
 * Record a payment against an invoice.
 *
 * @param PDO    $pdo
 * @param int    $invoiceId
 * @param float  $amount
 * @param string $method      cash|bank|mobile_money|other
 * @param string $referenceNo
 * @param string $paymentDate Y-m-d
 * @param int    $receivedBy  User ID
 * @param string $notes
 * @return array{success:bool,message:string,payment_id:int|null}
 */
function record_payment(
    PDO $pdo,
    int $invoiceId,
    float $amount,
    string $method = 'cash',
    string $referenceNo = '',
    string $paymentDate = '',
    int $receivedBy = 0,
    string $notes = ''
): array {
    // Validate invoice
    $invStmt = $pdo->prepare('SELECT * FROM invoices WHERE invoice_id = :id');
    $invStmt->execute(['id' => $invoiceId]);
    $invoice = $invStmt->fetch();

    if (!$invoice) {
        return ['success' => false, 'message' => 'Invoice not found.', 'payment_id' => null];
    }

    if ($invoice['status'] === 'cancelled') {
        return ['success' => false, 'message' => 'Cannot record payment against a cancelled invoice.', 'payment_id' => null];
    }

    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Payment amount must be greater than zero.', 'payment_id' => null];
    }

    $balance = (float) $invoice['balance'];
    if ($amount > $balance) {
        return [
            'success' => false,
            'message' => 'Payment amount (' . number_format($amount, 2) . ') exceeds outstanding balance (' . number_format($balance, 2) . ').',
            'payment_id' => null,
        ];
    }

    if (empty($paymentDate)) {
        $paymentDate = date('Y-m-d');
    }

    if (empty($receivedBy)) {
        $receivedBy = current_user_id() ?: 0;
    }

    // Get recorded by name
    $recorderName = '';
    if ($receivedBy > 0) {
        $userStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE user_id = :uid");
        $userStmt->execute(['uid' => $receivedBy]);
        $recorderName = $userStmt->fetchColumn() ?: '';
    }

    try {
        $pdo->beginTransaction();

        // Insert payment
        $payStmt = $pdo->prepare(
            'INSERT INTO payments (invoice_id, student_id, amount, payment_method, reference_no, payment_date, received_by, recorded_by_name, notes)
             VALUES (:iid, :sid, :amt, :method, :ref, :date, :by, :byname, :notes)'
        );
        $payStmt->execute([
            'iid'     => $invoiceId,
            'sid'     => $invoice['student_id'],
            'amt'     => $amount,
            'method'  => $method,
            'ref'     => $referenceNo ?: null,
            'date'    => $paymentDate,
            'by'      => $receivedBy ?: null,
            'byname'  => $recorderName ?: null,
            'notes'   => $notes ?: null,
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        // Update invoice (the trigger will also handle this, but we do it explicitly)
        $newPaid = (float) $invoice['amount_paid'] + $amount;
        $newBalance = (float) $invoice['total_amount'] - $newPaid;
        $newStatus = $newBalance <= 0 ? 'paid' : 'partial';

        $updStmt = $pdo->prepare(
            'UPDATE invoices SET amount_paid = :paid, balance = GREATEST(:bal, 0), status = :status WHERE invoice_id = :id'
        );
        $updStmt->execute([
            'paid'   => $newPaid,
            'bal'    => max($newBalance, 0),
            'status' => $newStatus,
            'id'     => $invoiceId,
        ]);

        // Sync student fee account
        sync_student_fee_account($pdo, (int) $invoice['student_id']);

        $pdo->commit();

        // Notify student and guardians
        notify_fee_payment($pdo, (int) $invoice['student_id'], $amount, $invoice['invoice_no']);

        return [
            'success'    => true,
            'message'    => 'Payment recorded successfully.',
            'payment_id' => $paymentId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ASMS] record_payment failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to record payment. Please try again.', 'payment_id' => null];
    }
}

/**
 * Send notifications about a fee payment to student and guardians.
 *
 * @param PDO    $pdo
 * @param int    $studentId
 * @param float  $amount
 * @param string $invoiceNo
 */
function notify_fee_payment(PDO $pdo, int $studentId, float $amount, string $invoiceNo): void
{
    // Notify student
    $studentStmt = $pdo->prepare('SELECT user_id FROM students WHERE student_id = :sid AND user_id IS NOT NULL');
    $studentStmt->execute(['sid' => $studentId]);
    $studentUserId = $studentStmt->fetchColumn();

    if ($studentUserId) {
        notify_user(
            $pdo,
            (int) $studentUserId,
            'Payment Received',
            'A payment of ' . format_money($amount) . ' was recorded against invoice ' . $invoiceNo . '.',
            'fee',
            app_url('/student/fees.php')
        );
    }

    // Notify guardians
    $guardianStmt = $pdo->prepare(
        "SELECT g.user_id FROM guardians g 
         JOIN student_guardians sg ON sg.guardian_id = g.guardian_id
         WHERE sg.student_id = :sid AND g.user_id IS NOT NULL"
    );
    $guardianStmt->execute(['sid' => $studentId]);
    foreach ($guardianStmt->fetchAll() as $g) {
        notify_user(
            $pdo,
            (int) $g['user_id'],
            'Payment Received',
            'A payment of ' . format_money($amount) . ' was recorded for your child against invoice ' . $invoiceNo . '.',
            'fee',
            app_url('/parent/fees.php') . '?student_id=' . $studentId
        );
    }
}

// =====================================================================
// REPORTS DATA AGGREGATION
// =====================================================================

/**
 * Get fee collection report data.
 *
 * @param PDO    $pdo
 * @param int    $termId
 * @param int    $classLevelId (0 for all)
 * @return array
 */
function get_collection_report(PDO $pdo, int $termId, int $classLevelId = 0): array
{
    $sql = "SELECT cl.level_name, c.stream_name,
                   COUNT(DISTINCT i.student_id) AS student_count,
                   COUNT(i.invoice_id) AS invoice_count,
                   COALESCE(SUM(i.total_amount), 0) AS total_billed,
                   COALESCE(SUM(i.amount_paid), 0) AS total_collected,
                   COALESCE(SUM(i.balance), 0) AS total_outstanding,
                   SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) AS paid_invoices,
                   SUM(CASE WHEN i.status = 'partial' THEN 1 ELSE 0 END) AS partial_invoices,
                   SUM(CASE WHEN i.status = 'pending' THEN 1 ELSE 0 END) AS pending_invoices,
                   SUM(CASE WHEN i.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_invoices
            FROM invoices i
            JOIN students s ON s.student_id = i.student_id
            JOIN classes c ON c.class_id = s.class_id
            JOIN class_levels cl ON cl.class_level_id = c.class_level_id
            WHERE i.term_id = :term";

    $params = ['term' => $termId];

    if ($classLevelId > 0) {
        $sql .= ' AND c.class_level_id = :cl';
        $params['cl'] = $classLevelId;
    }

    $sql .= ' GROUP BY cl.class_level_id, cl.level_name, c.class_id, c.stream_name
              ORDER BY cl.sort_order, c.stream_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get outstanding balance report.
 *
 * @param PDO $pdo
 * @param int $termId
 * @param int $classLevelId
 * @return array
 */
function get_outstanding_report(PDO $pdo, int $termId, int $classLevelId = 0): array
{
    $sql = "SELECT s.student_id, u.first_name, u.last_name, s.admission_no,
                   cl.level_name, c.stream_name,
                   i.invoice_no, i.control_number,
                   i.total_amount, i.amount_paid, i.balance,
                   i.due_date, i.status,
                   DATEDIFF(CURDATE(), i.due_date) AS days_overdue
            FROM invoices i
            JOIN students s ON s.student_id = i.student_id
            JOIN users u ON u.user_id = s.user_id
            JOIN classes c ON c.class_id = s.class_id
            JOIN class_levels cl ON cl.class_level_id = c.class_level_id
            WHERE i.term_id = :term AND i.balance > 0 AND i.status != 'cancelled'";

    $params = ['term' => $termId];

    if ($classLevelId > 0) {
        $sql .= ' AND c.class_level_id = :cl';
        $params['cl'] = $classLevelId;
    }

    $sql .= ' ORDER BY i.balance DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get student statement (all invoices + payments for a student).
 *
 * @param PDO $pdo
 * @param int $studentId
 * @return array
 */
function get_student_statement(PDO $pdo, int $studentId): array
{
    return get_student_fee_summary($pdo, $studentId);
}

/**
 * Get daily collection report.
 *
 * @param PDO   $pdo
 * @param string $dateFrom Y-m-d
 * @param string $dateTo   Y-m-d
 * @return array
 */
function get_daily_collection_report(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $stmt = $pdo->prepare(
        "SELECT p.payment_date, p.reference_no, p.amount, p.payment_method,
                p.recorded_by_name, p.notes,
                u.first_name AS student_fn, u.last_name AS student_ln, s.admission_no,
                i.invoice_no, i.control_number
         FROM payments p
         JOIN students s ON s.student_id = p.student_id
         JOIN users u ON u.user_id = s.user_id
         JOIN invoices i ON i.invoice_id = p.invoice_id
         WHERE p.payment_date BETWEEN :from AND :to
         ORDER BY p.payment_date DESC, p.created_at DESC"
    );
    $stmt->execute(['from' => $dateFrom, 'to' => $dateTo]);
    return $stmt->fetchAll();
}

/**
 * Get monthly collection report.
 *
 * @param PDO $pdo
 * @param int $month
 * @param int $year
 * @return array
 */
function get_monthly_collection_report(PDO $pdo, int $month, int $year): array
{
    $stmt = $pdo->prepare(
        "SELECT p.payment_date, p.reference_no, p.amount, p.payment_method,
                p.recorded_by_name,
                u.first_name AS student_fn, u.last_name AS student_ln, s.admission_no,
                i.invoice_no, i.control_number,
                cl.level_name
         FROM payments p
         JOIN students s ON s.student_id = p.student_id
         JOIN users u ON u.user_id = s.user_id
         JOIN invoices i ON i.invoice_id = p.invoice_id
         JOIN classes c ON c.class_id = s.class_id
         JOIN class_levels cl ON cl.class_level_id = c.class_level_id
         WHERE MONTH(p.payment_date) = :month AND YEAR(p.payment_date) = :year
         ORDER BY p.payment_date DESC, p.created_at DESC"
    );
    $stmt->execute(['month' => $month, 'year' => $year]);
    return $stmt->fetchAll();
}

/**
 * Get annual collection report.
 *
 * @param PDO $pdo
 * @param int $year
 * @return array
 */
function get_annual_collection_report(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(payment_date, '%M') AS month_name,
                MONTH(payment_date) AS month_num,
                COALESCE(SUM(amount), 0) AS total_collected,
                COUNT(*) AS payment_count,
                COUNT(DISTINCT p.student_id) AS student_count
         FROM payments p
         WHERE YEAR(payment_date) = :year
         GROUP BY DATE_FORMAT(payment_date, '%M'), MONTH(payment_date)
         ORDER BY MONTH(payment_date)"
    );
    $stmt->execute(['year' => $year]);
    return $stmt->fetchAll();
}

// =====================================================================
// EXPORT HELPERS
// =====================================================================

/**
 * Export data as CSV file for download.
 *
 * @param array  $data      Array of associative arrays
 * @param array  $headers   Column headers
 * @param string $filename  Download filename
 */
function export_csv(array $data, array $headers, string $filename = 'export.csv'): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Headers
    fputcsv($output, $headers);

    // Data rows
    foreach ($data as $row) {
        $line = [];
        foreach ($headers as $header) {
            $key = strtolower(str_replace([' ', '.', '/'], '_', $header));
            $line[] = $row[$key] ?? $row[$header] ?? '';
        }
        fputcsv($output, $line);
    }

    fclose($output);
    exit;
}

/**
 * Generate a printable HTML version of a report.
 *
 * @param string $title    Report title
 * @param array  $headers  Column headers
 * @param array  $data     Data rows
 * @param array  $totals   Summary totals
 * @return string HTML content
 */
function render_printable_report(string $title, array $headers, array $data, array $totals = []): string
{
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html .= '<title>' . e($title) . '</title>';
    $html .= '<style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
        h1 { font-size: 18px; margin-bottom: 5px; }
        .meta { color: #666; font-size: 11px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #1a3a5c; color: white; padding: 8px; text-align: left; font-size: 11px; }
        td { padding: 6px 8px; border-bottom: 1px solid #ddd; }
        tr:nth-child(even) { background: #f8f9fa; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        .summary { margin-top: 20px; padding: 15px; background: #f0f4f8; border-radius: 5px; }
        .summary table { width: auto; margin: 0; }
        .summary td { border: none; padding: 3px 10px; }
        @media print { body { margin: 0; } }
    </style></head><body>';
    $html .= '<h1>' . e($title) . '</h1>';
    $html .= '<div class="meta">Generated: ' . date('d M Y H:i') . ' | ASMS Fee Management</div>';
    $html .= '<table><thead><tr>';
    foreach ($headers as $h) {
        $html .= '<th>' . e($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($headers as $h) {
            $key = strtolower(str_replace([' ', '.', '/'], '_', $h));
            $val = $row[$key] ?? $row[$h] ?? '';
            $html .= '<td>' . e((string) $val) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    if (!empty($totals)) {
        $html .= '<div class="summary"><h3>Summary</h3><table>';
        foreach ($totals as $label => $value) {
            $html .= '<tr><td><strong>' . e($label) . ':</strong></td><td>' . e((string) $value) . '</td></tr>';
        }
        $html .= '</table></div>';
    }

    $html .= '<p style="text-align:center;color:#999;font-size:10px;margin-top:30px;">';
    $html .= 'Advanced School Management System &copy; ' . date('Y') . '</p>';
    $html .= '</body></html>';
    return $html;
}

/**
 * Output a PDF file for download using HTML2PDF approach.
 * Uses the browser's built-in print functionality.
 *
 * @param string $html     HTML content
 * @param string $filename Download filename
 */
function output_pdf(string $html, string $filename = 'document.pdf'): void
{
    // For now, output as HTML with print CSS - browser can save as PDF
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $html;
    exit;
}

// =====================================================================
// PAYMENT GATEWAY INTERFACE (API-Ready for Future Integration)
// =====================================================================

/**
 * Interface for payment gateway integration.
 * Implement this for each gateway (GePG, M-Pesa, Tigo Pesa, etc.)
 */
interface PaymentGatewayInterface
{
    /**
     * Initiate a payment request.
     *
     * @param array $paymentData Payment details
     * @return array{success:bool,message:string,transaction_id:string|null,redirect_url:string|null}
     */
    public function initiatePayment(array $paymentData): array;

    /**
     * Verify a payment transaction.
     *
     * @param string $transactionId
     * @return array{success:bool,status:string,amount:float,reference:string}
     */
    public function verifyPayment(string $transactionId): array;

    /**
     * Process a callback/webhook from the gateway.
     *
     * @param array $payload Callback payload
     * @return array{success:bool,message:string}
     */
    public function processCallback(array $payload): array;
}

/**
 * GePG (Government e-Payment Gateway) stub implementation.
 */
class GePGGateway implements PaymentGatewayInterface
{
    private string $apiEndpoint;
    private string $apiKey;
    private string $merchantCode;

    public function __construct(string $apiEndpoint = '', string $apiKey = '', string $merchantCode = '')
    {
        $this->apiEndpoint = $apiEndpoint ?: 'https://gepg.example.com/api/v1';
        $this->apiKey = $apiKey ?: '';
        $this->merchantCode = $merchantCode ?: 'SCH001';
    }

    public function initiatePayment(array $paymentData): array
    {
        // TODO: Implement actual GePG API call
        // For now, return a simulated response
        return [
            'success'        => true,
            'message'        => 'Payment initiated via GePG',
            'transaction_id' => 'GEPG' . date('YmdHis') . random_int(1000, 9999),
            'redirect_url'   => $this->apiEndpoint . '/pay/' . $paymentData['control_number'] ?? '',
        ];
    }

    public function verifyPayment(string $transactionId): array
    {
        // TODO: Implement actual verification
        return [
            'success'   => true,
            'status'    => 'completed',
            'amount'    => 0.00,
            'reference' => $transactionId,
        ];
    }

    public function processCallback(array $payload): array
    {
        // TODO: Implement actual callback processing
        return [
            'success' => true,
            'message' => 'Callback processed successfully',
        ];
    }
}

/**
 * M-Pesa stub implementation.
 */
class MpesaGateway implements PaymentGatewayInterface
{
    private string $apiEndpoint;
    private string $consumerKey;
    private string $consumerSecret;

    public function __construct(string $apiEndpoint = '', string $consumerKey = '', string $consumerSecret = '')
    {
        $this->apiEndpoint = $apiEndpoint ?: 'https://api.mpesa.example.com/v1';
        $this->consumerKey = $consumerKey ?: '';
        $this->consumerSecret = $consumerSecret ?: '';
    }

    public function initiatePayment(array $paymentData): array
    {
        return [
            'success'        => true,
            'message'        => 'STK Push sent to customer',
            'transaction_id' => 'MPESA' . date('YmdHis') . random_int(1000, 9999),
            'redirect_url'   => null,
        ];
    }

    public function verifyPayment(string $transactionId): array
    {
        return [
            'success'   => true,
            'status'    => 'completed',
            'amount'    => 0.00,
            'reference' => $transactionId,
        ];
    }

    public function processCallback(array $payload): array
    {
        return [
            'success' => true,
            'message' => 'M-Pesa callback processed',
        ];
    }
}

/**
 * Factory to get the appropriate payment gateway.
 *
 * @param string $gatewayName
 * @return PaymentGatewayInterface|null
 */
function get_payment_gateway(string $gatewayName): ?PaymentGatewayInterface
{
    return match (strtolower($gatewayName)) {
        'gepg'       => new GePGGateway(),
        'mpesa'      => new MpesaGateway(),
        'tigo_pesa'  => new MpesaGateway(), // Placeholder
        'airtel_money' => new MpesaGateway(), // Placeholder
        'halopesa'   => new MpesaGateway(), // Placeholder
        'bank'       => new GePGGateway(), // Placeholder
        default      => null,
    };
}

/**
 * Log an API payment request/response for audit.
 *
 * @param PDO    $pdo
 * @param int|null $paymentId
 * @param int|null $invoiceId
 * @param string $gateway
 * @param string $requestPayload
 * @param string $responsePayload
 * @param string $status
 * @param string $referenceNo
 * @param string $transactionId
 * @return int API log ID
 */
function log_payment_api_call(
    PDO $pdo,
    ?int $paymentId,
    ?int $invoiceId,
    string $gateway,
    string $requestPayload,
    string $responsePayload,
    string $status = 'pending',
    string $referenceNo = '',
    string $transactionId = ''
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO payment_api_logs (payment_id, invoice_id, gateway, request_payload, response_payload, status, reference_no, transaction_id)
         VALUES (:pid, :iid, :gw, :req, :res, :status, :ref, :txn)'
    );
    $stmt->execute([
        'pid'    => $paymentId,
        'iid'    => $invoiceId,
        'gw'     => $gateway,
        'req'    => $requestPayload,
        'res'    => $responsePayload,
        'status' => $status,
        'ref'    => $referenceNo ?: null,
        'txn'    => $transactionId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}
