<?php
/**
 * bursar/gateway_settings.php
 * Payment Gateway Configuration for Bursar and Director.
 * Configure external payment providers and view transactions.
 */
require_once __DIR__ . '/../config/config.php';
require_role(['director', 'system_admin', 'bursar']);

$pdo = get_db_connection();

// ---- Update Gateway Settings -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_gateway') {
    csrf_verify();
    $gatewayId = (int) ($_POST['gateway_id'] ?? 0);
    $apiKey = trim($_POST['api_key'] ?? '');
    $apiSecret = trim($_POST['api_secret'] ?? '');
    $apiEndpoint = trim($_POST['api_endpoint'] ?? '');
    $merchantCode = trim($_POST['merchant_code'] ?? '');
    $callbackUrl = trim($_POST['callback_url'] ?? '');
    $isActive = (int) ($_POST['is_active'] ?? 0);
    $configJson = trim($_POST['config_json'] ?? '');

    if ($gatewayId > 0) {
        $pdo->prepare(
            'UPDATE payment_gateways SET api_key = :key, api_secret = :secret, api_endpoint = :endpoint,
             merchant_code = :code, callback_url = :cb, is_active = :active, config_json = :cfg
             WHERE gateway_id = :id'
        )->execute([
            'key' => $apiKey ?: null, 'secret' => $apiSecret ?: null,
            'endpoint' => $apiEndpoint ?: null, 'code' => $merchantCode ?: null,
            'cb' => $callbackUrl ?: null, 'active' => $isActive, 'cfg' => $configJson ?: null,
            'id' => $gatewayId,
        ]);
        
        // Deactivate all other gateways if this one is active
        if ($isActive) {
            $pdo->prepare('UPDATE payment_gateways SET is_active = 0 WHERE gateway_id != :id')->execute(['id' => $gatewayId]);
        }
        
        audit_log('update_gateway', 'payment_settings', 'payment_gateways', $gatewayId, 'Updated payment gateway configuration');
        flash_set('success', 'Payment gateway updated successfully.');
    }
    redirect(app_url('/bursar/gateway_settings.php'));
}

$gateways = $pdo->query('SELECT * FROM payment_gateways ORDER BY gateway_name')->fetchAll();

// Get recent transactions
$recentTransactions = $pdo->query(
    "SELECT pt.*, CONCAT(u.first_name, ' ', u.last_name) AS parent_name,
            CONCAT(s2.first_name, ' ', s2.last_name) AS student_name
     FROM payment_transactions pt
     LEFT JOIN users u ON u.user_id = pt.initiated_by
     LEFT JOIN students st ON st.student_id = pt.student_id
     LEFT JOIN users s2 ON s2.user_id = st.user_id
     ORDER BY pt.created_at DESC LIMIT 20"
)->fetchAll();

$pageTitle = 'Payment Gateway Settings';
require APP_ROOT . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="fa fa-credit-card text-gold me-2"></i>Payment Gateway Settings</h1>
</div>

<div class="row g-4">
  <div class="col-md-7">
    <div class="card">
      <div class="card-header"><h5 class="mb-0">Configure Payment Gateways</h5></div>
      <div class="card-body">
        <p class="text-muted small">Configure payment providers for online fee collection. Only one gateway can be active at a time.</p>
        <?php foreach ($gateways as $gw): ?>
          <form method="POST" class="mb-4 p-3 border rounded">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_gateway">
            <input type="hidden" name="gateway_id" value="<?= (int) $gw['gateway_id'] ?>">
            
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="fw-bold mb-0 text-uppercase"><?= e(str_replace('_', ' ', $gw['gateway_name'])) ?></h6>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $gw['is_active'] ? 'checked' : '' ?>>
                <span class="badge bg-<?= $gw['is_active'] ? 'success' : 'secondary' ?>"><?= $gw['is_active'] ? 'Active' : 'Inactive' ?></span>
              </div>
            </div>
            
            <div class="row g-2 mb-2">
              <div class="col-md-6">
                <label class="form-label">API Key</label>
                <input type="text" name="api_key" class="form-control form-control-sm" value="<?= e($gw['api_key'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">API Secret</label>
                <input type="password" name="api_secret" class="form-control form-control-sm" value="<?= e($gw['api_secret'] ?? '') ?>">
              </div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-md-6">
                <label class="form-label">API Endpoint</label>
                <input type="url" name="api_endpoint" class="form-control form-control-sm" value="<?= e($gw['api_endpoint'] ?? '') ?>" placeholder="https://api.provider.com/v1/">
              </div>
              <div class="col-md-6">
                <label class="form-label">Merchant Code</label>
                <input type="text" name="merchant_code" class="form-control form-control-sm" value="<?= e($gw['merchant_code'] ?? '') ?>">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Callback URL</label>
              <input type="url" name="callback_url" class="form-control form-control-sm" value="<?= e($gw['callback_url'] ?? '') ?>" placeholder="https://yourschool.com/api/payments/callback.php">
              <small class="text-muted">Provide this URL to the payment provider for transaction callbacks.</small>
            </div>
            <div class="mb-2">
              <label class="form-label">Additional Configuration (JSON)</label>
              <textarea name="config_json" class="form-control form-control-sm" rows="2"><?= e($gw['config_json'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Save <?= e(ucwords(str_replace('_', ' ', $gw['gateway_name']))) ?> Settings</button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card">
      <div class="card-header"><h5 class="mb-0">Recent Online Transactions</h5></div>
      <div class="table-responsive">
        <table class="table table-sm small mb-0">
          <thead>
            <tr><th>Amount</th><th>Gateway</th><th>Status</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentTransactions as $t): ?>
              <tr>
                <td><strong>TZS <?= number_format($t['amount'], 0) ?></strong></td>
                <td><?= e($t['gateway_name'] ?: '-') ?></td>
                <td>
                  <span class="badge bg-<?= $t['status']==='completed'?'success':($t['status']==='pending'?'warning':'danger') ?>">
                    <?= e(ucfirst($t['status'])) ?>
                  </span>
                </td>
                <td class="text-muted"><?= e(date('d M H:i', strtotime($t['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($recentTransactions)): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">No online transactions yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><h5 class="mb-0">Auto-generated Callback URL</h5></div>
      <div class="card-body">
        <p class="small text-muted">Provide this callback URL to your payment provider:</p>
        <code class="d-block p-2 bg-light rounded"><?= e(app_url('/api/payments/callback.php')) ?></code>
        <p class="small text-muted mt-2">Payment status verification endpoint:</p>
        <code class="d-block p-2 bg-light rounded"><?= e(app_url('/api/payments/verify.php')) ?></code>
      </div>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>