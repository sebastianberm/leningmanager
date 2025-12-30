<?php
return [
    'name' => '20251230_add_lender_type',
    'check' => function(PDO $db) {
        $cols = $db->query("PRAGMA table_info(loans);")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            if ($c['name'] === 'lender_type') return false; // not needed
        }
        return true; // needed
    },
    'up' => function(PDO $db) {
        // Add lender_type column with default from config or 'private'
        $default = defined('DEFAULT_LENDER_TYPE') ? DEFAULT_LENDER_TYPE : 'private';
        $db->exec("ALTER TABLE loans ADD COLUMN lender_type TEXT DEFAULT '" . $default . "';");
    }
];
