<?php
/**
 * Simple log viewer to check PDF attachment logs
 */

// Security: Basic authentication (change these credentials!)
$validUser = 'admin';
$validPass = 'changeme123';

if (!isset($_SERVER['PHP_AUTH_USER']) || 
    $_SERVER['PHP_AUTH_USER'] !== $validUser || 
    $_SERVER['PHP_AUTH_PW'] !== $validPass) {
    header('WWW-Authenticate: Basic realm="Log Viewer"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Access denied';
    exit;
}

$lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 200;
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'pdf|PDF|attachment|upload|Checking|DevPos detail';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Application Logs</title>
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
        .controls {
            background: #252526;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .controls label {
            color: #9cdcfe;
            margin-right: 10px;
        }
        .controls input, .controls select {
            background: #3c3c3c;
            color: #d4d4d4;
            border: 1px solid #555;
            padding: 5px 10px;
            margin-right: 15px;
        }
        .controls button {
            background: #0e639c;
            color: white;
            border: none;
            padding: 8px 20px;
            cursor: pointer;
            border-radius: 3px;
        }
        .controls button:hover {
            background: #1177bb;
        }
        pre {
            background: #252526;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            line-height: 1.5;
        }
        .error {
            color: #f48771;
        }
        .success {
            color: #4ec9b0;
        }
        .warning {
            color: #dcdcaa;
        }
        .info {
            color: #9cdcfe;
        }
        .highlight {
            background: #3a3d41;
        }
    </style>
</head>
<body>
    <h1>📋 Application Logs</h1>
    
    <div class="controls">
        <form method="get">
            <label>Lines:</label>
            <input type="number" name="lines" value="<?= htmlspecialchars($lines) ?>" min="10" max="1000">
            
            <label>Filter (regex):</label>
            <input type="text" name="filter" value="<?= htmlspecialchars($filter) ?>" size="40">
            
            <label>Log File:</label>
            <select name="logfile">
                <option value="php-fpm" <?= !isset($_GET['logfile']) || $_GET['logfile'] === 'php-fpm' ? 'selected' : '' ?>>PHP-FPM Log (Default)</option>
                <option value="apache" <?= isset($_GET['logfile']) && $_GET['logfile'] === 'apache' ? 'selected' : '' ?>>Apache Error Log</option>
                <option value="syslog" <?= isset($_GET['logfile']) && $_GET['logfile'] === 'syslog' ? 'selected' : '' ?>>System Log</option>
            </select>
            
            <button type="submit">🔍 Refresh</button>
            <button type="button" onclick="location.href='?lines=<?= $lines ?>&filter=<?= urlencode($filter) ?>'">🔄 Auto-Refresh (5s)</button>
        </form>
    </div>

    <?php
    // Determine log source
    $logSource = isset($_GET['logfile']) ? $_GET['logfile'] : 'php-fpm';
    
    echo "<h2>Log Source: ";
    switch ($logSource) {
        case 'php-fpm':
            echo "PHP-FPM (via journalctl)";
            break;
        case 'apache':
            echo "Apache Error Log";
            break;
        case 'syslog':
            echo "System Log";
            break;
    }
    echo "</h2>";
    echo "<p>Last updated: " . date('Y-m-d H:i:s') . "</p>";

    // Build command based on log source
    $command = '';
    
    switch ($logSource) {
        case 'php-fpm':
            // Use journalctl for PHP-FPM logs (no permission issues)
            $command = "journalctl -u php8.3-fpm -n $lines --no-pager";
            break;
            
        case 'apache':
            $logFile = '/var/log/apache2/error.log';
            if (file_exists($logFile) && is_readable($logFile)) {
                $command = "tail -n $lines " . escapeshellarg($logFile);
            }
            break;
            
        case 'syslog':
            $logFile = '/var/log/syslog';
            if (file_exists($logFile) && is_readable($logFile)) {
                $command = "tail -n $lines " . escapeshellarg($logFile);
            }
            break;
    }
    
    // Apply filter if specified
    if (!empty($command) && !empty($filter)) {
        $command .= " | grep -E " . escapeshellarg($filter);
    }
    
    // Execute command
    if (empty($command)) {
        echo "<pre class='error'>Unable to read logs - insufficient permissions or log not found</pre>";
    } else {
        $output = shell_exec($command . " 2>&1");
        
        if (empty($output)) {
            echo "<pre class='warning'>No log entries found (filter may be too restrictive or no logs yet)</pre>";
            echo "<p>Try removing the filter or check if any sync has been run recently.</p>";
        } else {
            // Colorize output
            $outputLines = explode("\n", $output);
            echo "<pre>";
            foreach ($outputLines as $line) {
                if (empty(trim($line))) continue;
                
                $class = '';
                if (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
                    $class = 'error';
                } elseif (stripos($line, 'success') !== false || stripos($line, '✓') !== false) {
                    $class = 'success';
                } elseif (stripos($line, 'warning') !== false) {
                    $class = 'warning';
                } elseif (stripos($line, 'pdf') !== false || stripos($line, 'PDF') !== false) {
                    $class = 'highlight info';
                }
                
                echo "<span class='$class'>" . htmlspecialchars($line) . "</span>\n";
            }
            echo "</pre>";
        }
    }
    ?>

    <script>
        // Auto-refresh every 5 seconds if button clicked
        let autoRefresh = false;
        document.querySelector('button[type="button"]').addEventListener('click', function(e) {
            e.preventDefault();
            if (!autoRefresh) {
                autoRefresh = true;
                this.textContent = '⏸️ Stop Auto-Refresh';
                setInterval(() => location.reload(), 5000);
            }
        });
    </script>
</body>
</html>
