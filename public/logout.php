<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

start_session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
