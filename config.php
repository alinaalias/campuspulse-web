<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeload();

define('MAPS_API_KEY', $_ENV['GOOGLE_MAPS_API_KEY'] ?? '');
define('FIREBASE_AUTH_DOMAIN', $_ENV['FIREBASE_AUTH_DOMAIN'] ?? '');
define('FIREBASE_PROJECT_ID', $_ENV['FIREBASE_PROJECT_ID'] ?? '');
define('FIREBASE_STORAGE_BUCKET', $_ENV['FIREBASE_STORAGE_BUCKET'] ?? '');
define('FIREBASE_MESSAGING_SENDER_ID', $_ENV['FIREBASE_MESSAGING_SENDER_ID'] ?? '');
define('FIREBASE_APP_ID', $_ENV['FIREBASE_APP_ID'] ?? '');

use Kreait\Firebase\Factory;
use Google\Cloud\Storage\StorageClient;

$serviceAccountPath = __DIR__ . '/service-account.json';

if (!file_exists($serviceAccountPath)) {
    die("Error: Key file not found at: " . $serviceAccountPath);
}

$factory = (new Factory)->withServiceAccount($serviceAccountPath);
$firestore = $factory->createFirestore();
$messaging = $factory->createMessaging();

$storage = new StorageClient([
    'keyFilePath' => $serviceAccountPath,
    'projectId' => 'campuspulse-bfd09',
]);

$bucketName = 'campuspulse-bfd09.firebasestorage.app';
$bucket = $storage->bucket($bucketName);

function generateCustomId($type, $prefix, $firestore)
{
    $counterRef = $firestore->database()->collection('Counters')->document($type);
    $snapshot = $counterRef->snapshot();

    $last = $snapshot->exists() ? $snapshot['last_number'] : 0;
    $next = $last + 1;

    $counterRef->set(['last_number' => $next]);

    return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
}
?>