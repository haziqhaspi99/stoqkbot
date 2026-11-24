<?php

// 1. CONFIGURATION 
$botToken = getenv('TELEGRAM_BOT_TOKEN');
$chatId   = getenv('TELEGRAM_CHAT_ID');

$dropThreshold = -10.0; // Trigger when drop is more than 10% (i.e., <= -10.0%)

// List of Bursa Malaysia stock codes to monitor
$watchlist = [
    '4707.KL' => 'NESTLE',
    '1155.KL' => 'MAYBANK',
    '5347.KL' => 'TENAGA',
    '7084.KL' => 'QL',
    '3689.KL' => 'F&N',
];

$logFile = __DIR__ . '/alerted_log.json';
$alertedLog = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
$currentMonth = date('Y-m');

// 2. LOOP THROUGH WATCHLIST
foreach ($watchlist as $symbol => $name) {
    $data = fetchBursaData($symbol);
    
    if (!$data) {
        echo "Failed to fetch data for {$name} ({$symbol})\n";
        continue;
    }

    $prevMonthClose = $data['prev_close'];
    $currentPrice   = $data['current_price'];

    // Calculate percentage change from previous month's close
    $pctChange = (($currentPrice - $prevMonthClose) / $prevMonthClose) * 100;
    $pctFormatted = number_format($pctChange, 2);

    echo "{$name}: Prev Month RM {$prevMonthClose} | Now RM {$currentPrice} ({$pctFormatted}%)\n";

    // 3. CHECK TRIGGER & PREVENT DUPLICATE ALERTS IN THE SAME MONTH
    $alertKey = "{$symbol}_{$currentMonth}";

    if ($pctChange <= $dropThreshold && !isset($alertedLog[$alertKey])) {
        $message = "🚨 *BURSA DROP ALERT (>10%)* 🚨\n\n"
                 . "*Stock:* {$name} ({$symbol})\n"
                 . "*Prev Month Close:* RM " . number_format($prevMonthClose, 2) . "\n"
                 . "*Current Price:* RM " . number_format($currentPrice, 2) . "\n"
                 . "*Monthly Change:* *{$pctFormatted}%*\n"
                 . "*Action:* Check fundamentals for 1-lot entry.";

        if (sendTelegramAlert($botToken, $chatId, $message)) {
            $alertedLog[$alertKey] = date('Y-m-d H:i:s');
            file_put_contents($logFile, json_encode($alertedLog, JSON_PRETTY_PRINT));
        }
    }

    // Pause 1 second between requests to avoid rate limiting
    sleep(1);
}

// --- HELPER FUNCTIONS ---

function fetchBursaData(string $symbol): ?array {
    // Fetches 3 months of monthly interval candles
    $url = "https://yahoo.com{$symbol}?range=3mo&interval=1mo";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        // User-Agent is required so Yahoo Finance doesn't block the request
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PHP-Bursa-Monitor/1.0',
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return null;

    $json = json_decode($response, true);
    $result = $json['chart']['result'][0] ?? null;

    if (!$result) return null;

    $closes = $result['indicators']['quote'][0]['close'] ?? [];
    // Filter out null values
    $closes = array_values(array_filter($closes, fn($v) => $v !== null));

    $count = count($closes);
    if ($count < 2) return null;

    return [
        // Second-to-last candle is the previous completed month's close
        'prev_close'    => (float) $closes[$count - 2],
        // Last candle is the live/latest price of the current month
        'current_price' => (float) $closes[$count - 1],
    ];
}

function sendTelegramAlert(string $token, string $chatId, string $text): bool {
    $url = "https://telegram.org{$token}/sendMessage";
    $postData = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($res, true);
    return $decoded['ok'] ?? false;
}
