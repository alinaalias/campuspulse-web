<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Kreait\Firebase\Factory;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Firestore\FirestoreClient;

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
| FIREBASE SERVICE ACCOUNT (PHYSICAL FILE UNIFICATION)
|--------------------------------------------------------------------------
| We use a physical service-account.json file locally AND on Render
*/
$keyFilePath = __DIR__ . '/service-account.json';

if (!file_exists($keyFilePath)) {
    error_log("Firebase service account is missing.");
    http_response_code(500);
    exit("Server configuration error: Missing service-account.json file.");
}

// THIS IS THE SILVER BULLET
// It forces every Google Cloud package to use this exact credential file automatically!
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $keyFilePath);

/*
|--------------------------------------------------------------------------
| FIREBASE INITIALIZATION
|--------------------------------------------------------------------------
*/
$factory = (new Factory)->withServiceAccount($keyFilePath);
$messaging = $factory->createMessaging();

/*
|--------------------------------------------------------------------------
| FIRESTORE (REST TRANSPORT BYPASS)
|--------------------------------------------------------------------------
*/
$firestore = new FirestoreClient([
    'projectId' => $_ENV['FIREBASE_PROJECT_ID'] ?? '',
    'transport' => 'rest' // Permanently disables the gRPC hardware requirement!
]);

/*
|--------------------------------------------------------------------------
| GOOGLE CLOUD STORAGE
|--------------------------------------------------------------------------
*/
$storage = new StorageClient([
    'projectId' => $_ENV['FIREBASE_PROJECT_ID'] ?? ''
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