<?php
// Minimal .env loader - reads KEY=VALUE lines and sets env vars
function load_dotenv($path = null) {
    if ($path === null) {
        $path = __DIR__ . '/../.env';
    }
    if (!file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        // support KEY=VALUE and export KEY=VALUE
        if (strpos($line, 'export ') === 0) $line = substr($line, 7);
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        // Remove surrounding quotes if present
        if ((strlen($value) >= 2) && (($value[0] === '"' && $value[strlen($value)-1] === '"') || ($value[0] === "'" && $value[strlen($value)-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        // replace escaped \n with actual newline
        $value = str_replace('\\n', "\n", $value);
        // set env
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
    return true;
}

?>
