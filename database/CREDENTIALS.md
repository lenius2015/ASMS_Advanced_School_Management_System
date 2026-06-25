# ASMS - Login Credentials

**Password for ALL accounts:** `test@2026`

> ✅ You can login using **either** your username **or** your email address.
> No password change required on first login.

---

## 🟢 Directors (Super Admin - Full Access)

| Username | Email | Full Name |
|---|---|---|
| `director.demo` | director@example.com | Amani Mwakasege |
| `director.sarah` | sarah.mtei@example.com | Sarah Mtei |
| `director.james` | james.mkude@example.com | James Mkude |

---

## 🟢 System Admin

| Username | Email | Full Name |
|---|---|---|
| `sysadmin.demo` | sysadmin@example.com | Hassan Juma |

---

## 🟢 Head of School

| Username | Email | Full Name |
|---|---|---|
| `headofschool.demo` | headofschool@example.com | Grace Mushi |

---

## 🟢 Bursar (Finance)

| Username | Email | Full Name |
|---|---|---|
| `bursar.demo` | bursar@example.com | John Komba |

---

## 🟢 Academic Officer

| Username | Email | Full Name |
|---|---|---|
| `academic.demo` | academic@example.com | Fatma Salim |

---

## 🟢 Teachers (22 total)

| # | Username | Email | Full Name |
|---|---|---|---|
| 1 | `teacher.demo` | teacher@example.com | Peter Ndege |
| 2 | `classteacher.demo` | classteacher@example.com | Mary Lyimo |
| 3 | `teacher.john.mushi` | john.mushi@example.com | John Mushi |
| 4 | `teacher.grace.lema` | grace.lema@example.com | Grace Lema |
| 5 | `teacher.peter.kiondo` | peter.kiondo@example.com | Peter Kiondo |
| 6 | `teacher.mary.shayo` | mary.shayo@example.com | Mary Shayo |
| 7 | `teacher.david.massawe` | david.massawe@example.com | David Massawe |
| 8 | `teacher.anna.mwenda` | anna.mwenda@example.com | Anna Mwenda |
| 9 | `teacher.michael.sanga` | michael.sanga@example.com | Michael Sanga |
| 10 | `teacher.esther.mlay` | esther.mlay@example.com | Esther Mlay |
| 11 | `teacher.samuel.mboya` | samuel.mboya@example.com | Samuel Mboya |
| 12 | `teacher.dorothy.kessy` | dorothy.kessy@example.com | Dorothy Kessy |
| 13 | `teacher.joseph.njau` | joseph.njau@example.com | Joseph Njau |
| 14 | `teacher.ruth.makoye` | ruth.makoye@example.com | Ruth Makoye |
| 15 | `teacher.daniel.simba` | daniel.simba@example.com | Daniel Simba |
| 16 | `teacher.agnes.mrosso` | agnes.mrosso@example.com | Agnes Mrosso |
| 17 | `teacher.patrick.ndossi` | patrick.ndossi@example.com | Patrick Ndossi |
| 18 | `teacher.beatrice.mushi` | beatrice.mushi@example.com | Beatrice Mushi |
| 19 | `teacher.elijah.mdemu` | elijah.mdemu@example.com | Elijah Mdemu |
| 20 | `teacher.janet.msangi` | janet.msangi@example.com | Janet Msangi |
| 21 | `teacher.thomas.msigwa` | thomas.msigwa@example.com | Thomas Msigwa |
| 22 | `teacher.lilian.nyaki` | lilian.nyaki@example.com | Lilian Nyaki |

---

## 🟢 Sample Parent Accounts (All 100 parents exist)

| Username | Email | Full Name | Child |
|---|---|---|---|
| `parent.demo` | parent@example.com | Esther Mollel | David Mollel |
| ... and 99 more parents ... | | | |

> All parent usernames follow pattern: `parent.{lastname}.{childfirst}.{number}`
> All parents are linked to their respective children's accounts.

---

## 🟢 Sample Student Accounts (All 100 students exist)

| Admission No | Username | Email | Full Name | Class |
|---|---|---|---|---|
| STU-2026-0001 | `student.demo` | student@example.com | David Mollel | Form 1 A |
| ... and 99 more students ... | | | | |

> Student usernames follow: `student.{firstname}.{lastname}.{number}`
> Password for ALL: **test@2026**

---

## 📊 Classes Available (7 classes)

| Class | Stream | Capacity |
|---|---|---|
| Form 1 | A | 40 |
| Form 1 | B | 40 |
| Form 1 | C | 40 |
| Form 2 | A | 40 |
| Form 2 | B | 40 |
| Form 3 | A | 40 |
| Form 4 | A | 40 |

---

## 📚 Subjects (12 subjects)

Mathematics, English, Kiswahili, Biology, Chemistry, Physics, Geography, History, Computer Studies, Civics, Islamic Knowledge, French

---

## Quick Start

1. Go to: `http://localhost/ASMS_Advanced_School_Management_System/`
2. Login with any username **or email** above
3. Password: **test@2026**
4. You'll be redirected to your role-specific dashboard
</write_to_file>
<task_progress>
- [x] Generate new password hash for test@2026
- [x] Update all users' passwords in database
- [x] Modify login/auth to accept email as username
- [x] Update seed_data.sql with new hash
- [x] Update CREDENTIALS.md
- [ ] Clean up temp files
- [ ] Test login
</task_progress>
</write_to_file>