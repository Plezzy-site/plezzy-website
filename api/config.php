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

// Timezone
date_default_timezone_set('Asia/Kolkata');
