<?php
/**
 * includes/audit.php
 * Lightweight audit trail logging. Call audit_log() after any significant
 * create/update/delete/approve action for accountability and traceability.
 */

function audit_log(string $action, string $module, ?string $table = null, ?int $recordId = null, ?string $description = null): void
{
    try {
        $pdo = get_db_connection();
        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, module, record_table, record_id, description, ip_address)
             VALUES (:user_id, :action, :module, :record_table, :record_id, :description, :ip)'
        )->execute([
            'user_id'      => current_user_id(),
            'action'       => $action,
            'module'       => $module,
            'record_table' => $table,
            'record_id'    => $recordId,
            'description'  => $description,
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    } catch (Throwable $e) {
        // Audit logging must never break the main request flow.
        error_log('[ASMS] Audit log failure: ' . $e->getMessage());
    }
}
