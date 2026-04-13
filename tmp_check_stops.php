<?php
require 'c:\xampp\htdocs\FYP\config.php';
$stops = $firestore->database()->collection('Stops')->limit(1)->documents();
foreach ($stops as $s) {
    print_r($s->data());
}
