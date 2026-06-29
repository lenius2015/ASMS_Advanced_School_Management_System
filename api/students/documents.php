<?php
/**
 * api/students/documents.php
 * Returns a JSON list of uploaded documents for a given student.
 * Used by the Head of School document upload modal to preview existing files.
 */
require_once __DIR__ . '/../../config/config.php';
require_role(['director', 'system_admin', 'head_of_school']);

$pdo = get_db_connection();
$studentId = (int) ($_GET['student_id'] ?? 0);

if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid student ID']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT document_id, document_type, document_name, file_path, file_size, mime_type, created_at
     FROM student_documents
     WHERE student_id = :sid
     ORDER BY document_type ASC"
);
$stmt->execute(['sid' => $studentId]);
$docs = $stmt->fetchAll();

// Format file sizes
foreach ($docs as &$doc) {
    $size = (int) $doc['file_size'];
    if ($size >= 1_048_576) {
        $doc['file_size_formatted'] = round($size / 1_048_576, 1) . ' MB';
    } elseif ($size >= 1024) {
        $doc['file_size_formatted'] = round($size / 1024, 1) . ' KB';
    } else {
        $doc['file_size_formatted'] = $size . ' B';
    }
}
unset($doc);

header('Content-Type: application/json');
echo json_encode($docs);