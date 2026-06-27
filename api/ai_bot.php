<?php
/**
 * api/ai_bot.php
 * AI Bot backend endpoint for the ASMS floating assistant.
 * Processes requests, analyzes page context, and returns
 * intelligent rule-based responses (no external API needed).
 *
 * Accepts: POST
 * Parameters: action, message, page_url, page_title, context
 *
 * Returns: JSON { success: bool, response: string, error?: string }
 */

require_once __DIR__ . '/../config/config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'response' => '', 'error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'response' => '', 'error' => 'Method not allowed.']);
    exit;
}

$action   = $_POST['action'] ?? 'analyze';
$message  = trim($_POST['message'] ?? '');
$pageUrl  = $_POST['page_url'] ?? '';
$pageTitle= $_POST['page_title'] ?? '';
$context  = !empty($_POST['context']) ? json_decode($_POST['context'], true) : null;
$role     = current_role();
$response = '';
$success  = true;

try {
    switch ($action) {
        case 'analyze': $response = analyzePage($pageUrl, $pageTitle, $role, $context); break;
        case 'help':    $response = getQuickHelp($pageUrl, $pageTitle, $role); break;
        case 'tips':    $response = getPageTips($pageUrl, $role); break;
        case 'ask':     $response = answerQuestion($message, $pageUrl, $pageTitle, $role); break;
        case 'tasks':   $response = getMyTasks($role); break;
        case 'contacts':$response = getImportantContacts(); break;
        default: $response = 'I\'m not sure how to handle that. Try the quick action buttons above!';
    }
} catch (Throwable $e) {
    $success = false;
    $response = 'I encountered an issue processing your request. Please try again.';
    error_log("AI Bot error: " . $e->getMessage());
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => $success, 'response' => $response]);
exit;

// =========================================================================
// FUNCTIONS
// =========================================================================

function analyzePage(string $url, string $title, ?string $role, ?array $context): string
{
    $pdo = get_db_connection();
    $pageType = detectPageType($url);
    switch ($pageType) {
        case 'director_dashboard': return analyzeDirectorDashboard($pdo);
        case 'teacher_dashboard': return analyzeTeacherDashboard($pdo);
        case 'student_dashboard': return analyzeStudentDashboard($pdo);
        case 'parent_dashboard': return analyzeParentDashboard($pdo);
        case 'finance_overview': return analyzeFinancePage($pdo);
        case 'student_list': return analyzeStudentList($pdo);
        case 'staff_list': return analyzeStaffList($pdo);
        case 'enter_marks': return analyzeEnterMarks($pdo);
        default: return genericPageAnalysis($title, $role);
    }
}

function detectPageType(string $url): string
{
    $path = parse_url(str_replace(['\\'], '/', $url), PHP_URL_PATH) ?? '';
    if (str_contains($path, 'director/dashboard.php')) return 'director_dashboard';
    if (str_contains($path, 'director/students.php')) return 'student_list';
    if (str_contains($path, 'director/staff.php')) return 'staff_list';
    if (str_contains($path, 'director/finance_overview.php')) return 'finance_overview';
    if (str_contains($path, 'director/performance_reports.php')) return 'reports';
    if (str_contains($path, 'director/system_admin.php')) return 'settings';
    if (str_contains($path, 'director/users.php')) return 'user_management';
    if (str_contains($path, 'director/deletion_requests.php')) return 'deletion_requests';
    if (str_contains($path, 'director/audit_logs.php')) return 'audit_logs';
    if (str_contains($path, 'teacher/dashboard.php')) return 'teacher_dashboard';
    if (str_contains($path, 'teacher/enter_marks.php')) return 'enter_marks';
    if (str_contains($path, 'teacher/lesson_attendance.php')) return 'attendance';
    if (str_contains($path, 'teacher/timetable.php')) return 'timetable';
    if (str_contains($path, 'student/dashboard.php')) return 'student_dashboard';
    if (str_contains($path, 'student/results.php')) return 'results';
    if (str_contains($path, 'student/timetable.php')) return 'timetable';
    if (str_contains($path, 'student/attendance.php')) return 'attendance';
    if (str_contains($path, 'student/fees.php')) return 'fees';
    if (str_contains($path, 'student/notices.php')) return 'notices';
    if (str_contains($path, 'parent/dashboard.php')) return 'parent_dashboard';
    if (str_contains($path, 'parent/results.php')) return 'results';
    if (str_contains($path, 'parent/attendance.php')) return 'attendance';
    if (str_contains($path, 'parent/fees.php')) return 'fees';
    if (str_contains($path, 'head_of_school/dashboard.php')) return 'hos_dashboard';
    if (str_contains($path, 'head_of_school/discipline.php')) return 'discipline';
    if (str_contains($path, 'bursar/dashboard.php')) return 'bursar_dashboard';
    if (str_contains($path, 'bursar/invoices.php')) return 'invoices';
    if (str_contains($path, 'bursar/record_payment.php')) return 'record_payment';
    if (str_contains($path, 'bursar/payroll.php')) return 'payroll';
    if (str_contains($path, 'academic/dashboard.php')) return 'academic_dashboard';
    if (str_contains($path, 'academic/subjects.php')) return 'subjects';
    if (str_contains($path, 'academic/classes.php')) return 'classes';
    if (str_contains($path, 'profile/view.php')) return 'profile_view';
    if (str_contains($path, 'profile/edit.php')) return 'profile_edit';
    if (str_contains($path, 'communication/inbox.php')) return 'inbox';
    if (str_contains($path, 'communication/announcements.php')) return 'announcements';
    return 'generic';
}


function analyzeDirectorDashboard(PDO $pdo): string
{
    $ts = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
    $st = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status='active'")->fetchColumn();
    $tc = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
    $m  = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active' AND gender='male'")->fetchColumn();
    $f  = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active' AND gender='female'")->fetchColumn();
    $at = $pdo->query("SELECT SUM(status='present')p,COUNT(*)t FROM student_attendance WHERE attendance_date=CURDATE()")->fetch();
    $ar = ($at&&$at['t']>0)?round(($at['p']/$at['t'])*100,1):null;
    $pv = (int)$pdo->query("SELECT COUNT(*) FROM exam_marks WHERE verification_status='pending' AND submitted_at IS NOT NULL")->fetchColumn();
    $ou = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) FROM invoices")->fetchColumn();
    $h = '<div class="msg-label">📊 Dashboard Analysis</div>';
    $h .= "<p><strong>School Overview:</strong></p>";
    $h .= "<p><span class=\"bot-stat\"><i class=\"fa fa-user-graduate\"></i> {$ts} Students</span> ";
    $h .= "<span class=\"bot-stat\"><i class=\"fa fa-id-badge\"></i> {$st} Staff</span> ";
    $h .= "<span class=\"bot-stat\"><i class=\"fa fa-chalkboard\"></i> {$tc} Classes</span></p>";
    $h .= "<p>Gender: {$m} male, {$f} female.</p>";
    if ($ar!==null) $h .= "<p>".($ar>=90?'✅':'⚠️')." Attendance today: <strong>{$ar}%</strong></p>";
    if ($pv>0) $h .= "<p>⚠️ <strong>{$pv}</strong> pending verifications.</p>";
    $h .= "<p>💰 Outstanding: <strong>TZS ".number_format($ou)."</strong></p>";
    $h .= '<p class="mt-2"><em>💡 Use quick action buttons for more help.</em></p>';
    return $h;
}

function analyzeTeacherDashboard(PDO $pdo): string
{
    $tid = current_user_id();
    $c = $pdo->prepare("SELECT COUNT(*) FROM class_subjects WHERE teacher_id=:t");
    $c->execute(['t'=>$tid]); $cc = (int)$c->fetchColumn();
    $r = $pdo->prepare("SELECT COUNT(*) FROM exam_marks em JOIN class_subjects cs ON cs.class_subject_id=em.class_subject_id WHERE cs.teacher_id=:t AND em.verification_status='rejected'");
    $r->execute(['t'=>$tid]); $rc = (int)$r->fetchColumn();
    $p = $pdo->prepare("SELECT COUNT(*) FROM exam_marks em JOIN class_subjects cs ON cs.class_subject_id=em.class_subject_id WHERE cs.teacher_id=:t AND em.marks_obtained IS NULL AND em.is_absent=0");
    $p->execute(['t'=>$tid]); $pc = (int)$p->fetchColumn();
    $tl = date('l');
    $l = $pdo->prepare("SELECT COUNT(*) FROM timetable t JOIN class_subjects cs ON cs.class_subject_id=t.class_subject_id WHERE cs.teacher_id=:t AND t.day_of_week=:d");
    $l->execute(['t'=>$tid, 'd'=>$tl]); $lc = (int)$l->fetchColumn();
    $h = '<div class="msg-label">📋 Teacher Dashboard</div>';
    $h .= "<p>You have <strong>{$cc}</strong> class/subject assignment(s).</p>";
    $h .= "<p>📅 ".($lc>0?"You have <strong>{$lc}</strong> lesson(s) today.":"No lessons scheduled today.")."</p>";
    if ($pc>0) $h .= "<p>⚠️ <strong>{$pc}</strong> mark(s) need entry.</p>";
    if ($rc>0) $h .= "<p>🔴 <strong>{$rc}</strong> mark(s) rejected — needs correction.</p>";
    if ($pc===0&&$rc===0) $h .= "<p>✅ All marks up to date!</p>";
    $h .= '<p class="mt-2"><em>💡 Enter marks, then submit for verification.</em></p>';
    return $h;

function analyzeStudentDashboard(PDO $pdo): string
{
    $uid = current_user_id();
    $s = $pdo->prepare("SELECT s.* FROM students s WHERE s.user_id=:u");
    $s->execute(['u'=>$uid]); $stu = $s->fetch();
    if (!$stu) return '<p>Welcome! Your student profile is being set up.</p>';
    $sid = (int)$stu['student_id'];
    $period = get_current_period($pdo);
    $a = $pdo->prepare("SELECT SUM(status='present')p,COUNT(*)t FROM student_attendance WHERE student_id=:id");
    $a->execute(['id'=>$sid]); $att = $a->fetch();
    $ar = ($att&&$att['t']>0)?round(($att['p']/$att['t'])*100,1):null;
    $f = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE student_id=:id");
    $f->execute(['id'=>$sid]); $bal = (float)$f->fetchColumn();
    $res = $pdo->prepare("SELECT COUNT(*)c,COALESCE(AVG(average_marks),0)avg FROM term_results WHERE student_id=:id AND term_id=:t");
    $res->execute(['id'=>$sid,'t'=>$period['term_id']]); $r = $res->fetch();
    $sc = (int)($r['c']??0); $av = round((float)($r['avg']??0),1);
    $h = '<div class="msg-label">👋 Student Overview</div>';
    $h .= '<p>Welcome, <strong>'.e($_SESSION['full_name']??'Student').'</strong>!</p>';
    if ($ar!==null) $h .= "<p>".($ar>=90?'✅':'⚠️')." Attendance: <strong>{$ar}%</strong></p>";
    if ($sc>0) { $g=$av>=80?'A':($av>=70?'B':($av>=60?'C':($av>=50?'D':'F')));
        $h .= "<p>📚 Average: <strong>{$av}%</strong> (Grade {$g})</p>";
    } else $h .= '<p>📚 No results published yet.</p>';
    $h .= $bal>0 ? "<p>💰 Balance: <strong>TZS ".number_format($bal)."</strong></p>" : '<p>✅ Fee clear.</p>';
    $h .= '<p class="mt-2"><em>💡 Check timetable, results & notices via sidebar.</em></p>';
    return $h;
}

function analyzeParentDashboard(PDO $pdo): string
{
    $uid = current_user_id();
    $g = $pdo->prepare("SELECT guardian_id FROM guardians WHERE user_id=:u");
    $g->execute(['u'=>$uid]); $guard = $g->fetch();
    if (!$guard) return '<p>Welcome! No children linked yet.</p>';
    $kids = $pdo->prepare("SELECT COUNT(*) FROM student_guardians WHERE guardian_id=:g");
    $kids->execute(['g'=>(int)$guard['guardian_id']]); $c = (int)$kids->fetchColumn();
    return '<div class="msg-label">👨‍👩‍👧‍👦 Parent Dashboard</div>'
        . "<p>You have <strong>{$c}</strong> child(ren) enrolled.</p>"
        . '<p>Each child card shows attendance, results, and fee status.</p>'
        . '<p class="mt-2"><em>💡 Click a child card for detailed info.</em></p>';
}

function analyzeFinancePage(PDO $pdo): string
{
    $period = get_current_period($pdo);
    $f = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0)b,COALESCE(SUM(amount_paid),0)c,COALESCE(SUM(balance),0)o FROM invoices WHERE term_id=:t");
    $f->execute(['t'=>$period['term_id']]); $fin = $f->fetch();
    $rate = $fin['b']>0 ? round(($fin['c']/$fin['b'])*100,1) : 0;
    return '<div class="msg-label">💰 Finance Overview</div>'
        . "<p>Billed: <strong>TZS ".number_format((float)$fin['b'])."</strong></p>"
        . "<p>Collected: <strong>TZS ".number_format((float)$fin['c'])."</strong> ({$rate}%)</p>"
        . "<p>Outstanding: <strong>TZS ".number_format((float)$fin['o'])."</strong></p>"
        . '<p class="mt-2"><em>💡 Switch periods to compare terms.</em></p>';
}

function analyzeStudentList(PDO $pdo): string
{
    $total = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
    $m = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active' AND gender='male'")->fetchColumn();
    $f = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active' AND gender='female'")->fetchColumn();
    return '<div class="msg-label">👨‍🎓 Student Management</div>'
        . "<p>Total active: <strong>{$total}</strong> ({$m} male, {$f} female)</p>"
        . '<p>Use the search box to find students. Click "Register New Student" to add.</p>'
        . '<p class="mt-2"><em>💡 Filter by class using the dropdown above the table.</em></p>';
}

function analyzeStaffList(PDO $pdo): string
{
    $total = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status='active'")->fetchColumn();
    $depts = $pdo->query("SELECT COUNT(DISTINCT department_id) FROM staff WHERE status='active'")->fetchColumn();
    return '<div class="msg-label">👥 Staff Management</div>'
        . "<p>Total active staff: <strong>{$total}</strong></p>"
        . "<p>Departments: <strong>".(int)$depts."</strong></p>"
        . '<p>Search or filter staff. Click "Add Staff" to register.</p>'
        . '<p class="mt-2"><em>💡 Click a staff name to view their full profile.</em></p>';
}

function analyzeEnterMarks(PDO $pdo): string
{
    $eid = (int)($_GET['exam_id']??0);
    $csid = (int)($_GET['class_subject_id']??0);
    if ($eid && $csid) {
        $e = $pdo->prepare("SELECT exam_name,max_marks FROM exams WHERE exam_id=:id");
        $e->execute(['id'=>$eid]); $ex = $e->fetch();
        if ($ex) return '<div class="msg-label">📝 Entering Marks</div>'
            . "<p>Exam: <strong>".e($ex['exam_name'])."</strong> (Max: {$ex['max_marks']})</p>"
            . '<p>Type "ABS" for absent students. Save draft frequently.</p>'
            . '<p>When ready, click "Submit for Verification" to lock marks.</p>'
            . '<p class="mt-2"><em>💡 Marks locked after submission unless rejected.</em></p>';
    }
    return '<div class="msg-label">📝 Entering Marks</div>'
        . '<p>Select an exam and class/subject to begin entering marks.</p>'
        . '<p>You\'ll see a grid of students where you can enter scores or "ABS".</p>'
        . '<p class="mt-2"><em>💡 Save draft before leaving.</em></p>';
}

function genericPageAnalysis(string $title, ?string $role): string
{
    $roleName = $role ? ucfirst(str_replace('_', ' ', $role)) : 'User';
    return '<div class="msg-label">ℹ️ Page Info</div>'
        . "<p>Viewing <strong>".e($title)."</strong> as <strong>{$roleName}</strong>.</p>"
        . '<p>What would you like to know? Try "Quick Help" or ask a question below.</p>'
        . '<p class="mt-2"><em>💡 Use the sidebar to navigate.</em></p>';

// =========================================================================
// QUICK HELP & TIPS
// =========================================================================

function getQuickHelp(string $url, string $title, ?string $role): string
{
    $pageType = detectPageType($url);
    switch ($pageType) {
        case 'director_dashboard':
            return '<div class="msg-label">💡 Quick Help</div>'
                . '<p><strong>Director Dashboard</strong> shows school-wide KPIs: students, staff, attendance, finance.</p>'
                . '<ul><li>🔄 Switch chart views for different data</li>'
                . '<li>📥 Use export buttons to download data</li>'
                . '<li>🔍 Click any KPI card for more detail</li></ul>';
        case 'teacher_dashboard':
            return '<div class="msg-label">💡 Quick Help</div>'
                . '<p><strong>Teacher Dashboard</strong> shows classes, timetable, and pending tasks.</p>'
                . '<ul><li>📝 "Enter Marks" to record exam scores</li>'
                . '<li>📅 Check today\'s timetable for lessons</li>'
                . '<li>🔴 Rejected marks need correction</li></ul>';
        case 'student_dashboard':
            return '<div class="msg-label">💡 Quick Help</div>'
                . '<p><strong>Student Dashboard</strong> — your academic snapshot.</p>'
                . '<ul><li>📊 View attendance & results charts</li>'
                . '<li>💰 Check fee balance status</li>'
                . '<li>📅 See today\'s timetable</li></ul>';
        case 'parent_dashboard':
            return '<div class="msg-label">💡 Quick Help</div>'
                . '<p><strong>Parent Dashboard</strong> — all your enrolled children.</p>'
                . '<ul><li>👆 Click a child\'s card for details</li>'
                . '<li>📊 Attendance, results & fees per child</li>'
                . '<li>💬 Use Messages to contact school</li></ul>';
        case 'enter_marks':
            return '<div class="msg-label">💡 Quick Help</div>'
                . '<p><strong>Enter Marks</strong> — Step by step:</p>'
                . '<ol><li>Select an <strong>exam</strong> and <strong>class/subject</strong></li>'
                . '<li>Enter scores or type "ABS" for absent</li>'
                . '<li>Click <strong>Save Draft</strong> to save</li>'
                . '<li>Click <strong>Submit for Verification</strong> when done</li></ol>'
                . '<p>⚠️ After submission, marks cannot be edited unless rejected.</p>';
        case 'finance_overview':
            return '<div class="msg-label">💡 Quick Help</div>'
                . '<p><strong>Finance Overview</strong> — school financial health.</p>'
                . '<ul><li>📈 View collection trends across months</li>'
                . '<li>💰 Outstanding balances by class level</li>'
                . '<li>📅 Switch year/term to compare</li></ul>';
        case 'student_list':
            return '<div class="msg-label">💡 Quick Help</div>'
                . '<p><strong>Student Management</strong> — view and register students.</p>'
                . '<ul><li>🔍 Use the search box to find students</li>'
                . '<li>➕ "Register New Student" to add</li>'
                . '<li>📋 Use filters to narrow by class</li></ul>';
        default:
            $rn = $role ? ucfirst(str_replace('_', ' ', $role)) : 'User';
            return '<div class="msg-label">💡 Quick Help</div>'
                . "<p>You are on <strong>".e($title)."</strong> as <strong>{$rn}</strong>.</p>"
                . '<p>Try "Analyze Page" for insights, or ask a specific question below.</p>';
    }
}

function getPageTips(string $url, ?string $role): string
{
    $pageType = detectPageType($url);
    switch ($pageType) {
        case 'director_dashboard':
            return '<div class="msg-label">💡 Pro Tips</div>'
                . '<ul><li>📊 Use period selector to compare terms</li>'
                . '<li>🔔 Monitor pending verifications</li>'
                . '<li>📈 Track collection rate to improve cash flow</li></ul>';
        case 'teacher_dashboard':
            return '<div class="msg-label">💡 Pro Tips</div>'
                . '<ul><li>⏰ Check timetable daily for changes</li>'
                . '<li>📝 Enter marks as soon as exams end</li>'
                . '<li>✅ Submit promptly to avoid backlogs</li></ul>';
        case 'student_dashboard':
            return '<div class="msg-label">💡 Pro Tips</div>'
                . '<ul><li>📚 Check results regularly to track progress</li>'
                . '<li>⏰ Arrive on time for good attendance</li>'
                . '<li>💰 Clear fees early to avoid late charges</li></ul>';
        default:
            return '<div class="msg-label">💡 Tips</div>'
                . '<p>💡 Use the sidebar to navigate quickly.</p>'
                . '<p>💡 Click "Analyze Page" for insights about this page.</p>';
    }
}


// =========================================================================
// MY TASKS
// =========================================================================

function getMyTasks(?string $role): string
{
    $pdo = get_db_connection();
    $uid = current_user_id();

    switch ($role) {
        case 'director':
        case 'system_admin':
            $pv = (int)$pdo->query("SELECT COUNT(*) FROM exam_marks WHERE verification_status='pending' AND submitted_at IS NOT NULL")->fetchColumn();
            $dr = (int)$pdo->query("SELECT COUNT(*) FROM deletion_requests WHERE status='pending'")->fetchColumn();
            $h = '<div class="msg-label">📋 Your Pending Tasks</div>';
            if ($pv>0) $h .= "<p>📝 <strong>{$pv}</strong> mark submission(s) pending <strong>verification</strong></p>";
            if ($dr>0) $h .= "<p>🗑️ <strong>{$dr}</strong> deletion request(s) awaiting <strong>review</strong></p>";
            if ($pv===0&&$dr===0) $h .= "<p>✅ No pending tasks — everything is up to date!</p>";
            $h .= '<p class="mt-2"><em>💡 Visit Audit Logs or Deletion Requests.</em></p>';
            return $h;

        case 'subject_teacher':
        case 'class_teacher':
            $pc = $pdo->prepare("SELECT COUNT(*) FROM exam_marks em JOIN class_subjects cs ON cs.class_subject_id=em.class_subject_id WHERE cs.teacher_id=:t AND em.marks_obtained IS NULL AND em.is_absent=0");
            $pc->execute(['t'=>$uid]); $pe = (int)$pc->fetchColumn();
            $rc = $pdo->prepare("SELECT COUNT(*) FROM exam_marks em JOIN class_subjects cs ON cs.class_subject_id=em.class_subject_id WHERE cs.teacher_id=:t AND em.verification_status='rejected'");
            $rc->execute(['t'=>$uid]); $rj = (int)$rc->fetchColumn();
            $h = '<div class="msg-label">📋 Your Pending Tasks</div>';
            if ($pe>0) $h .= "<p>📝 <strong>{$pe}</strong> student mark(s) need <strong>entry</strong></p>";
            if ($rj>0) $h .= "<p>🔴 <strong>{$rj}</strong> mark(s) <strong>rejected</strong> — needs correction</p>";
            if ($pe===0&&$rj===0) $h .= "<p>✅ All marks up to date!</p>";
            $h .= '<p class="mt-2"><em>💡 Go to Enter Marks to complete these.</em></p>';
            return $h;

        case 'student':
            $s = $pdo->prepare("SELECT student_id FROM students WHERE user_id=:u");
            $s->execute(['u'=>$uid]); $stu = $s->fetch();
            if (!$stu) return '<p>📋 No student profile found.</p>';
            $sid = (int)$stu['student_id'];
            $b = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM invoices WHERE student_id=:id");
            $b->execute(['id'=>$sid]); $bal = (float)$b->fetchColumn();
            $n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0");
            $n->execute(['u'=>$uid]); $unread = (int)$n->fetchColumn();
            $h = '<div class="msg-label">📋 Your Pending Items</div>';
            if ($bal>0) $h .= "<p>💰 Fee balance: <strong>TZS ".number_format($bal)."</strong></p>";
            if ($unread>0) $h .= "<p>🔔 <strong>{$unread}</strong> unread notification(s)</p>";
            if ($bal==0&&$unread==0) $h .= "<p>✅ No pending items!</p>";
            $h .= '<p class="mt-2"><em>💡 Check Notices and Fees pages.</em></p>';
            return $h;

        case 'parent':
            $n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0");
            $n->execute(['u'=>$uid]); $unread = (int)$n->fetchColumn();
            $h = '<div class="msg-label">📋 Your Pending Items</div>';
            if ($unread>0) $h .= "<p>🔔 <strong>{$unread}</strong> unread notification(s)</p>";
            if ($unread==0) $h .= "<p>✅ No pending items!</p>";
            $h .= '<p class="mt-2"><em>💡 Check Messages and Children dashboards.</em></p>';
            return $h;

        case 'head_of_school':
        case 'academic_officer':
            $pv = (int)$pdo->query("SELECT COUNT(*) FROM exam_marks WHERE verification_status='pending' AND submitted_at IS NOT NULL")->fetchColumn();
            $h = '<div class="msg-label">📋 Your Pending Tasks</div>';
            if ($pv>0) $h .= "<p>📝 <strong>{$pv}</strong> mark submission(s) pending <strong>verification</strong></p>";
            if ($pv==0) $h .= "<p>✅ No pending verifications.</p>";
            $h .= '<p class="mt-2"><em>💡 Review marks from Academic section.</em></p>';
            return $h;

        case 'bursar':
            $ov = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE balance>0 AND due_date<CURDATE()")->fetchColumn();
            $h = '<div class="msg-label">📋 Your Pending Tasks</div>';
            if ($ov>0) $h .= "<p>💰 <strong>{$ov}</strong> overdue invoice(s) requiring follow-up</p>";
            if ($ov==0) $h .= "<p>✅ No overdue invoices.</p>";
            $h .= '<p class="mt-2"><em>💡 Visit Invoices to send reminders.</em></p>';
            return $h;

        default:
            return '<p>📋 Check your dashboard for role-specific tasks.</p>';
    }
}


// =========================================================================
// QUESTION ANSWERING
// =========================================================================

function answerQuestion(string $message, string $url, string $title, ?string $role): string
{
    $msg = mb_strtolower(trim($message));

    if (preg_match('/^(hi|hello|hey|greetings|good morning|good afternoon|good evening)/', $msg)) {
        $name = $_SESSION['full_name'] ?? 'there';
        return "<p>Hello <strong>" . e($name) . "</strong>! 👋 How can I help you today?</p>";
    }
    if (preg_match('/(help|what can you do|how (do|can) you)/', $msg)) {
        return '<div class="msg-label">🤖 What I Can Do</div>'
            . '<ul><li>🔍 <strong>Analyze Page</strong> — insights about this page</li>'
            . '<li>📋 <strong>My Tasks</strong> — view your incomplete/pending tasks</li>'
            . '<li>📞 <strong>Contacts</strong> — important school contacts</li>'
            . '<li>💡 <strong>Quick Help</strong> — step-by-step guides</li>'
            . '<li>💡 <strong>Tips</strong> — pro efficiency tips</li>'
            . '<li>❓ Ask about: attendance, marks, fees, timetable, registration, tasks, contacts</li></ul>';
    }
    if (str_contains($msg, 'attendance')) {
        return '<p>📅 <strong>Attendance</strong> is tracked daily by teachers.</p>'
            . '<p>✅ Present | ❌ Absent | ⏰ Late</p>'
            . '<p>View reports from the Attendance page in the sidebar.</p>';
    }
    if (str_contains($msg, 'mark') || str_contains($msg, 'result') || str_contains($msg, 'score') || str_contains($msg, 'grade')) {
        return '<p>📝 <strong>Marks & Results</strong></p>'
            . '<p>Teachers enter marks, submit for verification, then published.</p>'
            . '<p>📊 Grades: A (80%+), B (70-79%), C (60-69%), D (50-59%), F (&lt;50%)</p>';
    }
    if (str_contains($msg, 'fee') || str_contains($msg, 'payment') || str_contains($msg, 'invoice') || str_contains($msg, 'pay')) {
        return '<p>💰 <strong>Fees & Payments</strong></p>'
            . '<p>Invoices per term. Bursar records payments.</p>'
            . '<p>📄 View from the Finance section.</p>'
            . '<p>⚠️ Outstanding balances may restrict某些 features.</p>';
    }
    if (str_contains($msg, 'timetable') || str_contains($msg, 'schedule') || str_contains($msg, 'lesson')) {
        return '<p>📅 <strong>Timetable</strong> shows scheduled lessons per class.</p>'
            . '<p>Visit the Timetable page in the sidebar.</p>';
    }
    if (str_contains($msg, 'task') || str_contains($msg, 'incomplete') || str_contains($msg, 'pending') || str_contains($msg, 'to-do') || str_contains($msg, 'todo')) {
        return '<p>📋 Click the <strong>"My Tasks"</strong> button above to see your incomplete/pending tasks tailored to your role!</p>'
            . '<p>Or ask about a specific area like: marks, fees, or attendance.</p>';
    }
    if (str_contains($msg, 'contact') || str_contains($msg, 'phone') || str_contains($msg, 'email') || str_contains($msg, 'address') || str_contains($msg, 'who') || str_contains($msg, 'reach') || str_contains($msg, 'call')) {
        $pdo = get_db_connection();
        $schoolPhone = get_setting($pdo, 'school_phone', '+255 XXX XXX XXX');
        $schoolEmail = get_setting($pdo, 'school_email', 'info@school.ac.tz');
        return '<div class="msg-label">📞 Contacts Quick View</div>'
            . "<p>📞 Phone: <strong>" . e($schoolPhone) . "</strong></p>"
            . "<p>📧 Email: <strong>" . e($schoolEmail) . "</strong></p>"
            . "<p>🔍 Click <strong>\"Contacts\"</strong> above for full details with addresses and departments.</p>";
    }
    if (preg_match('/(register|enroll|admission|new student)/', $msg)) {
        return '<p>👨‍🎓 <strong>Student Registration</strong></p>'
            . '<ol><li>Go to <strong>Student Management</strong></li>'
            . '<li>Click <strong>Register New Student</strong></li>'
            . '<li>Fill details (name, class) and submit</li></ol>'
            . '<p>System auto-generates admission no. & login.</p>';
    }
    $rn = $role ? ucfirst(str_replace('_', ' ', $role)) : 'User';
    return '<p>I\'m not sure about "<strong>' . e($message) . '</strong>", <strong>' . e($_SESSION['full_name']??$rn) . '</strong>.</p>'
        . '<p>Try the quick action buttons above, or ask about: attendance, marks, fees, timetable, registration.</p>';

// =========================================================================
// IMPORTANT CONTACTS
// =========================================================================

function getImportantContacts(): string
{
    $pdo = get_db_connection();
    $schoolPhone = get_setting($pdo, 'school_phone', '+255 XXX XXX XXX');
    $schoolEmail = get_setting($pdo, 'school_email', 'info@school.ac.tz');
    $schoolAddr  = get_setting($pdo, 'school_address', 'P.O. Box, Dar es Salaam');
    $schoolName  = get_setting($pdo, 'school_name', 'ASMS School');

    $h = '<div class="msg-label">📞 Important Contacts</div>';
    $h .= "<p><strong>" . e($schoolName) . "</strong></p>";
    $h .= "<p>📍 <strong>Address:</strong> " . e($schoolAddr) . "</p>";
    $h .= "<p>📞 <strong>Phone:</strong> " . e($schoolPhone) . "</p>";
    $h .= "<p>📧 <strong>Email:</strong> " . e($schoolEmail) . "</p>";
    $h .= '<hr style="opacity:0.2;margin:8px 0;">';
    $h .= '<p><strong>Key Departments:</strong></p>';
    $h .= '<p>👨‍💼 <strong>Director\'s Office</strong> — Administration &amp; Oversight</p>';
    $h .= '<p>👩‍🏫 <strong>Academic Office</strong> — Results, Exams &amp; Timetable</p>';
    $h .= '<p>💰 <strong>Bursar\'s Office</strong> — Fees, Payments &amp; Invoices</p>';
    $h .= '<p>👨‍👩‍👧‍👦 <strong>Head of School</strong> — Student Welfare &amp; Discipline</p>';
    $h .= '<p class="mt-2"><em>💡 Contact the school office to be connected to any department.</em></p>';
    return $h;
}


