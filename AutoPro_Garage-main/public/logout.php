<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_post();            // logout is a state change: POST + CSRF only
AuthService::logout();
session_start();
flash('success', 'You have been signed out.');
redirect('login.php');
