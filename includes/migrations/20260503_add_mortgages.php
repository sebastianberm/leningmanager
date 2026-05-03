<?php
return [
    'name' => '20260503_add_mortgages',
    'check' => function(PDO $db): bool {
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mortgages'");
        return $stmt->fetchColumn() === false;
    },
    'up' => function(PDO $db): void {
        $db->exec("CREATE TABLE mortgages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            property_value REAL NOT NULL,
            start_date TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(owner_id) REFERENCES users(id)
        );");

        $db->exec("CREATE TABLE mortgage_components (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mortgage_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            principal REAL NOT NULL,
            rate REAL NOT NULL,
            term_months INTEGER NOT NULL,
            fixed_rate_months INTEGER NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('annuity','linear','interest_only')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(mortgage_id) REFERENCES mortgages(id) ON DELETE CASCADE
        );");
    },
];
