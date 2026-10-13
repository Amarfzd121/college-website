<?php
/**
 * Vercel Serverless Entry Point & Front Controller
 * Routes traffic to appropriate project endpoints on Vercel
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$path = '/' . ltrim($path, '/');

// 1. Admin Routes
if (strpos($path, '/admin') === 0) {
    if (strpos($path, 'login') !== false) {
        require __DIR__ . '/../admin/login.php';
    } elseif (strpos($path, 'logout') !== false) {
        require __DIR__ . '/../admin/logout.php';
    } else {
        require __DIR__ . '/../admin/dashboard.php';
    }
    exit;
}

// 2. Fee Payment Portal Route
if (strpos($path, '/payment') === 0) {
    require __DIR__ . '/../payment.php';
    exit;
}

// 3. Main College Website (Default Route)
require __DIR__ . '/../index.php';
