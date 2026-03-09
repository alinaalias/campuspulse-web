<?php
session_start();

// Destroy all session data to log the admin out
session_unset();
session_destroy();

// Redirect to login page
header('Location: index.php');
exit();
?>
