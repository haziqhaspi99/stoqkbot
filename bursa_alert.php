<?php

$botToken = getenv('TELEGRAM_BOT_TOKEN');
$chatId   = getenv('TELEGRAM_CHAT_ID');
$dropThreshold = -10.0;

// Use Bursa Malaysia 4-digit stock codes
$watchlist = [
    '4707' => 'NESTLE',
    '1155' => 'MAYBANK',
    '5347' => 'TENAGA',
    '7084' => 'QL',
    '3689' => 'F&N',
];

$logFile = __DIR__ . '/alerted_log.json';
$alertedLog = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
$currentMonth = date('Y-m');

foreach ($watchlist as $code => $name) {
    $data = fetchBursaData($code);
    if (!$data) {
        echo "Failed to fetch data for {$name} ({$code})\n";
        continue;
    }

    $prevClose = $data['prev_close'];
    $current   = $data['current_price'];
    $pctChange = (($current - $prevClose) / $prevClose) * 100;
    $pctFmt    = number_format($pctChange, 2);

    echo "{$name} ({$code}): Prev RM {$prevClose} | Now RM {$current} ({$pctFmt}%)\n";

    $alertKey = "{$code}_{$currentMonth}";

    if ($pctChange <= $dropThreshold && !isset($alertedLog[$alertKey])) {
        $msg = "🚨 *BURSA DROP ALERT (>10%)* 🚨\n\n"
             . "*Stock:* {$name} ({$code})\n"
             . "*Prev Month Close:* RM " . number_format($prevClose, 2) . "\n"
             . "*Current Price:* RM " . number_format($current, 2) . "\n"
             . "*Monthly Change:* *{$pctFmt}%*";

        if (sendTelegramAlert($botToken, $chatId, $msg)) {
            $alertedLog[$alertKey] = date('Y-m-d H:i:s');
            file_put_contents($logFile, json_encode($alertedLog, JSON_PRETTY_PRINT));
        }
    }
    sleep(1);
}

function fetchBursaData(string $code): ?array {
    // 1. Primary source: Stooq monthly CSV (works reliably on GitHub Actions)
    $startDate = date('Ym01', strtotime('-2 months'));
    $endDate   = date('Ymd');
    $stooqUrl  = "https://stooq.com{$code}.my&d1={$startDate}&d2={$endDate}&i=m";

    $csv = httpGet($stooqUrl);
    if ($csv && strpos($csv, 'Date,Open,High,Low,Close') !== false) {
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        if (count($lines) >= 3) {
            $prevRow = str_getcsv($lines[count($lines) - 2]);
            $currRow = str_getcsv($lines[count($lines) - 1]);
            if (isset($prevRow[4], $currRow[4]) && is_numeric($prevRow[4]) && is_numeric($currRow[4])) {
                return [
                    'prev_close'    => (float) $prevRow[4],
                    'current_price' => (float) $currRow[4],
                ];
            }
        }
    }

    // 2. Fallback source: Yahoo Finance query2
    $yahooUrl = "https://yahoo.com{$code}.KL?range=3mo&interval=1mo";
    $res = httpGet($yahooUrl);
    if ($res) {
        $json = json_decode($res, true);
        $closes = $json['chart']['result'][0]['indicators']['quote'][0]['close'] ?? [];
        $closes = array_values(array_filter($closes, fn($v) => $v !== null));
        if (count($closes) >= 2) {
            return [
                'prev_close'    => (float) $closes[count($closes) - 2],
                'current_price' => (float) $closes[count($closes) - 1],
            ];
        }
    }

    return null;
}

function httpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($res && $httpCode === 200) ? $res : null;
}

function sendTelegramAlert(string $token, string $chatId, string $text): bool {
    $ch = curl_init("https://telegram.org{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true)['ok'] ?? false;
}
