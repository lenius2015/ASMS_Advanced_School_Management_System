<?php
/**
 * bursar/payroll.php
 * Monthly payroll: create a payroll run for active staff (auto-generating
 * payslips from their basic_salary), then mark the run approved/paid.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_run') {
    csrf_verify();
    $month = (int) ($_POST['month'] ?? 0);
    $year = (int) ($_POST['year'] ?? 0);

    if ($month < 1 || $month > 12 || $year < 2000) {
        flash_set('error', 'Please select a valid month and year.');
    } else {
        $exists = $pdo->prepare('SELECT payroll_run_id FROM payroll_runs WHERE pay_period_month = :m AND pay_period_year = :y');
        $exists->execute(['m' => $month, 'y' => $year]);
        if ($exists->fetch()) {
            flash_set('error', 'A payroll run for that period already exists.');
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO payroll_runs (pay_period_month, pay_period_year, status, created_by) VALUES (:m, :y, "draft", :by)')
                    ->execute(['m' => $month, 'y' => $year, 'by' => current_user_id()]);
                $runId = (int) $pdo->lastInsertId();

                $staffList = $pdo->query("SELECT staff_id, basic_salary FROM staff WHERE status = 'active'")->fetchAll();
                foreach ($staffList as $s) {
                    $pdo->prepare(
                        'INSERT INTO payslips (payroll_run_id, staff_id, basic_salary, total_allowances, total_deductions, net_pay)
                         VALUES (:run, :staff, :basic, 0, 0, :net)'
                    )->execute(['run' => $runId, 'staff' => $s['staff_id'], 'basic' => $s['basic_salary'], 'net' => $s['basic_salary']]);
                }

                $pdo->commit();
                audit_log('create_payroll_run', 'finance', 'payroll_runs', $runId, "Created payroll run for {$month}/{$year}");
                flash_set('success', 'Payroll run created with ' . count($staffList) . ' payslip(s). Review and approve below.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[ASMS] create_run failed: ' . $e->getMessage());
                flash_set('error', 'Failed to create payroll run.');
            }
        }
    }
    redirect(app_url('/bursar/payroll.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_run') {
    csrf_verify();
    $runId = (int) ($_POST['run_id'] ?? 0);
    $pdo->prepare('UPDATE payroll_runs SET status = "approved", approved_by = :by WHERE payroll_run_id = :id')
        ->execute(['by' => current_user_id(), 'id' => $runId]);
    audit_log('approve_payroll', 'finance', 'payroll_runs', $runId, 'Approved payroll run');
    flash_set('success', 'Payroll run approved.');
    redirect(app_url('/bursar/payroll.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    csrf_verify();
    $runId = (int) ($_POST['run_id'] ?? 0);
    $pdo->prepare('UPDATE payroll_runs SET status = "paid" WHERE payroll_run_id = :id')->execute(['id' => $runId]);
    $pdo->prepare('UPDATE payslips SET status = "paid", paid_at = NOW() WHERE payroll_run_id = :id')->execute(['id' => $runId]);
    audit_log('mark_payroll_paid', 'finance', 'payroll_runs', $runId, 'Marked payroll run as paid');
    flash_set('success', 'Payroll run marked as paid. Payslips updated.');
    redirect(app_url('/bursar/payroll.php'));
}

$runs = $pdo->query(
    "SELECT pr.*, COUNT(ps.payslip_id) AS staff_count, COALESCE(SUM(ps.net_pay),0) AS total_payout
     FROM payroll_runs pr LEFT JOIN payslips ps ON ps.payroll_run_id = pr.payroll_run_id
     GROUP BY pr.payroll_run_id ORDER BY pr.pay_period_year DESC, pr.pay_period_month DESC"
)->fetchAll();

$viewRunId = (int) ($_GET['run_id'] ?? 0);
$payslips = [];
if ($viewRunId > 0) {
    $stmt = $pdo->prepare(
        "SELECT ps.*, u.first_name, u.last_name, st.staff_no, st.job_title FROM payslips ps
         JOIN staff st ON st.staff_id = ps.staff_id JOIN users u ON u.user_id = st.user_id
         WHERE ps.payroll_run_id = :id ORDER BY u.first_name"
    );
    $stmt->execute(['id' => $viewRunId]);
    $payslips = $stmt->fetchAll();
}

$pageTitle = 'Payroll';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Payroll Management</h1>

<div class="row g-4 mb-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Create Payroll Run</div>
      <div class="card-body">
        <form method="POST">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="create_run">
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Month</label>
              <select name="month" class="form-select">
                <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>><?= e(date('F', mktime(0,0,0,$m,1))) ?></option><?php endfor; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Year</label>
              <input type="number" name="year" class="form-control" value="<?= date('Y') ?>" min="2020" max="2100">
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100">Create Run</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">Payroll Runs</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Period</th><th>Staff</th><th>Total Payout</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($runs as $r): ?>
              <tr>
                <td><?= e(date('F Y', mktime(0,0,0,$r['pay_period_month'],1,$r['pay_period_year']))) ?></td>
                <td><?= (int) $r['staff_count'] ?></td>
                <td><?= format_money($r['total_payout']) ?></td>
                <td><span class="badge badge-status-<?= $r['status']==='paid' ? 'paid' : ($r['status']==='approved' ? 'verified' : 'pending') ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                <td class="text-nowrap">
                  <a href="?run_id=<?= (int) $r['payroll_run_id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                  <?php if ($r['status'] === 'draft'): ?>
                    <form method="POST" class="d-inline" data-confirm="Approve this payroll run?"><?php csrf_field(); ?><input type="hidden" name="action" value="approve_run"><input type="hidden" name="run_id" value="<?= (int) $r['payroll_run_id'] ?>"><button class="btn btn-sm btn-outline-success">Approve</button></form>
                  <?php elseif ($r['status'] === 'approved'): ?>
                    <form method="POST" class="d-inline" data-confirm="Mark this payroll run as paid?"><?php csrf_field(); ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="run_id" value="<?= (int) $r['payroll_run_id'] ?>"><button class="btn btn-sm btn-gold">Mark Paid</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($runs)): ?><tr><td colspan="5" class="text-center text-muted py-4">No payroll runs yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if ($viewRunId > 0): ?>
  <div class="card">
    <div class="card-header">Payslips for Selected Run</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Staff No.</th><th>Name</th><th>Job Title</th><th>Basic</th><th>Allowances</th><th>Deductions</th><th>Net Pay</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($payslips as $p): ?>
            <tr>
              <td><code><?= e($p['staff_no']) ?></code></td>
              <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
              <td class="small"><?= e($p['job_title']) ?></td>
              <td><?= format_money($p['basic_salary']) ?></td>
              <td class="text-success"><?= format_money($p['total_allowances']) ?></td>
              <td class="text-danger"><?= format_money($p['total_deductions']) ?></td>
              <td class="fw-semibold"><?= format_money($p['net_pay']) ?></td>
              <td><span class="badge badge-status-<?= $p['status']==='paid' ? 'paid' : 'pending' ?>"><?= e(ucfirst($p['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
