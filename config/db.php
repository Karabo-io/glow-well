<?php

    function load_env(string $path): array {
        $vars = [];
        if (!is_readable($path)) {
            return $vars;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $vars[trim($key)] = trim(trim($value), "\"'");
        }
        return $vars;
    }

    $env = load_env(__DIR__ . '/../.env');

    define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
    define('DB_NAME', $env['DB_NAME'] ?? 'glowwell');
    define('DB_USER', $env['DB_USER'] ?? 'root');
    define('DB_PASS', $env['DB_PASS'] ?? '');

    define('BASE_URL', rtrim($env['BASE_URL'] ?? '/glowwell', '/'));

    // Length of one booking time-slot step, in minutes.
    define('SLOT_STEP', 30);

    
    function db(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            try {
                $pdo = new PDO(
                    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
                //echo "Connection successful.";
                
            } catch (PDOException $e) {
                error_log('DB connection failed: ' . $e->getMessage());
                http_response_code(500);
                exit('The system could not connect to the database. Please check config/db.php and that MySQL is running.');
            }
        }
        return $pdo;
    }
?>