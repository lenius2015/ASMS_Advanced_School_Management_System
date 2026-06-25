<?php
/**
 * bursar/fee_reminders.php
 * Complete Fee Reminder Management System.
 * 
 * Bursar can:
 * - Send individual reminders to guardians of students with outstanding balances
 * - Send bulk reminders to all overdue invoices
 * - View reminder history (sent date, channel, message)
 * - Filter by status and class
 * - CRUD operations for reminder management
 * 
 * Channels: In-App Notification, SMS (future), Email (future)
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

// ====== HANDLE SEND REMINDER (Single) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminder') {
    csrf_verify();
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $channel = $_POST['channel'] ?? 'in_app';
    $customMessage = trim($_POST['custom_message'] ?? '');

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

        $message = $customMessage ?: "Reminder: Invoice {$invoice['invoice_no']} for {$invoice['first_name']} {$invoice['last_name']} has an outstanding balance of " . format_money($invoice['balance']) . ". Please settle at your earliest convenience.";

        $sentCount = 0;
        foreach ($guardians as $g) {
            // Log the reminder
            $pdo->prepare(
                'INSERT INTO fee_reminders (invoice_id, sent_to_guardian_id, channel, message) VALUES (:iid, :gid, :channel, :msg)'
            )->execute(['iid' => $invoiceId, 'gid' => $g['guardian_id'], 'channel' => $channel, 'msg' => $message]);

            // Send in-app notification if guardian has user account
            if ($g['user_id']) {
                notify_user($pdo, (int) $g['user_id'], 'Fee Payment Reminder', $message, 'fee', app_url('/parent/fees.php'));
                $sentCount++;
            }

            // SMS Channel (future integration)
            if ($channel === 'sms' && $g['phone']) {
                // TODO: Integrate SMS gateway here
                // send_sms($g['phone'], $message);
            }

            // Email Channel (future integration)
            if ($channel === 'email' && $g['email']) {
                // TODO: Integrate email service here
                // send_email($g['email'], 'Fee Payment Reminder', $message);
            }
        }

        audit_log('send_fee_reminder', 'finance', 'invoices', $invoiceId, "Sent {$channel} reminder for invoice {$invoice['invoice_no']}");
        flash_set('success', $sentCount > 0 ? "Reminder sent to {$sentCount} guardian(s) via {$channel}." : 'Reminder logged, but no guardian has portal access yet.');
    }
    redirect(app_url('/bursar/fee_reminders.php'));
}

// ====== HANDLE BULK REMINDERS ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_bulk_reminders') {
    csrf_verify();
    $channel = $_POST['bulk_channel'] ?? 'in_app';
    $classLevelId = (int) ($_POST['bulk_class_level_id'] ?? 0);

    $sql = "SELECT i.invoice_id FROM invoices i
            JOIN students s ON s.student_id = i.student_id
            LEFT JOIN classes c ON c.class_id = s.class_id
            WHERE i.status IN ('pending', 'partial', 'overdue') AND i.balance > 0";
    $params = [];

    if ($classLevelId > 0) {
        $sql .= ' AND c.class_level_id = :cl';
        $params['cl'] = $classLevelId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoiceIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sent = 0;
    foreach ($invoiceIds as $iid) {
        // Re-use single reminder logic
        $invStmt = $pdo->prepare(
            "SELECT i.*, u.first_name, u.last_name FROM invoices i
             JOIN students s ON s.student_id = i.student_id JOIN users u ON u.user_id = s.user_id
             WHERE i.invoice_id = :id"
        );
        $invStmt->execute(['id' => $iid]);
        $invoice = $invStmt->fetch();
        if (!$invoice) continue;

        $guardianStmt = $pdo->prepare(
            "SELECT g.* FROM guardians g JOIN student_guardians sg ON sg.guardian_id = g.guardian_id WHERE sg.student_id = :sid"
        );
        $guardianStmt->execute(['sid' => $invoice['student_id']]);
        $guardians = $guardianStmt->fetchAll();

        foreach ($guardians as $g) {
            $message = "Reminder: Invoice {$invoice['invoice_no']} has an outstanding balance of " . format_money($invoice['balance']) . ". Please settle at your earliest convenience.";
            $pdo->prepare(
                'INSERT INTO fee_reminders (invoice_id, sent_to_guardian_id, channel, message) VALUES (:iid, :gid, :channel, :msg)'
            )->execute(['iid' => $iid, 'gid' => $g['guardian_id'], 'channel' => $channel, 'msg' => $message]);

            if ($g['user_id']) {
                notify_user($pdo, (int) $g['user_id'], 'Fee Payment Reminder', $message, 'fee', app_url('/parent/fees.php'));
                $sent++;
            }
        }
    }

    audit_log('send_bulk_reminders', 'finance', 'fee_reminders', null, "Sent bulk {$channel} reminders to {$sent} guardians");
    flash_set('success', "Bulk reminder sent to {$sent} guardian(s) via {$channel}.");
    redirect(app_url('/bursar/fee_reminders.php'));
}

// ====== HANDLE DELETE REMINDER ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_reminder') {
    csrf_verify();
    $reminderId = (int) ($_POST['reminder_id'] ?? 0);
    if ($reminderId > 0) {
        $pdo->prepare('DELETE FROM fee_reminders WHERE reminder_id = :id')->execute(['id' => $reminderId]);
        audit_log('delete_fee_reminder', 'finance', 'fee_reminders', $reminderId, 'Deleted fee reminder');
        flash_set('success', 'Reminder deleted.');
    }
    redirect(app_url('/bursar/fee_reminders.php'));
}

// ====== GET DATA ======
$statusFilter = $_GET['status'] ?? '';
$classFilter = (int) ($_GET['class_level_id'] ?? 0);

$sql = "SELECT i.*, u.first_name, u.last_name, s.admission_no, cl.level_name, c.stream_name,
        (SELECT MAX(fr.sent_at) FROM fee_reminders fr WHERE fr.invoice_id = i.invoice_id) AS last_reminder,
        (SELECT COUNT(*) FROM fee_reminders fr WHERE fr.invoice_id = i.invoice_id) AS reminder_count
        FROM invoices i
        JOIN students s ON s.student_id = i.student_id 
        JOIN users u ON u.user_id = s.user_id
        LEFT JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN class_levels cl ON cl.class_level_id = c.class_level_id
        WHERE i.balance > 0";

$params = [];

if ($statusFilter) {
    $sql .= ' AND i.status = :status';
    $params['status'] = $statusFilter;
} else {
    $sql .= " AND i.status IN ('pending', 'partial', 'overdue')";
}

if ($classFilter > 0) {
    $sql .= ' AND c.class_level_id = :cl';
    $params['cl'] = $classFilter;
}

$sql .= ' ORDER BY i.due_date ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$overdueInvoices = $stmt->fetchAll();

// Get reminder history
$reminderHistory = $pdo->query(
    "SELECT fr.*, i.invoice_no, u.first_name, u.last_name, s.admission_no,
            g.first_name AS guardian_fn, g.last_name AS guardian_ln
     FROM fee_reminders fr
     JOIN invoices i ON i.invoice_id = fr.invoice_id
     JOIN students s ON s.student_id = i.student_id
     JOIN users u ON u.user_id = s.user_id
     LEFT JOIN guardians g ON g.guardian_id = fr.sent_to_guardian_id
     ORDER BY fr.sent_at DESC LIMIT 50"
)->fetchAll();

$classLevels = $pdo->query('SELECT * FROM class_levels ORDER BY sort_order')->fetchAll();

$pageTitle = 'Fee Reminders';
require APP_ROOT . '/includes/header.php';
?>

<style>html{overflow-y:scroll;}body{padding-right:0!important;}.animate-fade-in,.animate-delay-1,.animate-delay-2,.animate-delay-3{animation:none!important;opacity:1!important;}body.modal-open,.modal-open{padding-right:0!important;overflow:auto!important;}.modal-backdrop{position:fixed!important;}.modal-open .modal{overflow-x:hidden;overflow-y:auto;}.modal-header,.modal-footer{flex-shrink:0;}</style>
<div class="welcome-section">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h1 class="h3 mb-1">Fee Reminders <span class="badge bg-gold ms-2">Communication</span></h1>
      <p class="mb-0">Send and manage fee reminders to parents/guardians</p>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-gold btn-sm" data-bs-toggle="modal" data-bs-target="#bulkModal"><i class="fa fa-bullhorn me-1"></i> Bulk Reminder</button>
      <a href="<?= e(app_url('/bursar/dashboard.php')) ?>" class="btn btn-outline-light btn-sm"><i class="fa fa-tachometer-alt me-1"></i> Dashboard</a>
    </div>
  </div>
</div>

<!-- CRUD Action Bar -->
<div class="action-bar animate-fade-in animate-delay-1">
  <div class="action-left">
    <div class="search-box">
      <i class="fa fa-search search-icon"></i>
      <input type="text" class="form-control form-control-sm" placeholder="Search students..." data-search=".data-table" style="width:200px;">
      <i class="fa fa-times search-clear"></i>
    </div>
    <span class="filter-badge"><i class="fa fa-filter"></i> Fee Reminders</span>
  </div>
  <div class="action-right">
    <a href="<?= e(app_url('/bursar/fee_structures.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-list-ol me-1"></i> Fee Structures</a>
    <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-file-invoice me-1"></i> Invoices</a>
    <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-money-bill-wave me-1"></i> Record Payment</a>
    <a href="<?= e(app_url('/bursar/reports.php')) ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-chart-line me-1"></i> Reports</a>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
  </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4 animate-fade-in animate-delay-1">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-auto"><label class="form-label mb-0 fw-semibold small">Filter:</label></div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
          <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
          <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
        </select>
      </div>
      <div class="col-md-2">
        <select name="class_level_id" class="form-select form-select-sm">
          <option value="">All Classes</option>
          <?php foreach ($classLevels as $cl): ?>
            <option value="<?= (int) $cl['class_level_id'] ?>" <?= $classFilter === (int) $cl['class_level_id'] ? 'selected' : '' ?>><?= e($cl['level_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-sm btn-outline-primary"><i class="fa fa-search"></i> Filter</button>
        <a href="<?= e(app_url('/bursar/fee_reminders.php')) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-times"></i> Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-4">
  <!-- Outstanding Invoices Table -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-exclamation-triangle text-gold me-1"></i> Outstanding Invoices</span>
        <div>
          <span class="badge bg-danger me-1"><?= count($overdueInvoices) ?> pending</span>
          <button class="btn btn-sm btn-gold" data-bs-toggle="modal" data-bs-target="#bulkModal"><i class="fa fa-bullhorn"></i> Bulk</button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 data-table">
          <thead>
            <tr>
              <th>Student</th>
              <th>Invoice</th>
              <th>Control No.</th>
              <th>Class</th>
              <th>Balance</th>
              <th>Due</th>
              <th>Reminders</th>
              <th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($overdueInvoices as $inv): ?>
              <tr>
                <td>
                  <?= e($inv['first_name'] . ' ' . $inv['last_name']) ?>
                  <span class="text-muted small">(<?= e($inv['admission_no']) ?>)</span>
                </td>
                <td><code><?= e($inv['invoice_no']) ?></code></td>
                <td><code class="small"><?= e($inv['control_number'] ?: '-') ?></code></td>
                <td class="small"><?= e($inv['level_name'] ?? '') ?> <?= e($inv['stream_name'] ?? '') ?></td>
                <td class="text-danger fw-semibold"><?= format_money($inv['balance']) ?></td>
                <td class="small <?= strtotime($inv['due_date']) < time() ? 'text-danger' : '' ?>"><?= format_date($inv['due_date']) ?></td>
                <td>
                  <span class="badge bg-<?= (int) $inv['reminder_count'] > 0 ? 'info' : 'secondary' ?>">
                    <?= (int) $inv['reminder_count'] ?>x
                  </span>
                  <?php if ($inv['last_reminder']): ?>
                    <br><small class="text-muted"><?= date('d M', strtotime($inv['last_reminder'])) ?></small>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <div class="btn-group btn-group-sm">
                    <a href="<?= e(app_url('/bursar/invoices.php')) ?>?id=<?= (int) $inv['invoice_id'] ?>" class="btn btn-outline-primary" title="View Invoice"><i class="fa fa-eye"></i></a>
                    <a href="<?= e(app_url('/bursar/record_payment.php')) ?>?invoice_id=<?= (int) $inv['invoice_id'] ?>" class="btn btn-outline-success" title="Record Payment"><i class="fa fa-money-bill-wave"></i></a>
                    <button class="btn btn-outline-warning" title="Send Reminder" data-bs-toggle="modal" data-bs-target="#reminderModal<?= (int) $inv['invoice_id'] ?>"><i class="fa fa-paper-plane"></i></button>
                  </div>

                  <!-- Individual Reminder Modal -->
                  <div class="modal fade" id="reminderModal<?= (int) $inv['invoice_id'] ?>" tabindex="-1">
                    <div class="modal-dialog"><div class="modal-content">
                      <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="send_reminder">
                        <input type="hidden" name="invoice_id" value="<?= (int) $inv['invoice_id'] ?>">
                        <div class="modal-header">
                          <h5 class="modal-title">Send Reminder</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-start">
                          <p><strong>Student:</strong> <?= e($inv['first_name'] . ' ' . $inv['last_name']) ?><br>
                          <strong>Invoice:</strong> <?= e($inv['invoice_no']) ?><br>
                          <strong>Balance:</strong> <?= format_money($inv['balance']) ?></p>
                          <div class="mb-2">
                            <label class="form-label">Channel</label>
                            <select name="channel" class="form-select">
                              <option value="in_app">In-App Notification</option>
                              <option value="sms">SMS (Future)</option>
                              <option value="email">Email (Future)</option>
                            </select>
                          </div>
                          <div class="mb-2">
                            <label class="form-label">Custom Message (optional)</label>
                            <textarea name="custom_message" class="form-control" rows="3" placeholder="Leave blank for default message">Reminder: Invoice <?= e($inv['invoice_no']) ?> for <?= e($inv['first_name'] . ' ' . $inv['last_name']) ?> has an outstanding balance of <?= e(format_money($inv['balance'])) ?>. Please settle at your earliest convenience.</textarea>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane me-1"></i> Send Reminder</button>
                        </div>
                      </form>
                    </div></div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($overdueInvoices)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">No outstanding invoices. All fees collected!</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Reminder History -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fa fa-history text-gold me-1"></i> Reminder History <span class="badge bg-navy ms-1"><?= count($reminderHistory) ?></span></div>
      <div class="card-body p-0">
        <ul class="activity-feed">
          <?php foreach ($reminderHistory as $rh): ?>
            <li class="activity-item">
              <div class="activity-icon icon-warning"><i class="fa fa-bell"></i></div>
              <div class="activity-content">
                <div class="activity-title"><?= e($rh['first_name'] . ' ' . $rh['last_name']) ?></div>
                <div class="activity-text small">
                  Invoice: <?= e($rh['invoice_no']) ?><br>
                  To: <?= e($rh['guardian_fn'] . ' ' . $rh['guardian_ln']) ?><br>
                  Via: <span class="badge bg-info"><?= e($rh['channel']) ?></span>
                </div>
              </div>
              <div class="activity-time small text-muted">
                <?= date('d M H:i', strtotime($rh['sent_at'])) ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this reminder?')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_reminder">
                  <input type="hidden" name="reminder_id" value="<?= (int) $rh['reminder_id'] ?>">
                  <button class="btn btn-sm btn-link text-danger p-0 ms-1" title="Delete"><i class="fa fa-times"></i></button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($reminderHistory)): ?>
            <li class="activity-item"><div class="activity-content"><div class="activity-text text-muted">No reminders sent yet.</div></div></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <!-- Quick Stats -->
    <div class="card mt-3">
      <div class="card-header"><i class="fa fa-chart-simple text-gold me-1"></i> Summary</div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
          <span class="small">Pending Invoices</span>
          <span class="fw-bold"><?= count(array_filter($overdueInvoices, fn($i) => $i['status'] === 'pending')) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span class="small">Partial Payments</span>
          <span class="fw-bold"><?= count(array_filter($overdueInvoices, fn($i) => $i['status'] === 'partial')) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span class="small">Overdue</span>
          <span class="fw-bold"><?= count(array_filter($overdueInvoices, fn($i) => $i['status'] === 'overdue')) ?></span>
        </div>
        <div class="d-flex justify-content-between">
          <span class="small">Total Reminders Sent</span>
          <span class="fw-bold"><?= count($reminderHistory) ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Reminder Modal -->
<div class="modal fade" id="bulkModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="send_bulk_reminders">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-bullhorn me-1"></i> Send Bulk Reminders</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Send reminders to all guardians of students with outstanding balances. This will notify all guardians who have portal access.</p>
        <div class="mb-2">
          <label class="form-label">Channel</label>
          <select name="bulk_channel" class="form-select">
            <option value="in_app">In-App Notification</option>
            <option value="sms">SMS (Future)</option>
            <option value="email">Email (Future)</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Class Level (optional)</label>
          <select name="bulk_class_level_id" class="form-select">
            <option value="0">All Classes</option>
            <?php foreach ($classLevels as $cl): ?>
              <option value="<?= (int) $cl['class_level_id'] ?>"><?= e($cl['level_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="alert alert-info small py-2">
          <i class="fa fa-info-circle me-1"></i> This will send reminders for <strong><?= count($overdueInvoices) ?></strong> outstanding invoice(s).
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-gold" onclick="return confirm('Send bulk reminders to all guardians?')">
          <i class="fa fa-paper-plane me-1"></i> Send to All
        </button>
      </div>
    </form>
  </div></div>
</div>

<!-- Quick Actions -->
<div class="row mt-4 animate-fade-in animate-delay-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fa fa-bolt text-gold me-2"></i>Quick Actions</div>
      <div class="card-body">
        <div class="quick-actions">
          <a href="<?= e(app_url('/bursar/dashboard.php')) ?>" class="quick-action-item"><i class="fa fa-tachometer-alt"></i><span>Dashboard</span></a>
          <a href="<?= e(app_url('/bursar/fee_structures.php')) ?>" class="quick-action-item"><i class="fa fa-list-ol"></i><span>Fee Structures</span></a>
          <a href="<?= e(app_url('/bursar/invoices.php')) ?>" class="quick-action-item"><i class="fa fa-file-invoice"></i><span>Invoices</span></a>
          <a href="<?= e(app_url('/bursar/record_payment.php')) ?>" class="quick-action-item"><i class="fa fa-money-bill-wave"></i><span>Record Payment</span></a>
          <a href="<?= e(app_url('/bursar/reports.php')) ?>" class="quick-action-item"><i class="fa fa-chart-pie"></i><span>Reports</span></a>
          <a href="<?= e(app_url('/bursar/fee_reminders.php')) ?>" class="quick-action-item"><i class="fa fa-bell"></i><span>Fee Reminders</span></a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>