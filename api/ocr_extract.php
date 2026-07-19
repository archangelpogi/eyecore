<?php
/**
 * ocr_extract.php — Google Cloud Vision OCR + improved parsing
 * Requires: config/api_keys.php with define('GOOGLE_VISION_API_KEY', '...')
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// TEMPORARY - i-on muna natin para makita ang totoong error (i-off pagkatapos ma-debug)
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

$base64   = $input['base64'] ?? '';
$mimeType = $input['mimeType'] ?? 'image/jpeg';
$docName  = $input['docName'] ?? 'document';

$emptyResult = ['owner_name' => null, 'release_date' => null, 'expiry_date' => null];
$debugInfo = [];

if (!$base64) {
    echo json_encode($emptyResult);
    exit;
}

require_once __DIR__ . '/../config/api_keys.php';

$apiKey = defined('GOOGLE_VISION_API_KEY') ? GOOGLE_VISION_API_KEY : null;
if (!$apiKey) {
    error_log('GOOGLE_VISION_API_KEY is not set (check config/api_keys.php)');
    echo json_encode($emptyResult);
    exit;
}

if ($mimeType === 'application/pdf') {
    $debugInfo['note'] = 'PDF direct OCR not supported in this simple version. Mag-upload ng JPG/PNG na litrato ng document.';
    $emptyResult['_debug'] = $debugInfo;
    echo json_encode($emptyResult);
    exit;
}

$payload = [
    'requests' => [
        [
            'image' => ['content' => $base64],
            'features' => [
                ['type' => 'DOCUMENT_TEXT_DETECTION'],
            ],
        ],
    ],
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($apiKey));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

$result = $emptyResult;

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    $text = $data['responses'][0]['fullTextAnnotation']['text'] ?? '';

    if (!$text) {
        $debugInfo['note'] = 'Walang na-detect na text sa image (baka malabo ang litrato).';
    } else {
        $result = extractFieldsFromText($text, $docName);
        $debugInfo['raw_text_preview'] = substr($text, 0, 500);
    }
} else {
    error_log("Google Vision API error ({$httpCode}) for {$docName}: {$curlErr} | {$response}");
    $debugInfo['http_code'] = $httpCode;
    $debugInfo['curl_error'] = $curlErr;
    $debugInfo['api_response'] = $response;
}

error_log("OCR Result for {$docName}: " . json_encode($result));

if (!empty($debugInfo)) {
    $result['_debug'] = $debugInfo;
}

echo json_encode($result);


function extractFieldsFromText(string $text, string $docName): array
{
    $result = [
        'owner_name'   => null,
        'release_date' => null,
        'expiry_date'  => null,
    ];

    $result['owner_name'] = extractOwnerName($text);

    // ── DATES ───────────────────────────────────────────────────
    $months = 'January|February|March|April|May|June|July|August|September|October|November|December|' .
              'Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';

    // Isang "buong petsa" fragment na magagamit natin paulit-ulit --
    // kasama dito ang optional comma sa loob mismo ng petsa
    // (hal. "January 05, 2026"), para hindi ito ma-cut ng ibang pattern.
    $dateFragment = '(?:(?:' . $months . ')\.?\s+\d{1,2},?\s+\d{4}'
        . '|\d{1,2}\s+(?:' . $months . ')\.?,?\s+\d{4}'
        . '|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}'
        . '|\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})';

    $datePatterns = [
        '/(' . $dateFragment . ')/i',
    ];

    $allDates = [];
    foreach ($datePatterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $raw = $match[0];
                $pos = $match[1];
                $norm = normalizeDate($raw);
                if ($norm) {
                    $allDates[] = ['raw' => $raw, 'pos' => $pos, 'normalized' => $norm];
                }
            }
        }
    }

    $releaseDate = null;
    $expiryDate  = null;
    $releaseKeywords = '/(?:date\s*issued|issued\s*on|released|date\s*of\s*issue|release\s*date)/i';
    $expiryKeywords  = '/(?:valid\s*until|valid\s*thru|valid\s*up\s*to|expiry|expires|expiration)/i';

    foreach ($allDates as $d) {
        $contextStart = max(0, $d['pos'] - 60);
        $context = substr($text, $contextStart, $d['pos'] - $contextStart);

        if (!$releaseDate && preg_match($releaseKeywords, $context)) {
            $releaseDate = $d['normalized'];
        } elseif (!$expiryDate && preg_match($expiryKeywords, $context)) {
            $expiryDate = $d['normalized'];
        }
    }

    // "is valid from X to Y" style -- gamitin ang parehong $dateFragment
    // dito para hindi mahati ang petsa sa maling comma.
    if ((!$releaseDate || !$expiryDate) && preg_match(
        '/valid\s+from\s+(' . $dateFragment . ')\s+to\s+(' . $dateFragment . ')/i',
        $text,
        $vm
    )) {
        $fromNorm = normalizeDate(trim($vm[1]));
        $toNorm   = normalizeDate(trim($vm[2]));
        if (!$releaseDate && $fromNorm) $releaseDate = $fromNorm;
        if (!$expiryDate && $toNorm)    $expiryDate  = $toNorm;
    }

    if (!$releaseDate && !empty($allDates)) {
        $releaseDate = $allDates[0]['normalized'];
    }
    if (!$expiryDate && count($allDates) > 1) {
        foreach ($allDates as $d) {
            if ($d['normalized'] !== $releaseDate) {
                $expiryDate = $d['normalized'];
                break;
            }
        }
    }
    if ($releaseDate && !$expiryDate) {
        $dt = DateTime::createFromFormat('Y-m-d', $releaseDate);
        if ($dt) {
            $dt->modify('+1 year');
            $expiryDate = $dt->format('Y-m-d');
        }
    }

    $result['release_date'] = $releaseDate;
    $result['expiry_date']  = $expiryDate;

    return $result;
}

function extractOwnerName(string $text): ?string
{
    $lines = preg_split('/\r\n|\r|\n/', $text);

    $labelPatterns = [
        '/name\s+of\s+(?:applicant|owner|proprietor)/i',
        '/licensee\s+name/i',
        '/licensed\s+practitioner/i',
        '/policyholder/i',
        '/owner\s*\/?\s*proprietor/i',
        '/owner\s*name/i',
        '/issued\s+to\s+the\s+owner\s*\/?\s*proprietor/i',
        '/certificate\s+(?:is\s+)?issued\s+to/i',
        '/this\s+certificate\s+(?:is\s+)?issued\s+to/i',
        '/registered\s+under/i',
        '/record\s+of/i',
        '/issued\s+to/i',
        '/this\s+is\s+to\s+certify\s+that/i',
        '/this\s+certifies\s+that/i',
    ];

    foreach ($labelPatterns as $pattern) {
        foreach ($lines as $lineIndex => $line) {
            if (!preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $colInLine = $m[0][1] + strlen($m[0][0]);

            $sameLineRemainder = substr($line, $colInLine);
            $sameLineRemainder = trim(preg_replace('/^[\s:\-–—]+/', '', $sameLineRemainder));

            $candidate = cleanNameCandidate($sameLineRemainder);
            if ($candidate) {
                return $candidate;
            }

            for ($i = $lineIndex + 1; $i < count($lines) && $i <= $lineIndex + 4; $i++) {
                $next = trim($lines[$i]);
                if ($next === '') {
                    continue;
                }
                $candidate = cleanNameCandidate($next);
                if ($candidate) {
                    return $candidate;
                }
            }
        }
    }

    return null;
}

function cleanNameCandidate(string $candidate): ?string
{
    $candidate = preg_replace('/\s+(is\s+a|is\s+hereby|has\s+been|,).*/i', '', $candidate);
    $candidate = trim(preg_replace('/\s+/', ' ', $candidate));
    $candidate = trim($candidate, " \t\n\r\0\x0B.:-–—");

    if ($candidate === '') {
        return null;
    }
    if (strlen($candidate) < 3 || strlen($candidate) > 60) {
        return null;
    }
    if (!preg_match('/^[A-Za-zÀ-ÿ\'\.\-\s]+$/', $candidate)) {
        return null;
    }
    $rejectWords = '/^(Barangay|City|Municipality|Province|Republic|Philippines|'
        . 'Office|Department|Sample|Certificate|This|Address|Business)\b/i';
    if (preg_match($rejectWords, $candidate)) {
        return null;
    }

    $roleStopWords = ['the', 'a', 'an', 'of', 'to', 'taxpayer', 'applicant',
        'licensee', 'licensed', 'practitioner', 'owner', 'proprietor',
        'policyholder', 'policy', 'holder', 'registrant', 'member',
        'record', 'sample', 'name'];
    $words = array_filter(explode(' ', strtolower($candidate)), 'strlen');
    $nonStopWords = array_filter($words, fn($w) => !in_array($w, $roleStopWords, true));
    if (empty($nonStopWords)) {
        return null;
    }

    return $candidate;
}

function normalizeDate(string $raw): ?string
{
    $raw = trim($raw);
    $currentYear = (int) date('Y');
    $minYear = $currentYear - 15;
    $maxYear = $currentYear + 15;

    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $raw, $m)) {
        $y = (int)$m[1];
        if ($y < $minYear || $y > $maxYear) return null;
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $raw, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2]; $y = (int)$m[3];
        if ($y < $minYear || $y > $maxYear) return null;
        if ($a > 12) {
            if ($a > 31 || $b > 12) return null;
            return sprintf('%04d-%02d-%02d', $y, $b, $a);
        }
        if ($a > 12 || $b > 31) return null;
        return sprintf('%04d-%02d-%02d', $y, $a, $b);
    }
    $ts = strtotime($raw);
    if ($ts !== false) {
        $y = (int) date('Y', $ts);
        if ($y < $minYear || $y > $maxYear) return null;
        return date('Y-m-d', $ts);
    }
    return null;
}