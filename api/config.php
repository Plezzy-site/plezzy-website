<?php
/**
 * PLEZZY Database & Hostinger Environment Configuration
 * 
 * Instructions for Hostinger hPanel:
 * 1. Go to Hostinger hPanel -> Databases -> MySQL Databases
 * 2. Create a Database & User (e.g., u123456789_plezzy & u123456789_user)
 * 3. Update the credentials below with your Hostinger database details.
 */

// Hostinger MySQL Server (Usually 'localhost' or '127.0.0.1' on Hostinger)
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
}

// Hostinger Database Name (from your phpMyAdmin: u364814416_Plezzy)
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'u364814416_Plezzy');
}

// Hostinger Database Username
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'u364814416_Plezzydata');
}

// Hostinger Database Password
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') ?: 'Plezzy@123');
}

// MySQL Port (Default 3306)
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('DB_PORT') ?: 3306);
}

// ==============================================================================
// Hostinger SMTP & Email Configuration
// Hostinger Webmail/Titan Mail SMTP settings:
// Host: smtp.hostinger.com | Port: 465 (SSL) or 587 (TLS)
// ==============================================================================
if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.hostinger.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', getenv('SMTP_PORT') ?: 465);
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'ssl');
if (!defined('SMTP_USER')) define('SMTP_USER', getenv('SMTP_USER') ?: '');
if (!defined('SMTP_PASS')) define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'support@plezzy.in');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'PLEZZY VIP');

// Timezone
date_default_timezone_set('Asia/Kolkata');
