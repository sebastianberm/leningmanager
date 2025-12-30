<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/dotenv.php';

final class TestDotenv extends TestCase {
    public function testLoadDotenvCreatesEnvVars() {
        $tmp = sys_get_temp_dir() . '/test_env_' . uniqid() . '.env';
        $content = "TEST_VAR1=hello\nTEST_VAR2=\"world\"\n";
        file_put_contents($tmp, $content);
        $this->assertTrue(load_dotenv($tmp));
        $this->assertEquals('hello', getenv('TEST_VAR1'));
        $this->assertEquals('world', getenv('TEST_VAR2'));
        unlink($tmp);
    }
}
