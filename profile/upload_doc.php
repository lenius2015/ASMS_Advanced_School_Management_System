<?php
/**
 * profile/upload_doc.php
 * Handles student document uploads.
 * Accessible by students (own docs) and authorized staff.
 */
require_once __DIR__ . '/../config/config.php';
require_login();

$pdo = get_db_connection();
$currentUserId = current_user_id();
$currentRole = current_role();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(app_url('/profile/view.php'));
}

csrf_verify();

$studentId = (int) ($_POST['student_id'] ?? 0);
$docType = $_POST['document_type'] ?? 'other';
$docName = trim($_POST['document_name'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($studentId <= 0 || $docName === '') {
    flash_set('error', 'Student ID and document name are required.');
    redirect(app_url('/profile/view.php'));
}

// Verify permission: student can only upload to their own profile
$isStudentOwner = false;
if ($currentRole === 'student') {
    $stmt = $pdo->prepare("SELECT student_id FROM students WHERE user_id = :uid");
    $stmt->execute(['uid' => $currentUserId]);
    $ownStudent = $stmt->fetch();
    $isStudentOwner = ($ownStudent && (int) $ownStudent['student_id'] === $studentId);
}

$isAuthorized = in_array($currentRole, ['director', 'system_admin', 'head_of_school', 'academic_officer']) || $isStudentOwner;

if (!$isAuthorized) {
    flash_set('error', 'You do not have permission to upload documents for this student.');
    redirect(app_url('/profile/view.php'));
}

if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE) {
    flash_set('error', 'Please select a file to upload.');
    redirect(app_url('/profile/view.php?id=' . $studentId));
}

$file = $_FILES['document_file'];
$allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif'];
$uploadError = validate_upload($file, $allowedExt, 10_485_760); // 10MB max

if ($uploadError) {
    flash_set('error', $uploadError);
    redirect(app_url('/profile/view.php?id=' . $studentId));
}

try {
    $targetDir = APP_ROOT . '/uploads/documents/student/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $filePath = store_upload($file, $targetDir, 'doc_' . $studentId);
    $mimeType = mime_content_type($filePath) ?: $file['type'];

    $stmt = $pdo->prepare(
        'INSERT INTO student_documents (student_id, document_type, document_name, file_path, file_size, mime_type, notes, uploaded_by)
         VALUES (:sid, :type, :name, :path, :size, :mime, :notes, :uid)'
    );
    $stmt->execute([
        'sid'   => $studentId,
        'type'  => $docType,
        'name'  => $docName,
        'path'  => $filePath,
        'size'  => $file['size'],
        'mime'  => $mimeType,
        'notes' => $notes ?: null,
        'uid'   => $currentUserId,
    ]);

    // Check if registration is now complete (all 5 required documents)
    $requiredTypes = ['birth_certificate', 'medical_checkup', 'nida_card', 'passport_copy', 'vaccination_card'];
    $placeholders = implode(',', array_fill(0, count($requiredTypes), '?'));
    $reqStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT document_type) FROM student_documents
         WHERE student_id = ? AND document_type IN ($placeholders)"
    );
    $reqStmt->execute(array_merge([$studentId], $requiredTypes));
    $foundCount = (int) $reqStmt->fetchColumn();

    if ($foundCount >= count($requiredTypes)) {
        $pdo->prepare('UPDATE students SET registration_complete = 1 WHERE student_id = ?')
            ->execute([$studentId]);
    }

    audit_log('upload_student_document', 'student_management', 'student_documents', (int) $pdo->lastInsertId(), "Uploaded {$docType}: {$docName}");
    flash_set('success', 'Document uploaded successfully.');
} catch (Throwable $e) {
    error_log('[ASMS] document upload failed: ' . $e->getMessage());
    flash_set('error', 'Failed to upload document. Please try again.');
}

redirect(app_url('/profile/view.php?id=' . $studentId));