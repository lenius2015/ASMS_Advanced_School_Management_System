<?php
require_once __DIR__ . "/config/config.php";
\$pdo = get_db_connection();
\$r1 = attempt_login(\$pdo, "director.demo", "test@2026");
echo "Username login: " . (\$r1["ok"] ? "OK" : "FAIL") . chr(10);
\$r2 = attempt_login(\$pdo, "director@example.com", "test@2026");
echo "Email login: " . (\$r2["ok"] ? "OK" : "FAIL") . chr(10);
echo "ALL DONE" . chr(10);