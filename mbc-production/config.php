<?php
declare(strict_types=1);

const APP_NAME = 'Mindoro State University Bongabong Campus Production and Business Operation Record Management System';
const APP_SHORT_NAME = 'MinSU Bongabong Campus';
const APP_CAMPUS_NAME = 'Mindoro State University Bongabong Campus';
const APP_SYSTEM_NAME = 'Production and Business Operation Record Management System';
const APP_LOGO = 'assets/images/logo.png';
const APP_ROOT = __DIR__;
const APP_PUBLIC = __DIR__;

// Default database connection. Deployment can override these through DB_* environment variables.
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'u317918921_bpo_system';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

// Session configuration
const SESSION_IDLE_TIMEOUT_SECONDS = 1800; // 30 minutes

// Login rate limiting configuration
const LOGIN_WINDOW_MINUTES = 15; // Time window to count failed login attempts
const LOGIN_LOCKOUT_MINUTES = 15; // Duration to lock account after failed attempts
const LOGIN_MAX_ATTEMPTS = 3; // Max failed attempts per account before lockout
const LOGIN_MAX_IP_ATTEMPTS = 20; // Max failed attempts per IP before lockout
