<?php
/**
 * Test script to verify date extraction from DevPos responses
 */

// Sample DevPos response structure based on actual API
$sampleInvoice = [
    'invoiceCreatedDate' => '2025-05-21T14:33:57+02:00',
    'documentNumber' => 'INV-12345',
    'totalAmount' => 1500.00,
    'dueDate' => '2025-06-21T00:00:00+02:00'
];

echo "=== Testing Date Extraction ===\n\n";

echo "Sample DevPos Invoice:\n";
echo json_encode($sampleInvoice, JSON_PRETTY_PRINT) . "\n\n";

// Test extraction logic from transformers
$issueDate = $sampleInvoice['invoiceCreatedDate'] 
    ?? $sampleInvoice['dateTimeCreated']
    ?? $sampleInvoice['createdDate']
    ?? $sampleInvoice['issueDate']
    ?? null;

echo "Extracted issueDate: " . ($issueDate ?? 'NULL') . "\n";

if ($issueDate) {
    $formattedDate = substr($issueDate, 0, 10);
    echo "Formatted date (substr 0,10): $formattedDate\n";
    echo "Expected format: YYYY-MM-DD\n";
    echo "Match: " . ($formattedDate === '2025-05-21' ? 'YES ✓' : 'NO ✗') . "\n\n";
}

// Test due date
$dueDate = $sampleInvoice['dueDate'] ?? $issueDate;
echo "Extracted dueDate: $dueDate\n";
$formattedDueDate = substr($dueDate, 0, 10);
echo "Formatted due date: $formattedDueDate\n";
echo "Expected: 2025-06-21\n";
echo "Match: " . ($formattedDueDate === '2025-06-21' ? 'YES ✓' : 'NO ✗') . "\n\n";

// Test what happens if field is missing
echo "=== Testing Missing Date Field ===\n\n";
$invoiceNoDate = [
    'documentNumber' => 'INV-99999',
    'totalAmount' => 500.00
];

$issueDate2 = $invoiceNoDate['invoiceCreatedDate'] 
    ?? $invoiceNoDate['dateTimeCreated']
    ?? null;

if (!$issueDate2) {
    echo "No date found - would fall back to today: " . date('Y-m-d') . "\n";
    echo "This is the problem if DevPos doesn't return invoiceCreatedDate!\n\n";
}

echo "=== Checking Available Fields in Sample ===\n";
echo "Available keys: " . implode(', ', array_keys($sampleInvoice)) . "\n\n";

echo "✓ Test complete\n";
