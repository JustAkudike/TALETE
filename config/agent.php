<?php
// agent.php - Headless agent simulation (run with: php agent.php)

require_once 'config.php';

$query = 'Explain x402 payments with USDC on Stellar in one short paragraph';
$asset = 'USDC';
$amount = '0.05';

echo " Agent starting request...\n";

// First request - expect 402
$ch = curl_init('http://localhost:8000/ai');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query, 'asset' => $asset, 'amount' => $amount]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 402) {
    echo "Unexpected response code: $code\n";
    exit;
}

echo " Received 402 Payment Required.\n";
echo " Please send {$amount} {$asset} to {$service_public_key}\n";
echo "   (Use Stellar Laboratory or Freighter)\n\n";
echo "After payment, copy the transaction hash and run:\n";
echo "   php agent.php <tx_hash>\n";
exit;