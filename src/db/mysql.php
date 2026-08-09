<?php
// MariaDB / MySQL driver. Both speak the same protocol and go through the same
// PDO driver, so "mariadb" in the configuration leads here too.
//
// See src/db/sqlite.php for what the array below is and what each key owes the
// rest of the code.
declare(strict_types=1);

// Everything is stored under a binary collation. The default one compares
// without regard to case, and identifiers here are case-sensitive: "aB3" and
// "Ab3" are two different secrets, and serving one for the other would hand a
// reader somebody else's ciphertext. The same goes for account names.
$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';

return [
    'connect' => static function (array $db): PDO {
        $dsn = ['charset=' . ($db['charset'] ?? 'utf8mb4')];
        // A socket and a host are mutually exclusive; the socket wins when it
        // is given, which is how a database on the same machine is reached.
        if (($db['socket'] ?? '') !== '') {
            $dsn[] = 'unix_socket=' . $db['socket'];
        } else {
            $dsn[] = 'host=' . ($db['host'] ?? '127.0.0.1');
            $dsn[] = 'port=' . (int) ($db['port'] ?? 3306);
        }
        $dsn[] = 'dbname=' . ($db['name'] ?? 'stashbin');

        $pdo = new PDO(
            'mysql:' . implode(';', $dsn),
            ($db['user'] ?? null) === '' ? null : ($db['user'] ?? null),
            ($db['pass'] ?? null) === '' ? null : ($db['pass'] ?? null),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements, not statements PDO interpolates
                // itself: interpolation quotes every parameter, and a quoted
                // "LIMIT ?" is a syntax error. The values also stay out of the
                // SQL entirely.
                PDO::ATTR_EMULATE_PREPARES => false,
                // api.php compares "expires" to time() without casting.
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );

        // Not a preference: InnoDB's default level locks the gaps between the
        // rows a statement reads, and the access log is written by an
        // "INSERT … SELECT" that counts the very rows it is about to add to.
        // Two readers arriving together each hold a shared lock on the same
        // gap and each want to insert into it — a deadlock, reported to one of
        // them as a failure to read an auto-increment value. Twenty
        // simultaneous readers of a single-use secret produced it every time.
        //
        // Reading the latest committed state, statement by statement, is also
        // what SQLite does under WAL: the two engines behave alike.
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return $pdo;
    },

    // The database itself is not created here: that takes a privilege an
    // application has no business holding. Its tables are, on first use.
    //
    // No ALTER to be found: unlike the SQLite schema, this one has never had an
    // earlier version to bring forward. What is created is what the code
    // expects, or the connection fails outright.
    'migrate' => static function (PDO $pdo) use ($table): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(190) NOT NULL,
            pass_hash VARCHAR(255) NOT NULL,
            created BIGINT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY users_username (username)
        ) ' . $table);
        // "seq" is the insertion order, which SQLite hands out for free as
        // rowid: without it two secrets created in the same second — created is
        // in seconds — have no order at all in the inventory.
        //
        // A secret whose owner's account is deleted keeps working: the links
        // handed out are still valid, they simply stop appearing in anyone's
        // list.
        $pdo->exec('CREATE TABLE IF NOT EXISTS pastes (
            id VARCHAR(32) NOT NULL,
            seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payload LONGTEXT NOT NULL,
            burn TINYINT NOT NULL DEFAULT 0,
            delete_hash VARCHAR(64) NOT NULL,
            created BIGINT NOT NULL,
            expires BIGINT NULL,
            owner_id INT UNSIGNED NULL,
            gone BIGINT NULL,
            gone_cause VARCHAR(32) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY pastes_seq (seq),
            KEY pastes_owner (owner_id),
            CONSTRAINT pastes_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL
        ) ' . $table);
        // One row per access to a secret's payload. Deleting the secret for
        // good takes its log with it.
        $pdo->exec('CREATE TABLE IF NOT EXISTS paste_reads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            paste_id VARCHAR(32) NOT NULL,
            at BIGINT NOT NULL,
            outcome VARCHAR(32) NOT NULL,
            ip VARCHAR(45) NULL,
            agent VARCHAR(200) NULL,
            PRIMARY KEY (id),
            KEY paste_reads_by_paste (paste_id, at),
            CONSTRAINT paste_reads_paste FOREIGN KEY (paste_id) REFERENCES pastes (id) ON DELETE CASCADE
        ) ' . $table);
    },

    // JSON_EXTRACT() returns JSON, so a boolean comes back as the text "true"
    // and never as 1. The payload is whatever its author sent — the browser
    // writes 1 or 0, another client may well write true — and the server keeps
    // it unchanged, so both spellings have to be read as what they mean.
    //
    // The guard is the same as SQLite's, for the same reason: a headstone's
    // payload is the empty string, which is not JSON.
    'json_bool' => static fn (string $column, string $path): string
        => "CASE WHEN JSON_VALID($column)
                 THEN JSON_UNQUOTE(JSON_EXTRACT($column, '$path')) IN ('true', '1') END",

    'integer' => static fn (string $expression): string => "CAST($expression AS SIGNED)",

    'sequence' => 'seq',
];
