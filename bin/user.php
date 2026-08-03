#!/usr/bin/env php
<?php
// Management of the users allowed to create secrets.
//
//   php bin/user.php add <user>      create an account (password prompted for)
//   php bin/user.php passwd <user>   change the password
//   php bin/user.php del <user>      delete the account
//   php bin/user.php list            list the accounts

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

function prompt_password(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    shell_exec('stty -echo 2>/dev/null');
    $password = rtrim((string) fgets(STDIN), "\n");
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    if (strlen($password) < 8) {
        fwrite(STDERR, "The password must be at least 8 characters long.\n");
        exit(1);
    }
    return $password;
}

$command = $argv[1] ?? 'help';
$username = $argv[2] ?? null;

// Accounts stay manageable on an open instance, but serve no purpose there:
// saying so saves wondering why no sign-in is ever asked for.
if (!auth_enabled() && in_array($command, ['add', 'passwd', 'del', 'list'], true)) {
    fwrite(STDERR, "Note: authentication is disabled (the \"auth\" setting); accounts are not used.\n");
}

switch ($command) {
    case 'add':
        if (!$username) {
            fwrite(STDERR, "Usage: php bin/user.php add <user>\n");
            exit(1);
        }
        $hash = password_hash(prompt_password("Password for \"$username\""), PASSWORD_DEFAULT);
        try {
            db()->prepare('INSERT INTO users (username, pass_hash, created) VALUES (?, ?, ?)')
                ->execute([$username, $hash, time()]);
        } catch (PDOException) {
            fwrite(STDERR, "User \"$username\" already exists.\n");
            exit(1);
        }
        echo "User \"$username\" created.\n";
        break;

    case 'passwd':
        if (!$username) {
            fwrite(STDERR, "Usage: php bin/user.php passwd <user>\n");
            exit(1);
        }
        $hash = password_hash(prompt_password("New password for \"$username\""), PASSWORD_DEFAULT);
        $stmt = db()->prepare('UPDATE users SET pass_hash = ? WHERE username = ?');
        $stmt->execute([$hash, $username]);
        if ($stmt->rowCount() === 0) {
            fwrite(STDERR, "User \"$username\" not found.\n");
            exit(1);
        }
        echo "Password for \"$username\" updated.\n";
        break;

    case 'del':
        if (!$username) {
            fwrite(STDERR, "Usage: php bin/user.php del <user>\n");
            exit(1);
        }
        $stmt = db()->prepare('DELETE FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->rowCount() === 0) {
            fwrite(STDERR, "User \"$username\" not found.\n");
            exit(1);
        }
        echo "User \"$username\" deleted.\n";
        break;

    case 'list':
        foreach (db()->query('SELECT username, created FROM users ORDER BY username') as $row) {
            printf("%-24s created on %s\n", $row['username'], date('Y-m-d', (int) $row['created']));
        }
        break;

    default:
        echo "Usage: php bin/user.php {add|passwd|del|list} [user]\n";
}
