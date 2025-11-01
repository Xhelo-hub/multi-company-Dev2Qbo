<?php
/**
 * View application debug logs
 */

// Security
$validUser = 'admin';
$validPass = 'changeme123';

if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] !== $validUser || 
    $_SERVER['PHP_AUTH_PW'] !== $validPass) {
    header('WWW-Authenticate: Basic realm="Debug Log"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Access denied';
    exit;
}

$logFile = __DIR__ . '/../storage/pdf-debug.log';
$lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;

?>
<!DOCTYPE html>
<html>
<head>
    <title>PDF Debug Log</title>
    <meta http-equiv="refresh" content="5">
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            margin: 0;
        }
        h1 {
            color: #4ec9b0;
            border-bottom: 2px solid #4ec9b0;
            padding-bottom: 10px;
        }
        .info {
            background: #252526;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            color: #9cdcfe;
        }
        pre {
            background: #252526;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .error { color: #f48771; }
        .success { color: #4ec9b0; }
        .warning { color: #dcdcaa; }
        .highlight { background: #3a3d41; }
        .timestamp { color: #858585; }
        .controls {
            background: #252526;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .controls a {
            color: #9cdcfe;
            text-decoration: none;
            margin-right: 20px;
            padding: 5px 10px;
            background: #0e639c;
            border-radius: 3px;
        }
        .controls a:hover {
            background: #1177bb;
        }
    </style>
</head>
<body>
    <h1>📋 PDF Debug Log</h1>
    
    <div class="info">
        <strong>Log file:</strong> <?= htmlspecialchars($logFile) ?><br>
        <strong>Auto-refresh:</strong> Every 5 seconds<br>
        <strong>Last updated:</strong> <?= date('Y-m-d H:i:s') ?>
    </div>

    <div class="controls">
        <a href="?lines=50">Last 50 lines</a>
        <a href="?lines=100">Last 100 lines</a>
        <a href="?lines=500">Last 500 lines</a>
        <a href="?clear=1" onclick="return confirm('Clear all logs?')">🗑️ Clear Log</a>
    </div>

    <?php
    // Handle clear request
    if (isset($_GET['clear'])) {
        file_put_contents($logFile, '');
        echo "<div class='info'>✓ Log cleared</div>";
    }

    // Check if log file exists
    if (!file_exists($logFile)) {
        echo "<pre class='warning'>Log file does not exist yet.\nRun a sync to generate logs.\n\nExpected location: $logFile</pre>";
    } else {
        // Read log file
        $content = file_get_contents($logFile);
        
        if (empty($content)) {
            echo "<pre class='warning'>Log file is empty.\nRun a sync to generate logs.</pre>";
        } else {
            // Get last N lines
            $allLines = explode("\n", $content);
            $recentLines = array_slice($allLines, -$lines);
            
            echo "<pre>";
            foreach ($recentLines as $line) {
                if (empty(trim($line))) continue;
                
                $class = '';
                if (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
                    $class = 'error';
                } elseif (stripos($line, 'success') !== false || stripos($line, '✓') !== false) {
                    $class = 'success';
                } elseif (stripos($line, 'warning') !== false) {
                    $class = 'warning';
                } elseif (stripos($line, 'pdf') !== false || stripos($line, 'PDF') !== false) {
                    $class = 'highlight';
                }
                
                // Highlight timestamp
                $line = preg_replace('/^\[([^\]]+)\]/', '<span class="timestamp">[$1]</span>', $line);
                
                echo "<span class='$class'>" . $line . "</span>\n";
            }
            echo "</pre>";
            
            echo "<div class='info'>Showing last " . count($recentLines) . " lines</div>";
        }
    }
    ?>
</body>
</html>
