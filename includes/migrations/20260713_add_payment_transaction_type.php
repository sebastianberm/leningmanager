<?php
return [
    'name' => '20260713_add_payment_transaction_type',
    'check' => function(PDO $db): bool {
        $cols = $db->query("PRAGMA table_info(payments)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            if (($col['name'] ?? '') === 'transaction_type') return false;
        }
        return true;
    },
    'up' => function(PDO $db): void {
        $db->exec("ALTER TABLE payments ADD COLUMN transaction_type TEXT NOT NULL DEFAULT 'payment'");
        // If someone manually inserted negative payments before this feature existed,
        // preserve the intended meaning as a principal increase and store a positive amount.
        $db->exec("UPDATE payments SET transaction_type='principal_increase', amount=ABS(amount) WHERE amount < 0");
    },
];
