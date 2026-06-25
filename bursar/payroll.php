<?php
/**
 * bursar/payroll.php - COMPLETE REWRITE for reliability
 */
require_once __DIR__ . '/../config/config.php';
require_role(['bursar']);
$pdo = get_db_connection();

$runId = (int) ($_GET['run_id'] ?? 0);
$psId = (int) ($_GET['ps_id'] ?? 0);

// ========== HANDLE ALL POST ACTIONS HERE (BEFORE ANY HTML OUTPUT) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = $_POST['cmd'] ?? '';
    
    // CREATE RUN
    if ($cmd === 'create') {
        csrf_verify();
        $m = (int)($_POST['month'] ?? 0);
        $y = (int)($_POST['year'] ?? 0);
        if ($m >= 1 && $m <= 12 && $y >= 2020) {
            $ex = $pdo->prepare("SELECT payroll_run_id FROM payroll_runs WHERE pay_period_month=? AND pay_period_year=?");
            $ex->execute([$m, $y]);
            if (!$ex->fetch()) {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO payroll_runs (pay_period_month,pay_period_year,status,created_by) VALUES(?,?,'draft',?)")->execute([$m, $y, current_user_id()]);
                    $rid = (int)$pdo->lastInsertId();
                    $staff = $pdo->query("SELECT staff_id,basic_salary FROM staff WHERE status='active'")->fetchAll();
                    foreach ($staff as $s) {
                        $pdo->prepare("INSERT INTO payslips (payroll_run_id,staff_id,basic_salary,total_allowances,total_deductions,net_pay) VALUES(?,?,?,0,0,?)")->execute([$rid, $s['staff_id'], $s['basic_salary'], $s['basic_salary']]);
                    }
                    $pdo->commit();
                    $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Run created with ' . count($staff) . ' payslips.'];
                    header('Location: ' . app_url('/bursar/payroll.php') . '?run_id=' . $rid);
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
                    header('Location: ' . app_url('/bursar/payroll.php'));
                    exit;
                }
            } else {
                $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Run already exists for this period.'];
                header('Location: ' . app_url('/bursar/payroll.php'));
                exit;
            }
        }
    }
    
    // DELETE RUN
    if ($cmd === 'delete') {
        csrf_verify();
        $rid = (int)($_POST['rid'] ?? 0);
        $pdo->prepare("DELETE FROM payroll_runs WHERE payroll_run_id=? AND status='draft'")->execute([$rid]);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Run deleted.'];
        header('Location: ' . app_url('/bursar/payroll.php'));
        exit;
    }
    
    // APPROVE RUN
    if ($cmd === 'approve') {
        csrf_verify();
        $rid = (int)($_POST['rid'] ?? 0);
        $pdo->prepare("UPDATE payroll_runs SET status='approved',approved_by=? WHERE payroll_run_id=?")->execute([current_user_id(), $rid]);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Run approved.'];
        header('Location: ' . app_url('/bursar/payroll.php') . '?run_id=' . $rid);
        exit;
    }
    
    // MARK PAID
    if ($cmd === 'paid') {
        csrf_verify();
        $rid = (int)($_POST['rid'] ?? 0);
        $pdo->prepare("UPDATE payroll_runs SET status='paid' WHERE payroll_run_id=?")->execute([$rid]);
        $pdo->prepare("UPDATE payslips SET status='paid',paid_at=NOW() WHERE payroll_run_id=?")->execute([$rid]);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Run marked paid.'];
        header('Location: ' . app_url('/bursar/payroll.php') . '?run_id=' . $rid);
        exit;
    }
    
    // EDIT SALARY
    if ($cmd === 'salary') {
        csrf_verify();
        $pid = (int)($_POST['pid'] ?? 0);
        $sal = (float)($_POST['basic'] ?? 0);
        if ($pid > 0 && $sal > 0) {
            $pdo->prepare("UPDATE payslips SET basic_salary=? WHERE payslip_id=?")->execute([$sal, $pid]);
            // Recalc
            $ps = $pdo->prepare("SELECT * FROM payslips WHERE payslip_id=?");
            $ps->execute([$pid]);
            $p = $ps->fetch();
            if ($p) {
                $it = $pdo->prepare("SELECT * FROM payslip_items WHERE payslip_id=?");
                $it->execute([$pid]);
                $all = $it->fetchAll();
                $a = 0; $d = 0;
                foreach ($all as $i) { if ($i['item_type'] === 'allowance') $a += (float)$i['amount']; else $d += (float)$i['amount']; }
                $net = $sal + $a - $d;
                $pdo->prepare("UPDATE payslips SET total_allowances=?,total_deductions=?,net_pay=? WHERE payslip_id=?")->execute([$a, $d, max($net, 0), $pid]);
            }
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Salary updated.'];
        }
        $r = $pdo->prepare("SELECT payroll_run_id FROM payslips WHERE payslip_id=?");
        $r->execute([$pid]);
        header('Location: ' . app_url('/bursar/payroll.php') . '?run_id=' . $r->fetchColumn() . '&ps_id=' . $pid);
        exit;
    }
    
    // ADD ITEM
    if ($cmd === 'additem') {
        csrf_verify();
        $pid = (int)($_POST['pid'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'allowance';
        $amt = (float)($_POST['amt'] ?? 0);
        if ($pid > 0 && $name && $amt > 0) {
            $pdo->prepare("INSERT INTO payslip_items (payslip_id,item_type,item_name,amount) VALUES(?,?,?,?)")->execute([$pid, $type, $name, $amt]);
            // Recalc
            $ps = $pdo->prepare("SELECT * FROM payslips WHERE payslip_id=?");
            $ps->execute([$pid]);
            $p = $ps->fetch();
            if ($p) {
                $it = $pdo->prepare("SELECT * FROM payslip_items WHERE payslip_id=?");
                $it->execute([$pid]);
                $all = $it->fetchAll();
                $a = 0; $d = 0;
                foreach ($all as $i) { if ($i['item_type'] === 'allowance') $a += (float)$i['amount']; else $d += (float)$i['amount']; }
                $net = (float)$p['basic_salary'] + $a - $d;
                $pdo->prepare("UPDATE payslips SET total_allowances=?,total_deductions=?,net_pay=? WHERE payslip_id=?")->execute([$a, $d, max($net, 0), $pid]);
            }
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Item added.'];
        } else {
            $_SESSION['flash'][] = ['type' => 'error', 'message' => 'Name and amount required.'];
        }
        $r = $pdo->prepare("SELECT payroll_run_id FROM payslips WHERE payslip_id=?");
        $r->execute([$pid]);
        header('Location: ' . app_url('/bursar/payroll.php') . '?run_id=' . $r->fetchColumn() . '&ps_id=' . $pid);
        exit;
    }
    
    // DELETE ITEM
    if ($cmd === 'delitem') {
        csrf_verify();
        $iid = (int)($_POST['iid'] ?? 0);
        $r = $pdo->prepare("SELECT payslip_id FROM payslip_items WHERE payslip_item_id=?");
        $r->execute([$iid]);
        $pid = (int)$r->fetchColumn();
        if ($pid > 0) {
            $pdo->prepare("DELETE FROM payslip_items WHERE payslip_item_id=?")->execute([$iid]);
            // Recalc
            $ps = $pdo->prepare("SELECT * FROM payslips WHERE payslip_id=?");
            $ps->execute([$pid]);
            $p = $ps->fetch();
            if ($p) {
                $it = $pdo->prepare("SELECT * FROM payslip_items WHERE payslip_id=?");
                $it->execute([$pid]);
                $all = $it->fetchAll();
                $a = 0; $d = 0;
                foreach ($all as $i) { if ($i['item_type'] === 'allowance') $a += (float)$i['amount']; else $d += (float)$i['amount']; }
                $net = (float)$p['basic_salary'] + $a - $d;
                $pdo->prepare("UPDATE payslips SET total_allowances=?,total_deductions=?,net_pay=? WHERE payslip_id=?")->execute([$a, $d, max($net, 0), $pid]);
            }
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Item removed.'];
        }
        $r = $pdo->prepare("SELECT payroll_run_id FROM payslips WHERE payslip_id=?");
        $r->execute([$pid]);
        header('Location: ' . app_url('/bursar/payroll.php') . '?run_id=' . $r->fetchColumn() . '&ps_id=' . $pid);
        exit;
    }
}

// ========== HANDLE PRINT ==========
if ($psId > 0 && ($_GET['act'] ?? '') === 'print') {
    $p = $pdo->prepare("SELECT ps.*,u.first_name,u.last_name,st.staff_no,st.job_title,st.basic_salary,st.bank_name,st.bank_account_no,st.bank_branch,st.tin_number,st.nssf_number,st.national_id,st.date_hired,d.department_name,pr.pay_period_month,pr.pay_period_year FROM payslips ps JOIN staff st ON st.staff_id=ps.staff_id JOIN users u ON u.user_id=st.user_id JOIN payroll_runs pr ON pr.payroll_run_id=ps.payroll_run_id LEFT JOIN departments d ON d.department_id=st.department_id WHERE ps.payslip_id=?");
    $p->execute([$psId]);
    $ps = $p->fetch();
    if (!$ps) { header('Location: ' . app_url('/bursar/payroll.php')); exit; }
    $it = $pdo->prepare("SELECT * FROM payslip_items WHERE payslip_id=? ORDER BY item_type");
    $it->execute([$psId]);
    $items = $it->fetchAll();
    $sn = get_setting($pdo, 'school_name', 'School');
    $mn = date('F', mktime(0, 0, 0, $ps['pay_period_month'], 1));
    ?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Payslip</title><style>
    body{font-family:'Courier New',monospace;font-size:12px;margin:20px;}.header{text-align:center;margin-bottom:20px;border-bottom:2px solid #000;padding-bottom:10px;}
    h1{font-size:18px;margin:0;}table{width:100%;border-collapse:collapse;margin:8px 0;}th{background:#000;color:#fff;padding:5px 6px;text-align:left;font-size:10px;}
    td{padding:4px 6px;border-bottom:1px solid #ccc;font-size:11px;}.label{font-weight:bold;width:160px;}.text-end{text-align:right;}
    .bank-info{background:#f5f5f5;padding:8px;border-radius:4px;margin:8px 0;}@media print{body{margin:0;}.no-print{display:none;}}
    </style></head><body>
    <div class="no-print" style="text-align:center;margin:10px;"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
    <div class="header"><h1><?=e($sn)?></h1><p>PAYSLIP - <?=e($mn)?> <?=(int)$ps['pay_period_year']?></p></div>
    <table><tr><td class="label">Employee:</td><td><?=e($ps['first_name'].' '.$ps['last_name'])?></td><td class="label">Staff:</td><td><?=e($ps['staff_no'])?></td></tr>
    <tr><td class="label">Dept:</td><td><?=e($ps['department_name']??'N/A')?></td><td class="label">Title:</td><td><?=e($ps['job_title']??'N/A')?></td></tr>
    <tr><td class="label">TIN:</td><td><?=e($ps['tin_number']??'N/A')?></td><td class="label">NSSF:</td><td><?=e($ps['nssf_number']??'N/A')?></td></tr></table>
    <div class="bank-info"><strong>Bank:</strong> <?=e($ps['bank_name']??'N/A')?> | <strong>Account:</strong> <?=e($ps['bank_account_no']??'N/A')?> | <strong>Branch:</strong> <?=e($ps['bank_branch']??'N/A')?></div>
    <h4>EARNINGS</h4><table><thead><tr><th>Item</th><th class="text-end">Amount</th></tr></thead><tbody><tr><td>Basic Salary</td><td class="text-end"><?=number_format((float)$ps['basic_salary'],2)?></td></tr>
    <?php foreach($items as $i){if($i['item_type']!=='allowance')continue;?><tr><td><?=e($i['item_name'])?></td><td class="text-end text-success"><?=number_format((float)$i['amount'],2)?></td></tr><?php }?></tbody></table>
    <h4>DEDUCTIONS</h4><table><thead><tr><th>Item</th><th class="text-end">Amount</th></tr></thead><tbody>
    <?php $hd=false;foreach($items as $i){if($i['item_type']!=='deduction')continue;$hd=true;?><tr><td><?=e($i['item_name'])?></td><td class="text-end text-danger"><?=number_format((float)$i['amount'],2)?></td></tr><?php }if(!$hd){?><tr><td colspan="2" class="text-muted">None</td></tr><?php }?></tbody></table>
    <table style="margin-top:10px;"><tr style="font-weight:bold;font-size:14px;"><td>NET PAY</td><td class="text-end"><?=number_format((float)$ps['net_pay'],2)?></td></tr></table>
    <div class="footer" style="text-align:center;margin-top:20px;font-size:9px;color:#666;"><p><?=e($sn)?> | <?=date('d M Y H:i')?></p></div><script>window.print();</script></body></html>
    <?php exit;
}

// ========== HANDLE BANK EXPORT ==========
if ($runId > 0 && ($_GET['act'] ?? '') === 'bank') {
    $s = $pdo->prepare("SELECT ps.*,u.first_name,u.last_name,st.staff_no,st.bank_name,st.bank_account_no,st.bank_branch FROM payslips ps JOIN staff st ON st.staff_id=ps.staff_id JOIN users u ON u.user_id=st.user_id WHERE ps.payroll_run_id=? AND ps.net_pay>0 ORDER BY u.first_name");
    $s->execute([$runId]);
    $all = $s->fetchAll();
    header('Content-Type:text/csv;charset=utf-8');
    header('Content-Disposition:attachment;filename="bank_run_' . $runId . '.csv"');
    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($fp, ['Staff No', 'Name', 'Bank', 'Account', 'Branch', 'Net Pay']);
    foreach ($all as $p) fputcsv($fp, [$p['staff_no'], $p['first_name'] . ' ' . $p['last_name'], $p['bank_name'] ?? 'N/A', $p['bank_account_no'] ?? 'N/A', $p['bank_branch'] ?? 'N/A', number_format((float)$p['net_pay'], 2)]);
    fclose($fp);
    exit;
}

// ========== GET DATA ==========
$runs = $pdo->query("SELECT pr.*,COUNT(ps.payslip_id) AS sc,COALESCE(SUM(ps.net_pay),0) AS tp FROM payroll_runs pr LEFT JOIN payslips ps ON ps.payroll_run_id=pr.payroll_run_id GROUP BY pr.payroll_run_id ORDER BY pr.pay_period_year DESC,pr.pay_period_month DESC")->fetchAll();
$payslips = [];
$runInfo = null;
if ($runId > 0) {
    $s = $pdo->prepare("SELECT ps.*,u.first_name,u.last_name,st.staff_no,st.job_title,d.department_name FROM payslips ps JOIN staff st ON st.staff_id=ps.staff_id JOIN users u ON u.user_id=st.user_id LEFT JOIN departments d ON d.department_id=st.department_id WHERE ps.payroll_run_id=? ORDER BY u.first_name");
    $s->execute([$runId]);
    $payslips = $s->fetchAll();
    $r = $pdo->prepare("SELECT * FROM payroll_runs WHERE payroll_run_id=?");
    $r->execute([$runId]);
    $runInfo = $r->fetch();
}
$psDetail = null;
$psItems = [];
$allowTypes = [];
$dedTypes = [];
if ($psId > 0) {
    $s = $pdo->prepare("SELECT ps.*,u.first_name,u.last_name,st.staff_no,st.job_title,st.basic_salary,st.bank_name,st.bank_account_no,st.bank_branch,st.tin_number,st.nssf_number,st.national_id,st.date_hired,st.employment_type,d.department_name,pr.pay_period_month,pr.pay_period_year,pr.status AS run_status FROM payslips ps JOIN staff st ON st.staff_id=ps.staff_id JOIN users u ON u.user_id=st.user_id JOIN payroll_runs pr ON pr.payroll_run_id=ps.payroll_run_id LEFT JOIN departments d ON d.department_id=st.department_id WHERE ps.payslip_id=?");
    $s->execute([$psId]);
    $psDetail = $s->fetch();
    if ($psDetail) {
        $i = $pdo->prepare("SELECT * FROM payslip_items WHERE payslip_id=? ORDER BY item_type,payslip_item_id");
        $i->execute([$psId]);
        $psItems = $i->fetchAll();
    }
    $allowTypes = $pdo->query("SELECT * FROM payroll_item_types WHERE item_category='allowance' ORDER BY sort_order")->fetchAll();
    $dedTypes = $pdo->query("SELECT * FROM payroll_item_types WHERE item_category='deduction' ORDER BY sort_order")->fetchAll();
}
$activeStaff = $pdo->query("SELECT COUNT(*) FROM staff WHERE status='active'")->fetchColumn();
$totalPayroll = $pdo->query("SELECT COALESCE(SUM(basic_salary),0) FROM staff WHERE status='active'")->fetchColumn();

$pageTitle = 'Payroll Management';
require APP_ROOT . '/includes/header.php';
?>
<style>
.status-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.status-draft{background:#e2e8f0;color:#475569;}
.status-approved{background:#fef3c7;color:#92400e;}
.status-paid{background:#d1fae5;color:#065f46;}
.bank-info{background:#f0f4f8;border-left:4px solid #1F8A55;padding:12px;border-radius:6px;margin-bottom:12px;}
.item-a{border-left:3px solid #1F8A55;}
.item-d{border-left:3px solid #C23B3B;}
/* Compact table to prevent horizontal scroll */
.table-sm th,.table-sm td{white-space:nowrap;padding:3px 4px!important;font-size:11px;}
.table-sm .btn-sm{font-size:10px;padding:1px 4px;}
.table-sm code{font-size:9px;}
.table-sm .fw-bold{font-size:11px;}
/* Hide less important columns on small screens */
@media(max-width:1400px){
  .payslip-table-wrap{overflow-x:auto;width:100%;}
  .col-md-4,.col-md-8{flex:0 0 100%;max-width:100%;}
  .hide-md{display:none!important;}
}
@media(max-width:1200px){
  .hide-sm{display:none!important;}
}
</style>
<div class="payslip-table-wrap">
<?php if ($msg = flash_get_all()) { foreach ($msg as $f) { ?><div class="alert alert-<?= $f['type'] ?> alert-dismissible fade show"><?= e($f['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } } ?>
<?php if ($runId > 0): ?><div class="small text-muted mb-2">Debug: run_id=<?=$runId?>, payslips found=<?=count($payslips)?>, runInfo=<?=$runInfo?'yes':'no'?></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div><h1 class="h3 mb-0">Payroll <span class="badge bg-gold ms-2">Bursar</span></h1><p class="mb-0 text-muted small"><?= $activeStaff ?> active staff - Monthly: <?= format_money($totalPayroll) ?></p></div>
  <div><a href="<?= e(app_url('/bursar/dashboard.php')) ?>" class="btn btn-sm btn-outline-primary">Dashboard</a></div>
</div>

<div class="row g-3">
  <!-- LEFT -->
  <div class="col-md-4">
    <div class="card mb-3">
      <div class="card-header py-2"><i class="fa fa-plus-circle text-gold me-1"></i> New Payroll Run</div>
      <div class="card-body py-2">
        <form method="POST">
          <?php csrf_field(); ?>
          <input type="hidden" name="cmd" value="create">
          <div class="row g-1">
            <div class="col-5"><select name="month" class="form-select form-select-sm"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= $m == date('n') ? ' selected' : '' ?>><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option><?php endfor; ?></select></div>
            <div class="col-4"><input type="number" name="year" class="form-control form-control-sm" value="<?= date('Y') ?>" min="2020" max="2100"></div>
            <div class="col-3"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa fa-plus"></i> Add</button></div>
          </div>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header py-2"><i class="fa fa-history text-gold me-1"></i> Payroll Runs</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Period</th><th>Staff</th><th>Total</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($runs as $r): ?>
              <tr class="<?= $runId === $r['payroll_run_id'] ? 'table-active' : '' ?>">
                <td class="fw-semibold"><?= date('M Y', mktime(0, 0, 0, $r['pay_period_month'], 1, $r['pay_period_year'])) ?></td>
                <td><?= $r['sc'] ?></td>
                <td class="small"><?= format_money($r['tp']) ?></td>
                <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
                <td class="text-nowrap">
                  <a href="?run_id=<?= $r['payroll_run_id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="View Payslips"><i class="fa fa-eye"></i></a>
                  <?php if ($r['status'] === 'draft'): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Approve this run?')"><?php csrf_field(); ?><input type="hidden" name="cmd" value="approve"><input type="hidden" name="rid" value="<?= $r['payroll_run_id'] ?>"><button class="btn btn-sm btn-outline-success py-0 px-1"><i class="fa fa-check"></i></button></form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this draft run?')"><?php csrf_field(); ?><input type="hidden" name="cmd" value="delete"><input type="hidden" name="rid" value="<?= $r['payroll_run_id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-1"><i class="fa fa-trash"></i></button></form>
                  <?php elseif ($r['status'] === 'approved'): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Mark as paid?')"><?php csrf_field(); ?><input type="hidden" name="cmd" value="paid"><input type="hidden" name="rid" value="<?= $r['payroll_run_id'] ?>"><button class="btn btn-sm btn-gold py-0 px-1"><i class="fa fa-check-circle"></i></button></form>
                  <?php elseif ($r['status'] === 'paid'): ?>
                    <a href="?run_id=<?= $r['payroll_run_id'] ?>&act=bank" class="btn btn-sm btn-outline-success py-0 px-1"><i class="fa fa-file-excel"></i></a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($runs)): ?><tr><td colspan="5" class="text-center text-muted py-3">No runs yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- RIGHT -->
  <div class="col-md-8">
    <?php if ($runId > 0 && !$psId): ?>
      <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <span><i class="fa fa-users text-gold me-1"></i> Payslips <?php if ($runInfo): ?>- <?= date('F Y', mktime(0, 0, 0, $runInfo['pay_period_month'], 1, $runInfo['pay_period_year'])) ?> <span class="status-badge status-<?= $runInfo['status'] ?>"><?= ucfirst($runInfo['status']) ?></span><?php endif; ?></span>
          <div><a href="<?= e(app_url('/bursar/payroll.php')) ?>" class="btn btn-sm btn-outline-secondary">Close</a></div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead><tr><th>Staff</th><th>Name</th><th class="hide-md">Dept</th><th class="text-end">Basic</th><th class="text-end hide-sm">Allow</th><th class="text-end hide-sm">Deduct</th><th class="text-end">Net</th><th class="hide-sm">Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($payslips as $p): ?>
                <tr>
                  <td><code><?= e($p['staff_no']) ?></code></td>
                  <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                  <td class="small"><?= e($p['department_name'] ?? '-') ?></td>
                  <td class="text-end"><?= format_money($p['basic_salary']) ?></td>
                  <td class="text-end text-success"><?= format_money($p['total_allowances']) ?></td>
                  <td class="text-end text-danger"><?= format_money($p['total_deductions']) ?></td>
                  <td class="text-end fw-bold"><?= format_money($p['net_pay']) ?></td>
                  <td><span class="status-badge status-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
                  <td><a href="?run_id=<?= $runId ?>&ps_id=<?= $p['payslip_id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1"><i class="fa fa-edit"></i></a>
                    <a href="?ps_id=<?= $p['payslip_id'] ?>&act=print" class="btn btn-sm btn-outline-secondary py-0 px-1" target="_blank"><i class="fa fa-print"></i></a></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($payslips)): ?><tr><td colspan="9" class="text-center text-muted py-4">No payslips.</td></tr><?php endif; ?>
            </tbody>
            <?php if (!empty($payslips)): ?>
              <tfoot><tr class="fw-bold"><td colspan="3">Totals</td><td class="text-end"><?= format_money(array_sum(array_column($payslips, 'basic_salary'))) ?></td><td class="text-end"><?= format_money(array_sum(array_column($payslips, 'total_allowances'))) ?></td><td class="text-end"><?= format_money(array_sum(array_column($payslips, 'total_deductions'))) ?></td><td class="text-end"><?= format_money(array_sum(array_column($payslips, 'net_pay'))) ?></td><td colspan="2"></td></tr></tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

    <?php elseif ($psId > 0 && $psDetail): ?>
      <?php $ps = $psDetail; ?>
      <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
          <span><i class="fa fa-file-invoice text-gold me-1"></i> <?= e($ps['first_name'] . ' ' . $ps['last_name']) ?> <code class="ms-1"><?= e($ps['staff_no']) ?></code></span>
          <div><a href="?ps_id=<?= $psId ?>&act=print" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fa fa-print"></i> Print</a> <a href="?run_id=<?= $runId ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-arrow-left"></i> Back</a></div>
        </div>
        <div class="card-body">
          <div class="row g-1 mb-2 small">
            <div class="col-md-3"><strong>Dept:</strong> <?= e($ps['department_name'] ?? 'N/A') ?></div>
            <div class="col-md-3"><strong>Title:</strong> <?= e($ps['job_title'] ?? 'N/A') ?></div>
            <div class="col-md-3"><strong>Period:</strong> <?= date('F Y', mktime(0, 0, 0, $ps['pay_period_month'], 1, $ps['pay_period_year'])) ?></div>
            <div class="col-md-3"><strong>Status:</strong> <span class="status-badge status-<?= $ps['run_status'] ?>"><?= ucfirst($ps['run_status']) ?></span></div>
          </div>
          <div class="bank-info"><strong>Bank:</strong> <?= e($ps['bank_name'] ?? 'Not set') ?> | <strong>Account:</strong> <?= e($ps['bank_account_no'] ?? 'Not set') ?> | <strong>Branch:</strong> <?= e($ps['bank_branch'] ?? 'Not set') ?> | <strong>TIN:</strong> <?= e($ps['tin_number'] ?? '-') ?> | <strong>NSSF:</strong> <?= e($ps['nssf_number'] ?? '-') ?></div>
          <div class="row g-3">
            <div class="col-md-7">
              <div class="card">
                <div class="card-header py-1 d-flex justify-content-between"><span>Itemized Breakdown</span>
                  <?php if ($ps['run_status'] !== 'paid'): ?>
                    <form method="POST" style="display:inline"><?php csrf_field(); ?><input type="hidden" name="cmd" value="salary"><input type="hidden" name="pid" value="<?= $psId ?>">
                      <div class="input-group input-group-sm" style="width:180px;"><span class="input-group-text">Basic</span><input type="number" name="basic" class="form-control form-control-sm" value="<?= (float)$ps['basic_salary'] ?>" step="1000" min="0"><button class="btn btn-outline-primary btn-sm" type="submit"><i class="fa fa-save"></i></button></div></form>
                  <?php endif; ?>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm mb-0">
                    <thead><tr><th>Type</th><th>Item</th><th class="text-end">Amount</th><?php if ($ps['run_status'] !== 'paid'): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                      <tr class="item-a"><td><span class="badge bg-success">B</span></td><td>Basic Salary</td><td class="text-end fw-bold"><?= format_money($ps['basic_salary']) ?></td><?php if ($ps['run_status'] !== 'paid'): ?><td></td><?php endif; ?></tr>
                      <?php foreach ($psItems as $item): ?>
                        <tr class="<?= $item['item_type'] === 'allowance' ? 'item-a' : 'item-d' ?>">
                          <td><span class="badge bg-<?= $item['item_type'] === 'allowance' ? 'success' : 'danger' ?>"><?= ucfirst($item['item_type'][0]) ?></span></td>
                          <td><?= e($item['item_name']) ?></td>
                          <td class="text-end <?= $item['item_type'] === 'allowance' ? 'text-success' : 'text-danger' ?>"><?= format_money($item['amount']) ?></td>
                          <?php if ($ps['run_status'] !== 'paid'): ?><td><form method="POST" style="display:inline" onsubmit="return confirm('Remove this item?')"><?php csrf_field(); ?><input type="hidden" name="cmd" value="delitem"><input type="hidden" name="iid" value="<?= $item['payslip_item_id'] ?>"><button class="btn btn-sm btn-link text-danger p-0"><i class="fa fa-times"></i></button></form></td><?php endif; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                      <tr class="fw-bold"><td colspan="2">Total Allowances</td><td class="text-end text-success"><?= format_money($ps['total_allowances']) ?></td><?php if ($ps['run_status'] !== 'paid'): ?><td></td><?php endif; ?></tr>
                      <tr class="fw-bold"><td colspan="2">Total Deductions</td><td class="text-end text-danger"><?= format_money($ps['total_deductions']) ?></td><?php if ($ps['run_status'] !== 'paid'): ?><td></td><?php endif; ?></tr>
                      <tr class="fw-bold" style="border-top:2px solid #000;font-size:1.1rem;"><td colspan="2">NET PAY</td><td class="text-end"><?= format_money($ps['net_pay']) ?></td><?php if ($ps['run_status'] !== 'paid'): ?><td></td><?php endif; ?></tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>
            <?php if ($ps['run_status'] !== 'paid'): ?>
              <div class="col-md-5">
                <div class="card mb-2">
                  <div class="card-header py-1"><i class="fa fa-plus-circle text-success me-1"></i> Add Allowance</div>
                  <div class="card-body py-2">
                    <form method="POST"><?php csrf_field(); ?><input type="hidden" name="cmd" value="additem"><input type="hidden" name="pid" value="<?= $psId ?>"><input type="hidden" name="type" value="allowance">
                      <div class="row g-1"><div class="col-7"><select name="name" class="form-select form-select-sm" onchange="document.getElementById('aAmt').value=this.options[this.selectedIndex].dataset.amount||''"><option value="">Select</option><?php foreach ($allowTypes as $at): ?><option value="<?= e($at['item_name']) ?>" data-amount="<?= (float)$at['default_amount'] ?>"><?= e($at['item_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-3"><input type="number" name="amt" id="aAmt" class="form-control form-control-sm" min="1" step="100" required placeholder="Amount"></div>
                        <div class="col-2"><button class="btn btn-success btn-sm w-100" type="submit"><i class="fa fa-plus"></i></button></div></div></form>
                  </div>
                </div>
                <div class="card mb-2">
                  <div class="card-header py-1"><i class="fa fa-minus-circle text-danger me-1"></i> Add Deduction</div>
                  <div class="card-body py-2">
                    <form method="POST"><?php csrf_field(); ?><input type="hidden" name="cmd" value="additem"><input type="hidden" name="pid" value="<?= $psId ?>"><input type="hidden" name="type" value="deduction">
                      <div class="row g-1"><div class="col-7"><select name="name" class="form-select form-select-sm" onchange="document.getElementById('dAmt').value=this.options[this.selectedIndex].dataset.amount||''"><option value="">Select</option><?php foreach ($dedTypes as $dt): ?><option value="<?= e($dt['item_name']) ?>" data-amount="<?= (float)$dt['default_amount'] ?>"><?= e($dt['item_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-3"><input type="number" name="amt" id="dAmt" class="form-control form-control-sm" min="1" step="100" required placeholder="Amount"></div>
                        <div class="col-2"><button class="btn btn-danger btn-sm w-100" type="submit"><i class="fa fa-plus"></i></button></div></div></form>
                  </div>
                </div>
                <div class="card"><div class="card-body py-1 small">Type: <?= e(ucfirst(str_replace('_', ' ', $ps['employment_type'] ?? 'N/A'))) ?> | Hired: <?= format_date($ps['date_hired']) ?></div></div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    <?php else: ?>
      <div class="card"><div class="card-body text-center py-5"><i class="fa fa-wallet fa-3x text-muted mb-3"></i><h5>Payroll Management</h5><p class="text-muted">Select a run from the left to view employee payslips.</p></div></div>
    <?php endif; ?>
  </div>
</div>

</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
