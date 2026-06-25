<?php
/**
 * bursar/fee_reminders.php
 * Send fee reminders (in-app notification) to guardians of students
 * with outstanding balances. SMS/Email integration can be wired in later
 * by extending send_reminder() below.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminder') {
    csrf_verify();
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);

    $invStmt = $pdo->prepare(
        "SELECT i.*, u.first_name, u.last_name FROM invoices i
         JOIN students s ON s.student_id = i.student_id JOIN users u ON u.user_id = s.user_id
         WHERE i.invoice_id = :id"
    );
    $invStmt->execute(['id' => $invoiceId]);
    $invoice = $invStmt->fetch();

    if (!$invoice) {
        flash_set('error', 'Invoice not found.');
    } else {
        $guardianStmt = $pdo->prepare(
            "SELECT g.* FROM guardians g JOIN student_guardians sg ON sg.guardian_id = g.guardian_id WHERE sg.student_id = :sid"
        );
        $guardianStmt->execute(['sid' => $invoice['student_id']]);
        $guardians = $guardianStmt->fetchAll();

        $message = "Reminder: Invoice {$invoice['invoice_no']} for {$invoice['first_name']} {$invoice['last_name']} has an outstanding balance of " . format_money($invoice['balance']) . ". Please settle at your earliest convenience.";

        $sentCount = 0;
        foreach ($guardians as $g) {
            $pdo->prepare(
                'INSERT INTO fee_reminders (invoice_id, sent_to_guardian_id, channel, message) VALUES (:iid, :gid, "in_app", :msg)'
            )->execute(['iid' => $invoiceId, 'gid' => $g['guardian_id'], 'msg' => $message]);

            if ($g['user_id']) {
                notify_user($pdo, (int) $g['user_id'], 'Fee Payment Reminder', $message, 'fee', app_url('/parent/fees.php'));
                $sentCount++;
            }
        }

        audit_log('send_fee_reminder', 'finance', 'invoices', $invoiceId, "Sent fee reminder for invoice {$invoice['invoice_no']}");
        flash_set('success', $sentCount > 0 ? "Reminder sent to {$sentCount} guardian(s)." : 'Reminder logged, but no guardian has portal access yet to receive an in-app notification.');
    }
    redirect(app_url('/bursar/fee_reminders.php'));
}

$overdueInvoices = $pdo->query(
    "SELECT i.*, u.first_name, u.last_name, s.admission_no,
        (SELECT MAX(fr.sent_at) FROM fee_reminders fr WHERE fr.invoice_id = i.invoice_id) AS last_reminder
     FROM invoices i
     JOIN students s ON s.student_id = i.student_id JOIN users u ON u.user_id = s.user_id
     WHERE i.status IN ('unpaid', 'partial', 'overdue') AND i.balance > 0
     ORDER BY i.due_date ASC"
)->fetchAll();

$pageTitle = 'Fee Reminders';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Fee Reminders</h1>
<p class="text-muted">Send a reminder notification to the guardians of students with an outstanding balance. Reminders are delivered as in-app notifications to any guardian with portal access.</p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Student</th><th>Invoice</th><th>Balance</th><th>Due Date</th><th>Last Reminder</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($overdueInvoices as $inv): ?>
          <tr>
            <td><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?> <span class="text-muted small">(<?= e($inv['admission_no']) ?>)</span></td>
            <td><code><?= e($inv['invoice_no']) ?></code></td>
            <td class="text-danger fw-semibold"><?= format_money($inv['balance']) ?></td>
            <td class="small <?= strtotime($inv['due_date']) < time() ? 'text-danger' : '' ?>"><?= format_date($inv['due_date']) ?></td>
            <td class="small text-muted"><?= $inv['last_reminder'] ? e(date('d M Y, H:i', strtotime($inv['last_reminder']))) : 'Never' ?></td>
            <td>
              <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="send_reminder">
                <input type="hidden" name="invoice_id" value="<?= (int) $inv['invoice_id'] ?>">
                <button class="btn btn-sm btn-gold"><i class="fa fa-paper-plane me-1"></i> Send Reminder</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($overdueInvoices)): ?><tr><td colspan="6" class="text-center text-muted py-4">No outstanding invoices to remind about.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
