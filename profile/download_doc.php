<?php
/**
 * profile/download_doc.php
 * Handles secure student document downloads with permission checks.
 */
require_once __DIR__ . '/../config/config.php';
require_login();

$pdo = get_db_connection();
$docId = (int) ($_GET['doc_id'] ?? 0);
$currentUserId = current_user_id();
$currentRole = current_role();

if ($docId <= 0) {
    flash_set('error', 'Invalid document.');
    redirect(app_url('/profile/view.php'));
}

// Get document info
$stmt = $pdo->prepare(
    "SELECT sd.*, s.user_id AS student_user_id FROM student_documents sd
     JOIN students s ON s.student_id = sd.student_id
     WHERE sd.document_id = :id"
);
$stmt->execute(['id' => $docId]);
$doc = $stmt->fetch();

if (!$doc) {
    flash_set('error', 'Document not found.');
    redirect(app_url('/profile/view.php'));
}

// Permission check
$isStudentOwner = ($currentRole === 'student' && (int) $doc['student_user_id'] === $currentUserId);
$isParentOwner = false;

if ($currentRole === 'parent') {
    $checkParent = $pdo->prepare(
        "SELECT sg.student_id FROM student_guardians sg
         JOIN guardians g ON g.guardian_id = sg.guardian_id
         WHERE g.user_id = :uid AND sg.student_id = :sid"
    );
    $checkParent->execute(['uid' => $currentUserId, 'sid' => $doc['student_id']]);
    $isParentOwner = (bool) $checkParent->fetch();
}

$isAuthorized = in_array($currentRole, ['director', 'system_admin', 'head_of_school', 'academic_officer'])
    || $isStudentOwner
    || $isParentOwner;

if (!$isAuthorized) {
    flash_set('error', 'You do not have permission to download this document.');
    redirect(app_url('/profile/view.php'));
}

if (!file_exists($doc['file_path'])) {
    flash_set('error', 'File not found on server.');
    redirect(app_url('/profile/view.php'));
}

// Stream the file
header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . basename($doc['file_path']) . '"');
header('Content-Length: ' . filesize($doc['file_path']));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($doc['file_path']);
exit;