<?php
// Debug phone validation
require_once 'config/config.php';

echo "<h1>Phone Validation Debug</h1>";
echo "<style>body{font-family:Arial,sans-serif;padding:20px;} .success{color:green;} .error{color:red;} .info{color:blue;}</style>";

// Test different phone inputs
$test_phones = [
    '+63 9171234567',
    '+639171234567',
    '9171234567',
    '09171234567',
    '639171234567'
];

echo "<h2>Testing Phone Validation Logic</h2>";

foreach ($test_phones as $phone) {
    echo "<h3>Input: '$phone'</h3>";

    // Step 1: Trim
    $trimmed = trim($phone);
    echo "After trim: '$trimmed'<br>";

    // Step 2: Extract digits only (this is what register.php does)
    $phoneDigits = preg_replace('/\D/', '', $trimmed);
    echo "Digits only: '$phoneDigits'<br>";

    // Step 3: Check against regex (corrected version)
    $valid = preg_match('/^639\d{9}$/', $phoneDigits);
    echo "Regex /^639\\d{9}$/: " . ($valid ? "<span class='success'>✓ VALID</span>" : "<span class='error'>✗ INVALID</span>") . "<br>";

    // Step 4: Show what the old (wrong) regex would do
    $old_valid = preg_match('/^639\d{10}$/', $phoneDigits);
    echo "Old regex /^639\\d{10}$/: " . ($old_valid ? "<span class='success'>✓ VALID</span>" : "<span class='error'>✗ INVALID</span>") . "<br>";

    echo "<hr>";
}
