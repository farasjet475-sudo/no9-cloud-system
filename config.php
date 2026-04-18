<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// App configuration
const APP_NAME = 'No9 Cloud System';
const APP_VERSION = '3.0.0';
const BASE_URL = '';
const DB_HOST = 'localhost';
const DB_NAME = 'no9_cloud_system_v5';
const DB_USER = 'root';
const DB_PASS = '';
const APP_ENV = 'production'; // change to local during development
const INSTALL_LOCK = true;    // set false only during first installation if you build your own installer

const UPLOAD_PRODUCTS = __DIR__ . '/../uploads/products/';
const UPLOAD_PAYMENTS = __DIR__ . '/../uploads/payments/';
const LOGS_PATH = __DIR__ . '/../logs/';

date_default_timezone_set('Africa/Mogadishu');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db(): mysqli {
    static $db = null;
    if (!$db) {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
    }
    return $db;
}
