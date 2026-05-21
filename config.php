<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Kreait\Firebase\Factory;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Firestore\FirestoreClient; // Added to bypass Kreait's strict wrapper

/*
|--------------------------------------------------------------------------
| ENV LOADER
|--------------------------------------------------------------------------
*/
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

define('MAPS_API_KEY', $_ENV['GOOGLE_MAPS_API_KEY'] ?? '');
define('FIREBASE_AUTH_DOMAIN', $_ENV['FIREBASE_AUTH_DOMAIN'] ?? '');
define('FIREBASE_PROJECT_ID', $_ENV['FIREBASE_PROJECT_ID'] ?? '');
define('FIREBASE_STORAGE_BUCKET', $_ENV['FIREBASE_STORAGE_BUCKET'] ?? '');
define('FIREBASE_MESSAGING_SENDER_ID', $_ENV['FIREBASE_MESSAGING_SENDER_ID'] ?? '');
define('FIREBASE_APP_ID', $_ENV['FIREBASE_APP_ID'] ?? '');

/*
|--------------------------------------------------------------------------
| FIREBASE SERVICE ACCOUNT (LOCALHOST + RENDER SUPPORT)
|--------------------------------------------------------------------------
*/
$serviceAccount = null;

// 1. Try to read from Render's Environment Variable first
if (!empty($_ENV['FIREBASE_SERVICE_ACCOUNT'])) {
    $serviceAccount = json_decode($_ENV['FIREBASE_SERVICE_ACCOUNT'], true);
} 
// 2. If running locally on XAMPP, fall back to the physical file
elseif (file_exists(__DIR__ . '/service-account.json')) {
    $serviceAccount = json_decode(file_get_contents(__DIR__ . '/service-account.json'), true);
}

if (!$serviceAccount) {
    error_log("Firebase service account is missing or invalid.");
    http_response_code(500);
    exit("Server configuration error: Missing Service Account JSON.");
}

/*
|--------------------------------------------------------------------------
| FIREBASE INITIALIZATION
|--------------------------------------------------------------------------
*/
$factory = (new Factory)->withServiceAccount($serviceAccount);

$messaging = $factory->createMessaging();

// THE MAGIC BYPASS: We instantiate Firestore manually to force REST mode
$firestore = new FirestoreClient([
    'projectId' => $serviceAccount['project_id'] ?? ($_ENV['FIREBASE_PROJECT_ID'] ?? ''),
    'keyFile'   => $serviceAccount,
    'transport' => 'rest' // <--- This permanently disables the gRPC C++ requirement!
]);

/*
|--------------------------------------------------------------------------
| GOOGLE CLOUD STORAGE
|--------------------------------------------------------------------------
*/
$storage = new StorageClient([
    'keyFile' => $serviceAccount,
    'projectId' => $serviceAccount['project_id'] ?? ($_ENV['FIREBASE_PROJECT_ID'] ?? '')
]);

$bucketName = $_ENV['FIREBASE_STORAGE_BUCKET'] ?? '';
$bucket = $storage->bucket($bucketName);

/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/
function generateCustomId($type, $prefix, $firestore)
{
    $counterRef = $firestore->database()
        ->collection('Counters')
        ->document($type);

    $snapshot = $counterRef->snapshot();

    $last = $snapshot->exists() ? ($snapshot['last_number'] ?? 0) : 0;
    $next = $last + 1;

    $counterRef->set([
        'last_number' => $next
    ]);

    return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
}