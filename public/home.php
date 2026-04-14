<?php
require_once '../config/config.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? './';

if ($path === '/ai') {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    $query        = $input['query'] ?? 'Tell me about x402 micropayments on Stellar';
    $chosen_asset = strtoupper($input['asset'] ?? $default_asset);
    $amount       = $input['amount'] ?? $default_amount;

    $payment_proof = $_SERVER['HTTP_PAYMENT_SIGNATURE'] ?? $_SERVER['HTTP_X_STELLAR_PAYMENT'] ?? null;

    if ($payment_proof && verifyPayment($payment_proof, $chosen_asset, $amount)) {
        $answer = callOpenAI($query);
        http_response_code(200);
        echo json_encode([
            'answer'      => $answer,
            'asset'       => $chosen_asset,
            'verified_by' => 'facilitator_or_fallback'
        ]);
    } else {
        http_response_code(402);
        header('Content-Type: application/json');
        header('X-Payment-Network: stellar:testnet');

        $payment_required = [
            'scheme'      => 'exact',
            'price'       => $amount,
            'asset'       => $chosen_asset === 'XLM' ? 'native' : $chosen_asset,
            'payTo'       => $service_public_key,
            'facilitator' => $facilitator_url,
            'network'     => 'stellar:testnet',
            'description' => "AI query micropayment ({$chosen_asset})"
        ];

        header('PAYMENT-REQUIRED: ' . base64_encode(json_encode($payment_required)));

        echo json_encode([
            'message'          => 'Payment required via Stellar x402',
            'asset'            => $chosen_asset,
            'amount'           => $amount,
            'destination'      => $service_public_key,
            'facilitator'      => $facilitator_url,
            'supported_assets' => ['XLM', 'USDC']
        ]);
    }
    exit;
}

// ====================== Demo Page (vanilla JS + CDNs) ======================
if ($path === './' || $path === './index.php') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>x402 AI Micropayment API – XLM or USDC</title>
        
        <!-- Stellar SDK CDN -->
        <script src="./dist/js/stellar-sdk.min.js"></script>
        
        <!-- Freighter API CDN (working version) -->
        <script src="./dist/js/index.min.js"></script>
        
        <style>
            body { font-family: system-ui, sans-serif; max-width: 820px; margin: 40px auto; padding: 20px; line-height: 1.6; }
            button { padding: 14px 24px; font-size: 16px; margin: 5px; border: none; border-radius: 8px; cursor: pointer; }
            .primary { background: #0a84ff; color: white; }
            .secondary { background: #666; color: white; }
            #result { margin-top: 25px; padding: 20px; background: #f8f9fa; border-radius: 8px; white-space: pre-wrap; }
            select, input { padding: 10px; margin: 8px 0; width: 100%; font-size: 16px; box-sizing: border-box; }
        </style>
    </head>
    <body>
        <h1>x402-Protected AI Micropayment API</h1>
        <p>Choose asset → Ask AI → 402 → Pay (Freighter or Laboratory) → Verify → Get AI response</p>

        <select id="asset">
            <option value="USDC">USDC (stable value)</option>
            <option value="XLM">XLM (native)</option>
        </select>
        <input id="amount" type="text" value="0.05" placeholder="Amount e.g. 0.05">
        <input id="query" value="How do AI agents benefit from x402 micropayments on Stellar using USDC?" style="width:100%; padding:12px;">

        <button class="primary" onclick="askAI()">Ask AI Agent (triggers x402)</button>
        <button class="secondary" onclick="payWithFreighter()" id="freighterBtn" style="display:none;">Pay with Freighter Wallet</button>

        <div id="result"></div>

        <script>
            let freighterAvailable = false;

            // Detect Freighter
            setTimeout(() => {
                if (typeof window.freighterApi !== 'undefined') {
                    freighterAvailable = true;
                    document.getElementById('freighterBtn').style.display = 'inline-block';
                }
            }, 600);

            async function askAI() {
                const asset = document.getElementById('asset').value;
                const amount = document.getElementById('amount').value;
                const query = document.getElementById('query').value;
                const resultDiv = document.getElementById('result');
                resultDiv.innerHTML = '🤖 Sending request...';

                const res = await fetch('/ai', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ query, asset, amount })
                });

                if (res.status === 402) {
                    const data = await res.json();
                    resultDiv.innerHTML = `
                        <strong>402 Payment Required</strong><br><br>
                        Asset: ${data.asset} • Amount: ${data.amount}<br>
                        Destination: ${data.destination}<br><br>
                        <strong>Pay options:</strong><br>
                        1. Click "Pay with Freighter" (if button visible)<br>
                        2. Or use Stellar Laboratory → send payment → paste tx hash below<br><br>
                        <input id="txhash" placeholder="Paste transaction hash here" style="width:100%; padding:10px; margin:10px 0;">
                        <button onclick="retryWithPayment()">Retry with Payment Proof</button>
                    `;
                    return;
                }

                const data = await res.json();
                resultDiv.innerHTML = `<strong>✅ AI Answer (${data.asset}):</strong><br>${data.answer || 'No response'}`;
            }

            async function retryWithPayment() {
                const txHash = document.getElementById('txhash').value.trim();
                if (!txHash) return alert('Paste the transaction hash first');

                const asset = document.getElementById('asset').value;
                const amount = document.getElementById('amount').value;
                const query = document.getElementById('query').value;
                const resultDiv = document.getElementById('result');
                resultDiv.innerHTML = '🔄 Verifying with facilitator + fallback...';

                const res = await fetch('/ai', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'PAYMENT-SIGNATURE': txHash 
                    },
                    body: JSON.stringify({ query, asset, amount })
                });

                const data = await res.json();
                resultDiv.innerHTML = res.ok 
                    ? `<strong>✅ Verified! AI Answer:</strong><br>${data.answer}` 
                    : `Verification failed: ${data.message || 'Try again'}`;
            }

            async function payWithFreighter() {
                if (!freighterAvailable) {
                    return alert('Freighter wallet not detected. Install the extension and refresh.');
                }
                alert('Freighter detected!\n\nApprove the payment in the Freighter popup.\nAfter successful payment, copy the transaction hash and paste it above.');
                try {
                    await window.freighterApi.getPublicKey(); // simple connection test
                } catch (e) {
                    alert('Freighter error: ' + (e.message || e));
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

echo '404 Not Found';

// ====================== Helper Functions ======================
function verifyPayment($paymentProof, $asset, $amount) {
    global $facilitator_url, $horizon_url, $service_public_key, $usdc_issuer;

    $verifyPayload = [
        'x402Version' => 1,
        'paymentHeader' => base64_encode(json_encode(['signature' => $paymentProof])),
        'paymentRequirements' => [
            'scheme' => 'exact',
            'network' => 'stellar:testnet',
            'payTo' => $service_public_key,
            'asset' => $asset === 'XLM' ? 'native' : $asset,
            'price' => $amount,
            'description' => 'AI micropayment'
        ],
        'paymentPayload' => [
            'to' => $service_public_key,
            'amount' => $amount,
            'asset' => $asset === 'XLM' ? 'native' : $asset,
            'signature' => $paymentProof
        ]
    ];

    // Try facilitator first
    $ch = curl_init($facilitator_url . '/verify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verifyPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if (isset($result['valid']) && $result['valid'] === true) {
            return true;
        }
    }

    // Fallback to Horizon
    return fallbackHorizonCheck($paymentProof, $asset, $amount);
}

function fallbackHorizonCheck($txHash, $asset, $amount) {
    global $horizon_url, $service_public_key, $usdc_issuer;

    $url = $horizon_url . "/transactions/" . urlencode($txHash) . "/payments";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    foreach ($data['_embedded']['records'] ?? [] as $op) {
        $match = false;
        if ($asset === 'XLM' && $op['asset_type'] === 'native') {
            $match = ($op['to'] === $service_public_key && (float)$op['amount'] == (float)$amount);
        } elseif ($asset === 'USDC' && $op['asset_code'] === 'USDC') {
            $match = ($op['to'] === $service_public_key && (float)$op['amount'] == (float)$amount);
        }
        if ($match) return true;
    }
    return false;
}

function callOpenAI($query) {
    global $openai_api_key;
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openai_api_key
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [['role' => 'user', 'content' => $query]],
        'max_tokens' => 300
    ]));
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? 'AI service error.';
}