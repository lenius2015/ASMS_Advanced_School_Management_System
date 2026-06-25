<?php
/**
 * bursar/fee_structures.php
 * Define how much each class level should be charged per fee category
 * per term. This is the basis used when generating invoices in bulk.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_structure') {
    csrf_verify();

    $classLevelId = (int) ($_POST['class_level_id'] ?? 0);
    $feeCategoryId = (int) ($_POST['fee_category_id'] ?? 0);
    $termId = (int) ($_POST['term_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);

    if ($classLevelId <= 0 || $feeCategoryId <= 0 || $termId <= 0 || $amount <= 0) {
        flash_set('error', 'All fields are required and amount must be greater than zero.');
    } else {
        $pdo->prepare(
            'INSERT INTO fee_structures (class_level_id, fee_category_id, term_id, amount)
             VALUES (:cl, :fc, :term, :amt)
             ON DUPLICATE KEY UPDATE amount = :amt2'
        )->execute(['cl' => $classLevelId, 'fc' => $feeCategoryId, 'term' => $termId, 'amt' => $amount, 'amt2' => $amount]);

        audit_log('set_fee_structure', 'finance', 'fee_structures', null, 'Set/updated fee structure');
        flash_set('success', 'Fee structure saved.');
    }
    redirect(app_url('/bursar/fee_structures.php'));
}

// Bulk invoice generation for a term + class level
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_invoices') {
    csrf_verify();
    $termId = (int) ($_POST['gen_term_id'] ?? 0);
    $classLevelId = (int) ($_POST['gen_class_level_id'] ?? 0);

    if ($termId <= 0 || $classLevelId <= 0) {
        flash_set('error', 'Select both a term and a class level to generate invoices.');
        redirect(app_url('/bursar/fee_structures.php'));
    }

    $structures = $pdo->prepare('SELECT * FROM fee_structures WHERE class_level_id = :cl AND term_id = :term');
    $structures->execute(['cl' => $classLevelId, 'term' => $termId]);
    $feeItems = $structures->fetchAll();

    if (empty($feeItems)) {
        flash_set('error', 'No fee structure defined for that class level and term yet.');
        redirect(app_url('/bursar/fee_structures.php'));
    }

    $totalAmount = array_sum(array_column($feeItems, 'amount'));

    $studentsStmt = $pdo->prepare(
        "SELECT s.student_id FROM students s JOIN classes c ON c.class_id = s.class_id
         WHERE c.class_level_id = :cl AND c.year_id = (SELECT year_id FROM terms WHERE term_id = :term) AND s.status='active'"
    );
    $studentsStmt->execute(['cl' => $classLevelId, 'term' => $termId]);
    $studentIds = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);

    $created = 0;
    foreach ($studentIds as $sid) {
        $exists = $pdo->prepare('SELECT invoice_id FROM invoices WHERE student_id = :sid AND term_id = :term');
        $exists->execute(['sid' => $sid, 'term' => $termId]);
        if ($exists->fetch()) {
            continue; // avoid duplicate invoices for the same student/term
        }

        $invoiceNo = 'INV-' . date('Y') . '-' . str_pad((string) (random_int(1, 999999)), 6, '0', STR_PAD_LEFT);
        $pdo->prepare(
            'INSERT INTO invoices (student_id, term_id, invoice_no, total_amount, amount_paid, balance, due_date, status)
             VALUES (:sid, :term, :no, :total, 0, :total2, :due, "unpaid")'
        )->execute([
            'sid' => $sid, 'term' => $termId, 'no' => $invoiceNo, 'total' => $totalAmount, 'total2' => $totalAmount,
            'due' => (new DateTime())->modify('+30 days')->format('Y-m-d'),
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        foreach ($feeItems as $item) {
            $pdo->prepare(
                'INSERT INTO invoice_items (invoice_id, fee_category_id, description, amount) VALUES (:iid, :fc, :desc, :amt)'
            )->execute(['iid' => $invoiceId, 'fc' => $item['fee_category_id'], 'desc' => null, 'amt' => $item['amount']]);
        }
        $created++;
    }

    audit_log('generate_invoices', 'finance', 'invoices', null, "Bulk-generated {$created} invoices");
    flash_set('success', "Generated {$created} new invoice(s). Students who already had an invoice for this term were skipped.");
    redirect(app_url('/bursar/fee_structures.php'));
}

$structures = $pdo->query(
    "SELECT fs.*, cl.level_name, fc.category_name, t.term_name, y.year_name
     FROM fee_structures fs
     JOIN class_levels cl ON cl.class_level_id = fs.class_level_id
     JOIN fee_categories fc ON fc.fee_category_id = fs.fee_category_id
     JOIN terms t ON t.term_id = fs.term_id
     JOIN academic_years y ON y.year_id = t.year_id
     ORDER BY y.year_name DESC, t.term_name, cl.sort_order"
)->fetchAll();

$classLevels = $pdo->query('SELECT * FROM class_levels ORDER BY sort_order')->fetchAll();
$feeCategories = $pdo->query('SELECT * FROM fee_categories ORDER BY category_name')->fetchAll();
$terms = $pdo->query('SELECT t.*, y.year_name FROM terms t JOIN academic_years y ON y.year_id = t.year_id ORDER BY t.start_date DESC')->fetchAll();

$pageTitle = 'Fee Structures';
require APP_ROOT . '/includes/header.php';
?>

<h1 class="h3 mb-4">Fee Structures</h1>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Add / Update Fee Structure</div>
      <div class="card-body">
        <form method="POST">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="add_structure">
          <div class="mb-2">
            <label class="form-label">Class Level</label>
            <select name="class_level_id" class="form-select" required>
              <?php foreach ($classLevels as $cl): ?><option value="<?= (int) $cl['class_level_id'] ?>"><?= e($cl['level_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Fee Category</label>
            <select name="fee_category_id" class="form-select" required>
              <?php foreach ($feeCategories as $fc): ?><option value="<?= (int) $fc['fee_category_id'] ?>"><?= e($fc['category_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Term</label>
            <select name="term_id" class="form-select" required>
              <?php foreach ($terms as $t): ?><option value="<?= (int) $t['term_id'] ?>"><?= e($t['year_name'] . ' - ' . $t['term_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control" placeholder="e.g. Term 1 School Fees" maxlength="150">
          </div>
          <div class="mb-3">
            <label class="form-label">Amount (TZS)</label>
            <input type="number" name="amount" class="form-control" min="0" step="1000" required>
          </div>
          <button type="submit" class="btn btn-primary w-100">Save Fee Structure</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Generate Invoices in Bulk</div>
      <div class="card-body">
        <p class="text-muted small">Creates an invoice for every active student in the selected class level for the selected term, based on the fee structure above. Students who already have an invoice for that term are skipped.</p>
        <form method="POST" data-confirm="Generate invoices for all active students in this class level and term?">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="generate_invoices">
          <div class="mb-2">
            <label class="form-label">Class Level</label>
            <select name="gen_class_level_id" class="form-select" required>
              <?php foreach ($classLevels as $cl): ?><option value="<?= (int) $cl['class_level_id'] ?>"><?= e($cl['level_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Term</label>
            <select name="gen_term_id" class="form-select" required>
              <?php foreach ($terms as $t): ?><option value="<?= (int) $t['term_id'] ?>"><?= e($t['year_name'] . ' - ' . $t['term_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-gold w-100"><i class="fa fa-bolt me-1"></i> Generate Invoices</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Current Fee Structures</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Year / Term</th><th>Class Level</th><th>Category</th><th>Amount</th></tr></thead>
      <tbody>
        <?php foreach ($structures as $s): ?>
          <tr>
            <td><?= e($s['year_name'] . ' - ' . $s['term_name']) ?></td>
            <td><?= e($s['level_name']) ?></td>
            <td><?= e($s['category_name']) ?></td>
            <td class="fw-semibold"><?= format_money($s['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($structures)): ?><tr><td colspan="4" class="text-center text-muted py-4">No fee structures defined yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
