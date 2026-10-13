<?php
define('DB_HOST','localhost'); define('DB_NAME','college_website'); define('DB_USER','root'); define('DB_PASS','');
if(session_status()===PHP_SESSION_NONE)session_start();
function db(){static $pdo=null;if(!$pdo)$pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);return $pdo;}
