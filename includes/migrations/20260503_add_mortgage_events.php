<?php
return [
    'name' => '20260503_add_mortgage_events',
    'check' => function(PDO $db): bool {
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mortgage_component_events'");
        return $stmt->fetchColumn() === false;
    },
    'up' => function(PDO $db): void {
        $db->exec("ALTER TABLE mortgages ADD COLUMN months_elapsed INTEGER NOT NULL DEFAULT 0;");
        $db->exec("CREATE TABLE mortgage_component_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            component_id INTEGER NOT NULL,
            month_index INTEGER NOT NULL,
            rate REAL,
            extra_payment REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(component_id) REFERENCES mortgage_components(id) ON DELETE CASCADE
        );");
        $db->exec("CREATE UNIQUE INDEX idx_mortgage_component_events_unique_month ON mortgage_component_events(component_id, month_index);");

        $db->exec("CREATE TABLE mortgage_value_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mortgage_id INTEGER NOT NULL,
            month_index INTEGER NOT NULL,
            property_value REAL NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(mortgage_id) REFERENCES mortgages(id) ON DELETE CASCADE
        );");
        $db->exec("CREATE UNIQUE INDEX idx_mortgage_value_events_unique_month ON mortgage_value_events(mortgage_id, month_index);");
    },
];
