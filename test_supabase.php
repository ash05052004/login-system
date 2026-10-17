<?php
require_once 'config.php';

header('Content-Type: text/plain; charset=utf-8');

$supabase_url = rtrim(env('SUPABASE_URL', ''), '/');
$supabase_key = env('SUPABASE_SERVICE_KEY', '');

echo "SUPABASE_URL: " . ($supabase_url === '' ? '(EMPTY)' : $supabase_url) . "\n";
echo "SUPABASE_SERVICE_KEY length: " . strlen($supabase_key) . "\n";
echo "Key prefix: " . substr($supabase_key, 0, 12) . "...\n\n";

if ($supabase_url === '' || $supabase_key === '') {
    exit("Missing env vars.\n");
}

// Test 1: list buckets
echo "=== TEST 1: List buckets ===\n";
$ch = curl_init($supabase_url . '/storage/v1/bucket');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $supabase_key,
        'apikey: ' . $supabase_key,
    ],
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP $code\n$res\n\n";

// Test 2: upload a small test file
echo "=== TEST 2: Upload test.png ===\n";
$test_filename = 'test_' . time() . '.png';
// 1x1 transparent PNG
$png_data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

$ch = curl_init($supabase_url . '/storage/v1/object/receipts/' . $test_filename);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $png_data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $supabase_key,
        'apikey: ' . $supabase_key,
        'Content-Type: image/png',
        'x-upsert: true',
    ],
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP $code\n$res\n\n";

if ($code >= 200 && $code < 300) {
    echo "=== SUCCESS ===\n";
    echo "Public URL: $supabase_url/storage/v1/object/public/receipts/$test_filename\n";
} else {
    echo "=== FAILED ===\n";
    echo "See error above.\n";
}