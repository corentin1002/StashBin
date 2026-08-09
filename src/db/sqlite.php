<?php
// SQLite driver — the default, and the only one that needs no server.
//
// One file per engine in this directory, each returning the array below;
// src/bootstrap.php knows that shape and nothing else about any engine. Adding
// a database means dropping a file here, as adding a language means dropping
// one in src/lang/.
//
//   connect   builds the PDO connection from the "db" setting, in array form
//   migrate   creates what is missing, and is harmless once it is not
//   json_bool reads a boolean out of a JSON column, as 1 or 0
//   integer   forces an expression to be compared as a number
//   sequence  a column ordering rows by insertion, to break a tie on a date
//
// The last three are SQL fragments interpolated into queries: they come from
// this file and never from a request.
declare(strict_types=1);

return [
    'connect' => static function (array $db): PDO {
        $path = (string) ($db['path'] ?? '');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    },

    // Creates what is missing, on an empty database as well as on one that
    // predates the creator's inventory. There is no migration table: SQLite
    // describes its own schema, which is a more reliable answer to "which
    // version is this" than a number we would have to remember to bump.
    'migrate' => static function (PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            pass_hash TEXT NOT NULL,
            created INTEGER NOT NULL
        )');
        // A secret whose owner's account is deleted keeps working: the links
        // handed out are still valid, they simply stop appearing in anyone's
        // list.
        $pdo->exec('CREATE TABLE IF NOT EXISTS pastes (
            id TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            burn INTEGER NOT NULL DEFAULT 0,
            delete_hash TEXT NOT NULL,
            created INTEGER NOT NULL,
            expires INTEGER,
            owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            gone INTEGER,
            gone_cause TEXT
        )');
        // One row per access to a secret's payload. Deleting the secret for
        // good takes its log with it.
        $pdo->exec('CREATE TABLE IF NOT EXISTS paste_reads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            paste_id TEXT NOT NULL REFERENCES pastes(id) ON DELETE CASCADE,
            at INTEGER NOT NULL,
            outcome TEXT NOT NULL,
            ip TEXT,
            agent TEXT
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS paste_reads_by_paste ON paste_reads (paste_id, at)');

        // Columns added after the first version. SQLite only allows ADD COLUMN
        // with a NULL default when a REFERENCES clause is involved, which is
        // what these all are.
        $existing = array_column($pdo->query('PRAGMA table_info(pastes)')->fetchAll(), 'name');
        $added = [
            'owner_id'   => 'INTEGER REFERENCES users(id) ON DELETE SET NULL',
            'gone'       => 'INTEGER',
            'gone_cause' => 'TEXT',
        ];
        foreach ($added as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE pastes ADD COLUMN $column $definition");
            }
        }
    },

    // json_extract() returns SQLite's own 1 and 0 for a JSON true and false,
    // and raises on the empty string a headstone carries — hence the guard,
    // without which the inventory would die on its first finished secret.
    'json_bool' => static fn (string $column, string $path): string
        => "CASE WHEN json_valid($column) THEN json_extract($column, '$path') END",

    // SQLite compares by storage class, and an integer is *always* smaller
    // than a string: PDO binds parameters as text, so "COUNT(*) < ?" is true
    // whatever the count until the parameter is cast back to a number.
    'integer' => static fn (string $expression): string => "CAST($expression AS INTEGER)",

    // Every table has one, without asking for it.
    'sequence' => 'rowid',
];
