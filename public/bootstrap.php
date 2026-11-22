<?php
require_once __DIR__ . '/../app/config/session_cache.php';

if (!isset($GLOBALS['session_cache_file']) || !($GLOBALS['session_cache_file'] instanceof SessionCacheFile)) {
    $GLOBALS['session_cache_file'] = new SessionCacheFile();
}

