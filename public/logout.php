<?php
require_once 'error_handler.php';
session_start();
session_unset();
session_destroy();
header("Location: login.php");
exit;
?>