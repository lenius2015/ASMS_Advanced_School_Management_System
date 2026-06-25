<?php
/**
 * bursar/budget.php
 * Department budget allocation and expense tracking/approval.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();
$period = get_current_period($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_budget') {
    csrf_verify();
    $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
    $category = trim($_POST['category'] ?? '');
    $amount = (float) ($_POST['allocated_amount'] ?? 0);

    if ($category === '' || $amount <= 0) {
        flash_set('error', 'Category and a positive amount are required.');
    } else {
        $pdo->prepare('INSERT INTO budgets (year_id, department_id, category, allocated_amount, created_by) VALUES (:y, :d, :c, :a, :by)')
            ->execute(['y' => $period['year_id'], 'd' => $departmentId, 'c' => $category, 'a' => $amount, 'by' => current_user_id()]);
        audit_log('add_budget', 'finance', 'budgets', (int) $pdo->lastInsertId(), "Added budget line: {$category}");
        flash_set('success', 'Budget line added.');
    }
    redirect(app_url('/bursar/budget.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_expense') {
    csrf_verify();
    $budgetId = (int) ($_POST['budget_id'] ?? 0) ?: null;
    $departmentId = (int) ($_POST['department_id'] ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');

    if ($description === '' || $amount <= 0) {
        flash_set('error', 'Description and a positive amount are required.');
    } else {
        $pdo->prepare(
            'INSERT INTO expenses (budget_id, department_id, description, amount, expense_date, status, requested_by)
             VALUES (:b, :d, :desc, :amt, :date, "pending_approval", :by)'
        )->execute(['b' => $budgetId, 'd' => $departmentId, 'desc' => $description, 'amt' => $amount, 'date' => $expenseDate, 'by' => current_user_id()]);
        audit_log('log_expense', 'finance', 'expenses', (int) $pdo->lastInsertId(), "Logged expense: {$description}");
        flash_set('success', 'Expense logged and is pending approval.');
    }
    redirect(app_url('/bursar/budget.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_expense') {
    csrf_verify();
    $expenseId = (int) ($_POST['expense_id'] ?? 0);
    $decision = $_POST['decision'] === 'approve' ? 'approved' : 'rejected';
    $pdo->prepare('UPDATE expenses SET status = :s, approved_by = :by WHERE expense_id = :id')
        ->execute(['s' => $decision, 'by' => current_user_id(), 'id' => $expenseId]);
    audit_log('expense_decision', 'finance', 'expenses', $expenseId, "Expense {$decision}");
    flash_set('success', "Expense {$decision}.");
    redirect(app_url('/bursar/budget.php'));
}

$budgets = $pdo->prepare(
    "SELECT b.*, d.department_name FROM budgets b LEFT JOIN departments d ON d.department_id = b.department_id
     WHERE b.year_id = :y ORDER BY b.created_at DESC"
);
$budgets->execute(['y' => $period['year_id']]);
$budgetRows = $budgets->fetchAll();

$expenses = $pdo->query(
    "SELECT e.*, d.department_name, u.first_name, u.last_name FROM expenses e
     LEFT JOIN departments d ON d.department_id = e.department_id
     LEFT JOIN users u ON u.user_id = e.requested_by
     ORDER BY e.created_at DESC LIMIT 100"
)->fetchAll();

$departments = $pdo->query('SELECT * FROM departments ORDER BY department_name')->fetchAll();

$pageTitle = 'Budget & Expenses';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Budget &amp; Expense Tracking</h1>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Add Budget Line</div>
      <div class="card-body">
        <form method="POST">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="add_budget">
          <div class="mb-2">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">-- School-wide --</option>
              <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Category</label><input type="text" name="category" class="form-control" placeholder="e.g. Maintenance, Supplies" required></div>
          <div class="mb-3"><label class="form-label">Allocated Amount (TZS)</label><input type="number" name="allocated_amount" class="form-control" min="1" step="1000" required></div>
          <button type="submit" class="btn btn-primary w-100">Add Budget Line</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Log Expense</div>
      <div class="card-body">
        <form method="POST">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="log_expense">
          <div class="mb-2">
            <label class="form-label">Budget Line (optional)</label>
            <select name="budget_id" class="form-select">
              <option value="">-- None --</option>
              <?php foreach ($budgetRows as $b): ?><option value="<?= (int) $b['budget_id'] ?>"><?= e($b['category'] . ' (' . ($b['department_name'] ?: 'School-wide') . ')') ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">-- School-wide --</option>
              <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2"><label class="form-label">Description</label><input type="text" name="description" class="form-control" required></div>
          <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label">Amount (TZS)</label><input type="number" name="amount" class="form-control" min="1" step="100" required></div>
            <div class="col-6"><label class="form-label">Date</label><input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
          </div>
          <button type="submit" class="btn btn-gold w-100">Log Expense</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Budget Lines (<?= e(get_setting($pdo, 'school_name')) ?> &mdash; Current Year)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th>Category</th><th>Department</th><th>Allocated</th></tr></thead>
      <tbody>
        <?php foreach ($budgetRows as $b): ?>
          <tr><td><?= e($b['category']) ?></td><td><?= e($b['department_name'] ?: 'School-wide') ?></td><td><?= format_money($b['allocated_amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (empty($budgetRows)): ?><tr><td colspan="3" class="text-center text-muted py-3">No budget lines yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header">Expenses</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Date</th><th>Description</th><th>Department</th><th>Amount</th><th>Requested By</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($expenses as $e): ?>
          <tr>
            <td class="small"><?= format_date($e['expense_date']) ?></td>
            <td><?= e($e['description']) ?></td>
            <td class="small"><?= e($e['department_name'] ?: 'School-wide') ?></td>
            <td><?= format_money($e['amount']) ?></td>
            <td class="small text-muted"><?= e(trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''))) ?></td>
            <td><span class="badge badge-status-<?= $e['status']==='approved' || $e['status']==='paid' ? 'verified' : ($e['status']==='rejected' ? 'rejected' : 'pending') ?>"><?= e(str_replace('_',' ',ucfirst($e['status']))) ?></span></td>
            <td class="text-nowrap">
              <?php if ($e['status'] === 'pending_approval'): ?>
                <form method="POST" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="action" value="approve_expense"><input type="hidden" name="expense_id" value="<?= (int) $e['expense_id'] ?>"><input type="hidden" name="decision" value="approve"><button class="btn btn-sm btn-outline-success">Approve</button></form>
                <form method="POST" class="d-inline"><?php csrf_field(); ?><input type="hidden" name="action" value="approve_expense"><input type="hidden" name="expense_id" value="<?= (int) $e['expense_id'] ?>"><input type="hidden" name="decision" value="reject"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($expenses)): ?><tr><td colspan="7" class="text-center text-muted py-4">No expenses logged yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
