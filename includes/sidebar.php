<?php
/**
 * includes/sidebar.php
 * Renders a sidebar menu tailored to the logged-in user's role.
 * Included by header.php — do not include directly on pages.
 */

$role = current_role();
$base = app_url('');

/**
 * Each menu item: [label, url, icon]. Grouped per role below.
 */
$menus = [
    'director' => [
        ['Dashboard', '/director/dashboard.php', 'fa-chart-line'],
        ['Student Management', '/director/students.php', 'fa-user-graduate'],
        ['HR & Staff Management', '/director/staff.php', 'fa-id-badge'],
        ['Financial Overview', '/director/finance_overview.php', 'fa-coins'],
        ['School Calendar', '/director/calendar.php', 'fa-calendar-alt'],
        ['Performance Reports', '/director/performance_reports.php', 'fa-chart-bar'],
        ['User & Role Management', '/director/users.php', 'fa-users-cog'],
        ['Audit & Security Logs', '/director/audit_logs.php', 'fa-shield-alt'],
        ['System Settings', '/director/system_admin.php', 'fa-cogs'],
        ['Announcements', '/communication/announcements.php', 'fa-bullhorn'],
    ],
    'school_board' => [
        ['Board Dashboard', '/director/board_dashboard.php', 'fa-landmark'],
        ['Performance Reports', '/director/performance_reports.php', 'fa-chart-bar'],
        ['Financial Summary', '/director/finance_overview.php', 'fa-coins'],
        ['Announcements', '/communication/announcements.php', 'fa-bullhorn'],
    ],
    'head_of_school' => [
        ['Dashboard', '/head_of_school/dashboard.php', 'fa-school'],
        ['Student Management', '/head_of_school/students.php', 'fa-user-graduate'],
        ['Staff Management', '/head_of_school/staff.php', 'fa-id-badge'],
        ['Department Management', '/head_of_school/departments.php', 'fa-building'],
        ['Attendance Overview', '/academic/attendance_overview.php', 'fa-calendar-check'],
        ['Academic Reports', '/academic/reports.php', 'fa-chart-bar'],
        ['Discipline Records', '/head_of_school/discipline.php', 'fa-gavel'],
        ['School Reports', '/head_of_school/reports.php', 'fa-file-alt'],
        ['Parent Accounts', '/academic/parent_accounts.php', 'fa-users'],
        ['Announcements', '/communication/announcements.php', 'fa-bullhorn'],
    ],
    'bursar' => [
        ['Finance Dashboard', '/bursar/dashboard.php', 'fa-chart-pie'],
        ['Fee Structures', '/bursar/fee_structures.php', 'fa-list-ol'],
        ['Invoices', '/bursar/invoices.php', 'fa-file-invoice'],
        ['Record Payment', '/bursar/record_payment.php', 'fa-money-bill-wave'],
        ['Fee Reminders', '/bursar/fee_reminders.php', 'fa-bell'],
        ['Payroll', '/bursar/payroll.php', 'fa-wallet'],
        ['Budget & Expenses', '/bursar/budget.php', 'fa-piggy-bank'],
        ['Payment Gateway', '/bursar/gateway_settings.php', 'fa-credit-card'],
        ['Financial Reports', '/bursar/reports.php', 'fa-chart-line'],
    ],
    'academic_officer' => [
        ['Dashboard', '/academic/dashboard.php', 'fa-book'],
        ['Students', '/academic/students.php', 'fa-user-graduate'],
        ['Teachers Management', '/academic/teachers.php', 'fa-chalkboard-teacher'],
        ['Subjects', '/academic/subjects.php', 'fa-book'],
        ['Class Levels', '/academic/class_levels.php', 'fa-layer-group'],
        ['Classes & Subjects', '/academic/classes.php', 'fa-chalkboard'],
        ['Attendance Overview', '/academic/attendance_overview.php', 'fa-calendar-check'],
        ['Lesson Reports', '/academic/lesson_reports.php', 'fa-chalkboard-teacher'],
        ['Timetable', '/academic/timetable.php', 'fa-calendar-alt'],
        ['Exams', '/academic/exams.php', 'fa-pencil-alt'],
        ['Edit Marks (CRUD)', '/academic/edit_marks.php', 'fa-edit'],
        ['Verify Marks', '/academic/verify_marks.php', 'fa-check-double'],
        ['Publish Results', '/academic/publish_results.php', 'fa-bullhorn'],
        ['Prepare Exam Summary', '/academic/prepare_examination.php', 'fa-cogs'],
        ['Academic Reports', '/academic/reports.php', 'fa-chart-bar'],
        ['Promotions', '/academic/promotions.php', 'fa-level-up-alt'],
        ['Transcripts', '/academic/transcripts.php', 'fa-scroll'],
        ['Parent Accounts', '/academic/parent_accounts.php', 'fa-users'],
    ],
    'subject_teacher' => [
        ['Dashboard', '/teacher/dashboard.php', 'fa-chalkboard-teacher'],
        ['My Classes', '/teacher/my_classes.php', 'fa-users'],
        ['My Timetable', '/teacher/timetable.php', 'fa-calendar-alt'],
        ['Enter Marks', '/teacher/enter_marks.php', 'fa-edit'],
        ['Lesson Attendance', '/teacher/lesson_attendance.php', 'fa-clipboard-check'],
    ],
    'class_teacher' => [
        ['Dashboard', '/class_teacher/dashboard.php', 'fa-chalkboard-teacher'],
        ['My Class', '/class_teacher/my_class.php', 'fa-users'],
        ['Class Subjects', '/class_teacher/subjects.php', 'fa-book'],
        ['Class Timetable', '/class_teacher/timetable.php', 'fa-calendar-alt'],
        ['Subject Teachers', '/class_teacher/teachers.php', 'fa-chalkboard-teacher'],
        ['Class Leaders', '/class_teacher/class_leaders.php', 'fa-user-tie'],
        ['Daily Attendance', '/class_teacher/attendance.php', 'fa-calendar-check'],
        ['Discipline Records', '/class_teacher/discipline.php', 'fa-gavel'],
        ['Student Progress', '/class_teacher/progress.php', 'fa-chart-line'],
        ['Compile Results', '/class_teacher/compile_results.php', 'fa-file-alt'],
        ['Parent Communication', '/communication/inbox.php', 'fa-comments'],
    ],
    'parent' => [
        ['Dashboard', '/parent/dashboard.php', 'fa-home'],
        ['Academic Results', '/parent/results.php', 'fa-graduation-cap'],
        ['Attendance', '/parent/attendance.php', 'fa-calendar-check'],
        ['Fee Status', '/parent/fees.php', 'fa-money-check-alt'],
        ['Discipline Reports', '/parent/discipline.php', 'fa-gavel'],
        ['School Calendar', '/parent/calendar.php', 'fa-calendar-alt'],
        ['Messages', '/communication/inbox.php', 'fa-comments'],
    ],
    'student' => [
        ['Dashboard', '/student/dashboard.php', 'fa-home'],
        ['My Results', '/student/results.php', 'fa-graduation-cap'],
        ['My Timetable', '/student/timetable.php', 'fa-calendar-alt'],
        ['My Attendance', '/student/attendance.php', 'fa-calendar-check'],
        ['Fee Status', '/student/fees.php', 'fa-money-check-alt'],
        ['School Notices', '/student/notices.php', 'fa-bullhorn'],
    ],
    'system_admin' => [
        ['Dashboard', '/director/dashboard.php', 'fa-chart-line'],
        ['Student Management', '/director/students.php', 'fa-user-graduate'],
        ['HR & Staff Management', '/director/staff.php', 'fa-id-badge'],
        ['Financial Overview', '/director/finance_overview.php', 'fa-coins'],
        ['School Calendar', '/director/calendar.php', 'fa-calendar-alt'],
        ['Performance Reports', '/director/performance_reports.php', 'fa-chart-bar'],
        ['User & Role Management', '/director/users.php', 'fa-users-cog'],
        ['Role Permissions', '/director/roles.php', 'fa-user-shield'],
        ['Audit & Security Logs', '/director/audit_logs.php', 'fa-shield-alt'],
        ['System Settings', '/director/system_admin.php', 'fa-cogs'],
        ['Communication Control', '/communication/notifications.php', 'fa-comment-dots'],
        ['Announcements', '/communication/announcements.php', 'fa-bullhorn'],
    ],
];

// department_head reuses head_of_school's menu shape for this build
$menus['department_head'] = $menus['head_of_school'];

$items = $menus[$role] ?? [];
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<nav class="asms-sidebar bg-dark" id="asmsSidebar">
  <!-- User Profile Section -->
  <?php
    $sidebarUserPhoto = $_SESSION['photo_path'] ?? null;
    $sidebarFullName = $_SESSION['full_name'] ?? 'User';
    $sidebarRole = current_role() ?? '';
    $nameParts = explode(' ', $sidebarFullName, 2);
    $sidebarFn = $nameParts[0] ?? '';
    $sidebarLn = $nameParts[1] ?? '';
  ?>
  <a href="<?= e(app_url('/profile/view.php')) ?>" class="text-decoration-none">
    <div class="p-3 d-flex align-items-center gap-3 border-bottom border-secondary">
      <?= render_avatar($sidebarUserPhoto, $sidebarFn, $sidebarLn, 40, '') ?>
      <div class="text-truncate">
        <div class="text-white fw-semibold small text-truncate"><?= e($sidebarFullName) ?></div>
        <div class="text-white-50 small text-truncate"><?= e(ucfirst(str_replace('_', ' ', $sidebarRole))) ?></div>
      </div>
    </div>
  </a>
  <div class="p-3 text-white-50 small text-uppercase fw-bold">Menu</div>
  <ul class="nav flex-column px-2">
    <?php foreach ($items as [$label, $url, $icon]): ?>
      <li class="nav-item">
        <a class="nav-link text-light <?= basename($url) === $currentScript ? 'active' : '' ?>" href="<?= e($base . $url) ?>">
          <i class="fa <?= e($icon) ?> me-2"></i><?= e($label) ?>
        </a>
      </li>
    <?php endforeach; ?>
    <li class="nav-item mt-3 border-top border-secondary pt-2">
      <a class="nav-link text-light" href="<?= e(app_url('/profile/view.php')) ?>">
        <i class="fa fa-user me-2"></i>My Profile
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link text-light" href="<?= e(app_url('/auth/logout.php')) ?>">
        <i class="fa fa-sign-out-alt me-2"></i>Logout
      </a>
    </li>
  </ul>
</nav>
