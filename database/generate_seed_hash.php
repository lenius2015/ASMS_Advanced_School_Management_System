<?php
/**
 * generate_seed_hash.php
 *
 * Run this once with the PHP CLI to generate a real bcrypt hash for the
 * default seed password, then paste the output into database/seed_data.sql
 * in place of the @pwd_hash placeholder value.
 *
 * Usage:
 *   php database/generate_seed_hash.php
 *
 * Why this exists: password_hash() output includes a random salt, so it
 * cannot be hard-coded reliably ahead of time across PHP builds. Generate
 * it fresh on your own server before importing seed_data.sql.
 */

$password = 'test@2026';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

echo "Password : {$password}\n";
echo "Hash     : {$hash}\n\n";
echo "Copy the hash above and replace the @pwd_hash value in database/seed_data.sql, e.g.:\n";
echo "SET @pwd_hash = '{$hash}';\n";
