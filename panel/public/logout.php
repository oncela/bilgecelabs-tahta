<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
session_begin();
if (!empty($_SESSION['admin_id'])) audit('logout');
$_SESSION = [];
session_destroy();
header('Location: login.php');
