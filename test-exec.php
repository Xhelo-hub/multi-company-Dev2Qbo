<?php
// Test if exec works
echo "Testing exec() function...\n\n";

$phpBinary = PHP_BINARY;
echo "PHP Binary: $phpBinary\n";

$scriptPath = __DIR__ . '/execute-sync-job.php';
echo "Script path: $scriptPath\n";
echo "Script exists: " . (file_exists($scriptPath) ? 'YES' : 'NO') . "\n\n";

// Test 1: Simple echo command
echo "Test 1: Simple command\n";
exec("echo 'Hello from exec'", $output1, $return1);
echo "Output: " . implode("\n", $output1) . "\n";
echo "Return code: $return1\n\n";

// Test 2: PHP version check
echo "Test 2: PHP version\n";
exec("$phpBinary --version", $output2, $return2);
echo "Output: " . implode("\n", $output2) . "\n";
echo "Return code: $return2\n\n";

// Test 3: Check disabled functions
echo "Test 3: Disabled functions\n";
$disabled = ini_get('disable_functions');
echo "Disabled functions: " . ($disabled ?: 'NONE') . "\n\n";

// Test 4: Try spawning background process
echo "Test 4: Background process (creating test file)\n";
$testFile = __DIR__ . '/test-bg.txt';
$cmd = "$phpBinary -r 'sleep(2); file_put_contents(\"$testFile\", \"Background process works!\");' > /dev/null 2>&1 &";
echo "Command: $cmd\n";
exec($cmd);
echo "Background command executed. Check $testFile in a few seconds.\n";
