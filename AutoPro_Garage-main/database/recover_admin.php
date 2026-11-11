<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

function prompt_line(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Unable to read administrator details from the console.');
    }
    return trim($value);
}

try {
    fwrite(STDOUT, "This creates one administrator only when no active administrator account exists.\n");
    fwrite(STDOUT, "Continue only with explicit garage-owner authorization.\n");
    if (prompt_line('Type RECOVER ADMIN to continue: ') !== 'RECOVER ADMIN') {
        throw new RuntimeException('Administrator recovery was not confirmed.');
    }

    $fullName = prompt_line('Administrator full name: ');
    $username = prompt_line('Administrator username: ');
    $email = strtolower(prompt_line('Administrator email: '));
    $errors = validate(
        ['full_name' => $fullName, 'username' => $username, 'email' => $email],
        ['full_name' => 'required|max:120', 'username' => 'required|min:3|max:50', 'email' => 'required|email|max:150']
    );
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
        $errors['username'] = 'Use letters, digits, dot, dash and underscore only.';
    }
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', array_values($errors)));
    }

    $passwordInput = prompt_line('Administrator password (leave blank to auto-generate): ');
    $isGenerated = false;
    if ($passwordInput !== '') {
        $pProblems = AuthService::passwordProblems($passwordInput, $username);
        if ($pProblems) {
            throw new InvalidArgumentException('Password requirements not met: ' . implode(', ', $pProblems));
        }
        $password = $passwordInput;
    } else {
        $isGenerated = true;
        do {
            $password = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        } while (AuthService::passwordProblems($password, $username));
    }

    $userId = Database::transaction(function () use ($fullName, $username, $email, $password, $isGenerated): int {
        $roleId = Database::value("SELECT id FROM roles WHERE name = 'administrator' FOR UPDATE");
        if (!$roleId) {
            throw new RuntimeException('The administrator role is missing; restore the role schema before recovery.');
        }
        if ((int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [(int) $roleId]) > 0) {
            throw new RuntimeException('An active administrator account already exists; recovery did not create another.');
        }
        if ((int) Database::value(
            'SELECT COUNT(*) FROM users WHERE username = ? OR LOWER(email) = ?',
            [$username, $email]
        ) > 0) {
            throw new RuntimeException('The username or email is already in use; recovery did not change existing accounts.');
        }

        $mustChange = $isGenerated ? 1 : 0;
        $id = Database::insert(
            'INSERT INTO users (role_id, username, full_name, email, password_hash, is_active, must_change_password)
             VALUES (?, ?, ?, ?, ?, 1, ?)',
            [(int) $roleId, $username, $fullName, $email, AuthService::hash($password), $mustChange]
        );
        AuditLogger::log(
            'administrator_recovered',
            'users',
            $id,
            null,
            ['username' => $username, 'channel' => 'cli_recovery'],
            'success',
            ['user_id' => $id, 'username' => $username, 'role' => 'administrator']
        );
        return $id;
    });

    if ($isGenerated) {
        fwrite(STDOUT, "\nAdministrator account created. Change this one-time password immediately after signing in.\n");
        fwrite(STDOUT, "Username: $username\nEmail: $email\nOne-time password: $password\n");
        fwrite(STDOUT, "The password is not written to application logs. Store it securely, then remove it from this console history.\n");
    } else {
        fwrite(STDOUT, "\n[SUCCESS] Administrator account created successfully.\n");
        fwrite(STDOUT, "Username: $username\nEmail: $email\n");
        fwrite(STDOUT, "You may now sign in at /login.php\n");
    }
} catch (Throwable $e) {
    app_log('CLI administrator recovery failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, 'Administrator recovery failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
