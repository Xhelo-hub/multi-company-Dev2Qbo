<?php
/**
 * Simple debug log writer for PDF attachments
 * Writes to app directory instead of system logs
 */

$logFile = __DIR__ . '/../storage/pdf-debug.log';
$logDir = dirname($logFile);

// Create storage directory if it doesn't exist
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Function to write to our custom log
function debugLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}

debugLog("PDF Debug Logger Initialized");
