<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/migrations.php';

final class TestMigrations extends TestCase {
    public function testListMigrationFilesReturnsArray() {
        $files = list_migration_files();
        $this->assertIsArray($files);
    }
}
