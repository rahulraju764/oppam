<?php
// The FAQ is now a section of package.php — plans and the questions about them
// live on one page. This file stays only so old links/bookmarks don't 404.
require_once __DIR__ . '/assets/includes/auth.php';
// Absolute, and extensionless — a Location: must be a full URL to be
// unambiguous, and /package.php would only be 301'd again by .htaccess.
header('Location: ' . site_url('package') . '#faq', true, 301);
exit;
