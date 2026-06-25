<?php
/**
 * database/generate_bulk_data.php
 * 
 * Generates 100 students with parents, 20 teachers, 2 directors,
 * additional classes, and assigns teachers to subjects.
 * 
 * Run: php database/generate_bulk_data.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = get_db_connection();
$pdo->beginTransaction();

echo "=== ASMS Bulk Data Generator ===\n\n";

// ---- Step 1: Create additional classes (Form 1-4 streams) ----
echo "Creating additional classes...\n";

// Fetch class_levels
$levels = $pdo->query("SELECT class_level_id, level_name FROM class_levels ORDER BY class_level_id")->fetchAll();
$levelMap = [];
foreach ($levels as $l) {
    $levelMap[$l['level_name']] = (int)$l['class_level_id'];
}

$newClasses = [
    ['level' => 'Form 1', 'stream' => 'B', 'year_id' => 1, 'capacity' => 40],
    ['level' => 'Form 1', 'stream' => 'C', 'year_id' => 1, 'capacity' => 40],
    ['level' => 'Form 2', 'stream' => 'B', 'year_id' => 1, 'capacity' => 40],
    ['level' => 'Form 3', 'stream' => 'A', 'year_id' => 1, 'capacity' => 40],
    ['level' => 'Form 4', 'stream' => 'A', 'year_id' => 1, 'capacity' => 40],
];

$classInsert = $pdo->prepare(
    "INSERT INTO classes (class_level_id, stream_name, year_id, capacity) VALUES (:level_id, :stream, :year_id, :capacity)"
);

foreach ($newClasses as $c) {
    $classInsert->execute([
        'level_id' => $levelMap[$c['level']],
        'stream' => $c['stream'],
        'year_id' => $c['year_id'],
        'capacity' => $c['capacity'],
    ]);
    echo "  Created {$c['level']} {$c['stream']}\n";
}

// ---- Step 2: Create additional subjects ----
echo "\nCreating additional subjects...\n";
$extraSubjects = [
    ['Civics', 'CIV'],
    ['Islamic Knowledge', 'ISLAM'],
    ['French', 'FREN'],
];
$subjInsert = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code, is_active) VALUES (:name, :code, 1)");
foreach ($extraSubjects as $s) {
    $subjInsert->execute(['name' => $s[0], 'code' => $s[1]]);
    echo "  Added subject: {$s[0]} ({$s[1]})\n";
}

// Fetch all subjects
$allSubjects = $pdo->query("SELECT subject_id, subject_code FROM subjects WHERE is_active = 1")->fetchAll();
$subjectIds = array_column($allSubjects, 'subject_id');

// Fetch all classes now
$allClasses = $pdo->query("SELECT class_id, class_level_id FROM classes WHERE year_id = 1")->fetchAll();
$classIds = array_column($allClasses, 'class_id');

// Fetch role IDs
$roles = $pdo->query("SELECT role_id, role_name FROM roles")->fetchAll();
$roleMap = [];
foreach ($roles as $r) {
    $roleMap[$r['role_name']] = (int)$r['role_id'];
}

// Fetch department IDs
$depts = $pdo->query("SELECT department_id, department_name FROM departments")->fetchAll();
$deptMap = [];
foreach ($depts as $d) {
    $deptMap[$d['department_name']] = (int)$d['department_id'];
}

$pwdHash = password_hash('Passw0rd!2026', PASSWORD_BCRYPT, ['cost' => 10]);
echo "  Using password hash: {$pwdHash}\n";

// ---- Step 3: Create 2 Directors ----
echo "\nCreating 2 Directors...\n";
$directorData = [
    ['username' => 'director.sarah',  'first' => 'Sarah',  'last' => 'Mtei',    'email' => 'sarah.mtei@example.com',   'phone' => '+255700000010'],
    ['username' => 'director.james',  'first' => 'James',  'last' => 'Mkude',   'email' => 'james.mkude@example.com',  'phone' => '+255700000011'],
];

$userInsert = $pdo->prepare(
    "INSERT INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
     VALUES (UUID(), :role_id, :username, :email, :phone, :pwd_hash, :first, :last, :gender, 1, 1)"
);

foreach ($directorData as $d) {
    $userInsert->execute([
        'role_id'  => $roleMap['director'],
        'username' => $d['username'],
        'email'    => $d['email'],
        'phone'    => $d['phone'],
        'pwd_hash' => $pwdHash,
        'first'    => $d['first'],
        'last'     => $d['last'],
        'gender'   => 'male',
    ]);
    echo "  Created director: {$d['username']} / Passw0rd!2026\n";
}

// ---- Step 4: Create 20 Teachers ----
echo "\nCreating 20 Teachers...\n";
$teacherNames = [
    ['John', 'Mushi'],    ['Grace', 'Lema'],    ['Peter', 'Kiondo'],  ['Mary', 'Shayo'],
    ['David', 'Massawe'], ['Anna', 'Mwenda'],   ['Michael', 'Sanga'], ['Esther', 'Mlay'],
    ['Samuel', 'Mboya'],  ['Dorothy', 'Kessy'], ['Joseph', 'Njau'],   ['Ruth', 'Makoye'],
    ['Daniel', 'Simba'],  ['Agnes', 'Mrosso'],  ['Patrick', 'Ndossi'],['Beatrice', 'Mushi'],
    ['Elijah', 'Mdemu'],  ['Janet', 'Msangi'],  ['Thomas', 'Msigwa'], ['Lilian', 'Nyaki'],
];

$staffInsert = $pdo->prepare(
    "INSERT INTO staff (user_id, staff_no, department_id, job_title, employment_type, date_hired, basic_salary, status)
     VALUES (:uid, :staff_no, :dept_id, 'Subject Teacher', 'full_time', :date_hired, :salary, 'active')"
);

$teacherUserIds = [];
$subjectTeacherRoleId = $roleMap['subject_teacher'];
$academicDeptId = $deptMap['Academic'];

for ($i = 0; $i < 20; $i++) {
    $t = $teacherNames[$i];
    $username = 'teacher.' . strtolower($t[0]) . '.' . strtolower($t[1]);
    $email = strtolower($t[0]) . '.' . strtolower($t[1]) . '@example.com';
    $phone = '+255700' . str_pad(20 + $i, 5, '0', STR_PAD_LEFT);
    $gender = ($i % 2 === 0) ? 'male' : 'female';
    $staffNo = 'STF-2026-' . str_pad(5 + $i, 4, '0', STR_PAD_LEFT);
    $dateHired = '202' . (2 + ($i % 4)) . '-' . str_pad(1 + ($i % 12), 2, '0', STR_PAD_LEFT) . '-15';
    $salary = 800000 + ($i * 25000);

    $userInsert->execute([
        'role_id'  => $subjectTeacherRoleId,
        'username' => $username,
        'email'    => $email,
        'phone'    => $phone,
        'pwd_hash' => $pwdHash,
        'first'    => $t[0],
        'last'     => $t[1],
        'gender'   => $gender,
    ]);
    $uid = $pdo->lastInsertId();
    $teacherUserIds[] = $uid;

    $staffInsert->execute([
        'uid'       => $uid,
        'staff_no'  => $staffNo,
        'dept_id'   => $academicDeptId,
        'date_hired' => $dateHired,
        'salary'    => $salary,
    ]);
    echo "  Created teacher: {$username} / Passw0rd!2026\n";
}

// ---- Step 5: Assign teachers to class subjects ----
echo "\nAssigning teachers to class subjects...\n";

// For each class, assign some subjects with teachers
$classSubjInsert = $pdo->prepare(
    "INSERT INTO class_subjects (class_id, subject_id, teacher_id) VALUES (:class_id, :subject_id, :teacher_id)
     ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id)"
);

$teacherIdx = 0;
foreach ($classIds as $cid) {
    // Assign first 6-8 subjects per class with teachers
    $numSubjects = min(count($subjectIds), 6 + ($cid % 4));
    for ($s = 0; $s < $numSubjects; $s++) {
        $teachId = $teacherUserIds[$teacherIdx % count($teacherUserIds)];
        $teacherIdx++;
        $classSubjInsert->execute([
            'class_id'   => $cid,
            'subject_id' => $subjectIds[$s % count($subjectIds)],
            'teacher_id' => $teachId,
        ]);
    }
}
echo "  Assigned teachers to class subjects.\n";

// ---- Step 6: Create 100 Students with Parents and Guardians ----
echo "\nCreating 100 Students with Parents...\n";

$studentNames = [
    'Abdul','Aisha','Baraka','Catherine','Charles','Diana','Edwin','Elizabeth','Emmanuel','Farida',
    'Fatuma','Fidelis','George','Gloria','Hamisi','Hawa','Ibrahim','Irene','Isaac','Jackline',
    'Jacob','Janeth','Juma','Juliana','Kassim','Keren','Khalid','Latifa','Lawrence','Lillian',
    'Lucas','Mariam','Mathew','Martha','Mbwana','Miraji','Mohamed','Monica','Moses','Mwajuma',
    'Mwanaisha','Nancy','Nasibu','Neema','Oscar','Pamela','Paulo','Rachel','Rashid','Rehema',
    'Richard','Rose','Saidi','Salma','Samson','Sarah','Sebastian','Shamim','Sharon','Shija',
    'Simoni','Sophia','Stella','Suleiman','Tabu','Tatu','Timothy','Veronica','Victoria','Zainabu',
    'Zakaria','Baraka','Mwanamke','Salim','Hussein','Khadija','Ramadhani','Asha','Iddi','Mwajuma',
    'Juma','Mwanaisha','Msafiri','Upendo','Daudi','Sofia','Ezekiel','Dorcas','Yohana','Lilian',
    'Omari','Mwanajuma','Rajabu','Mariam','Hamza','Amina','Bakari','Zainab','Mkubwa','Simba',
];

$parentLastNames = [
    'Mushi','Lema','Kiondo','Shayo','Massawe','Mwenda','Sanga','Mlay','Mboya','Kessy',
    'Njau','Makoye','Simba','Mrosso','Ndossi','Msangi','Msigwa','Nyaki','Mtei','Mkude',
    'Mrema','Mwakasege','Mollel','Komba','Salim','Ndege','Lyimo','Juma','Mollel','Mushi',
];

$userInsert2 = $pdo->prepare(
    "INSERT INTO users (uuid, role_id, username, email, phone, password_hash, first_name, last_name, gender, is_active, must_change_password)
     VALUES (UUID(), :role_id, :username, :email, :phone, :pwd_hash, :first, :last, :gender, 1, 1)"
);

$guardianInsert = $pdo->prepare(
    "INSERT INTO guardians (user_id, first_name, last_name, relationship, phone, email)
     VALUES (:uid, :first, :last, :relationship, :phone, :email)"
);

$studentInsert = $pdo->prepare(
    "INSERT INTO students (user_id, admission_no, class_id, date_of_birth, gender, admission_date, status)
     VALUES (:uid, :admission_no, :class_id, :dob, :gender, :admission_date, 'active')"
);

$sgInsert = $pdo->prepare(
    "INSERT INTO student_guardians (student_id, guardian_id, is_primary_contact) VALUES (:sid, :gid, 1)"
);

$invoiceInsert = $pdo->prepare(
    "INSERT INTO invoices (student_id, term_id, invoice_no, total_amount, amount_paid, balance, due_date, status)
     VALUES (:sid, 1, :inv_no, 450000.00, 0.00, 450000.00, '2026-02-15', 'unpaid')"
);

$invoiceItemInsert = $pdo->prepare(
    "INSERT INTO invoice_items (invoice_id, fee_category_id, description, amount) VALUES (:inv_id, 1, 'Term 1 Tuition Fee', 400000.00), (:inv_id2, 4, 'Examination Fee', 50000.00)"
);

// Get parent role
$parentRoleId = $roleMap['parent'];
$studentRoleId = $roleMap['student'];

for ($i = 0; $i < 100; $i++) {
    $studentFirst = $studentNames[$i];
    $parentLast = $parentLastNames[$i % count($parentLastNames)];
    $studentLast = $parentLast;
    $gender = ($i % 2 === 0) ? 'male' : 'female';

    // Determine class distribution (spread across 7 classes)
    $classIdx = $i % count($classIds);
    $classId = $classIds[$classIdx];
    $dob = (2010 + ($i % 4)) . '-' . str_pad(1 + ($i % 12), 2, '0', STR_PAD_LEFT) . '-' . str_pad(1 + ($i % 28), 2, '0', STR_PAD_LEFT);

    // Create Student User
    $studentUsername = 'student.' . strtolower($studentFirst) . '.' . strtolower($studentLast) . ($i > 0 ? (string)($i + 1) : '');
    if ($i === 0) {
        // Skip, already exists from seed
        continue;
    }
    // Make unique username
    $studentUsername = 'student.' . strtolower($studentFirst) . '.' . strtolower($studentLast) . '.' . str_pad($i + 1, 3, '0', STR_PAD_LEFT);
    $studentEmail = strtolower($studentFirst) . '.' . strtolower($studentLast) . '.' . ($i + 1) . '@student.example.com';

    $userInsert2->execute([
        'role_id'  => $studentRoleId,
        'username' => $studentUsername,
        'email'    => $studentEmail,
        'phone'    => '+255700' . str_pad(100 + $i, 5, '0', STR_PAD_LEFT),
        'pwd_hash' => $pwdHash,
        'first'    => $studentFirst,
        'last'     => $studentLast,
        'gender'   => $gender,
    ]);
    $studentUserId = $pdo->lastInsertId();

    // Create Parent User
    $parentFirst = $parentLastNames[($i + 3) % count($parentLastNames)];
    $parentUsername = 'parent.' . strtolower($parentFirst) . '.' . strtolower($studentLast) . ($i > 0 ? '.' . str_pad($i + 1, 3, '0', STR_PAD_LEFT) : '');
    $parentEmail = strtolower($parentFirst) . '.' . strtolower($studentLast) . '.' . ($i + 1) . '@parent.example.com';

    $userInsert2->execute([
        'role_id'  => $parentRoleId,
        'username' => $parentUsername,
        'email'    => $parentEmail,
        'phone'    => '+255700' . str_pad(300 + $i, 5, '0', STR_PAD_LEFT),
        'pwd_hash' => $pwdHash,
        'first'    => $parentFirst,
        'last'     => $studentLast,
        'gender'   => ($i % 2 === 0) ? 'female' : 'male',
    ]);
    $parentUserId = $pdo->lastInsertId();

    // Create Student Record
    $admissionNo = 'STU-2026-' . str_pad(2 + $i, 4, '0', STR_PAD_LEFT);
    $studentInsert->execute([
        'uid'           => $studentUserId,
        'admission_no'  => $admissionNo,
        'class_id'      => $classId,
        'dob'           => $dob,
        'gender'        => $gender,
        'admission_date' => '2026-01-06',
    ]);
    $studentId = $pdo->lastInsertId();

    // Create Guardian Record
    $guardianInsert->execute([
        'uid'          => $parentUserId,
        'first'        => $parentFirst,
        'last'         => $studentLast,
        'relationship' => ($i % 4 === 3) ? 'guardian' : 'father',
        'phone'        => '+255700' . str_pad(300 + $i, 5, '0', STR_PAD_LEFT),
        'email'        => $parentEmail,
    ]);
    $guardianId = $pdo->lastInsertId();

    // Link Student-Guardian
    $sgInsert->execute(['sid' => $studentId, 'gid' => $guardianId]);

    // Create Invoice for student
    $invNo = 'INV-2026-' . str_pad(2 + $i, 6, '0', STR_PAD_LEFT);
    $invoiceInsert->execute([
        'sid'    => $studentId,
        'inv_no' => $invNo,
    ]);
    $invoiceId = $pdo->lastInsertId();

    $invoiceItemInsert->execute(['inv_id' => $invoiceId, 'inv_id2' => $invoiceId]);

    if (($i + 1) % 10 === 0) {
        echo "  Created student {$admissionNo}: {$studentUsername} / Passw0rd!2026\n";
    }
}

// ---- Update ID sequences ----
$pdo->exec("INSERT INTO id_sequences (seq_key, last_value) VALUES ('STU-2026', 101) ON DUPLICATE KEY UPDATE last_value = 101");
$pdo->exec("INSERT INTO id_sequences (seq_key, last_value) VALUES ('STF-2026', 25) ON DUPLICATE KEY UPDATE last_value = 25");

$pdo->commit();

echo "\n=== BULK DATA GENERATION COMPLETE ===\n";
echo "Created:\n";
echo "  - 2 Directors\n";
echo "  - 20 Teachers\n";
echo "  - 99 Students (plus 1 existing = 100 total)\n";
echo "  - 99 Parents (plus 1 existing = 100 total)\n";
echo "  - 5 Additional classes (7 total)\n";
echo "  - 3 Additional subjects (12 total)\n";
echo "  - Assigned teachers to class subjects\n";
echo "  - Created invoices for all students\n";
echo "\nAll passwords: Passw0rd!2026\n";
echo "\nRun the login credentials script to get all usernames.\n";