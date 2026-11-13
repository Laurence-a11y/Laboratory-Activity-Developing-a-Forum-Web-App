<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

// Credentials come from environment variables; never commit real values.
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_NAME') ?: 'nested_forum';
$user = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

if ($user === false || $password === false) {
    throw new RuntimeException('Database credentials not configured (set DB_USER and DB_PASSWORD).');
}

$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'mysql',          // Eloquent talks to MySQL through PDO (pdo_mysql)
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $user,
    'password' => $password,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'timezone' => '+00:00',       // read and write TIMESTAMPs in UTC
    'strict' => true,
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

return $capsule;
