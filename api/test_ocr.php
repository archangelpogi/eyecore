<?php
// test_ocr.php - Fixed version

header('Content-Type: text/html');

echo "<h2>Testing OCR.Space API (Fixed)</h2>";

// Create a simple test image with text (using GD)
$img = imagecreate(400, 100);
$bg = imagecolorallocate($img, 255, 255, 255);
$textColor = imagecolorallocate($img, 0, 0, 0);
imagestring($img, 5, 10, 40, "Name: Juan Dela Cruz", $textColor);
imagestring($img, 5, 10, 60, "Date Issued: 01/15/2024", $textColor);
imagestring($img, 5, 10, 80, "Expiry Date: 01/15/2025", $textColor);

$tmpFile = tempnam(sys_get_temp_dir(), 'test_') . '.jpg';
imagejpeg($img, $tmpFile);
imagedestroy($img);

echo "<p>Test image created: " . $tmpFile . "</p>";

// Call OCR.Space API with proper file type
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.ocr.space/parse/image');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'apikey' => 'helloworld',
    'language' => 'eng',
    'isOverlayRequired' => 'false',
    'OCREngine' => '2',
    'filetype' => 'JPG',  // ✅ Explicit file type
    'file' => new CURLFile($tmpFile, 'image/jpeg', 'test.jpg')
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

@unlink($tmpFile);

echo "<p>HTTP Code: " . $httpCode . "</p>";

if ($response) {
    $data = json_decode($response, true);
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    
    if (isset($data['ParsedResults'][0]['ParsedText'])) {
        echo "<h3>Extracted Text:</h3>";
        echo "<textarea rows='10' cols='80'>" . htmlspecialchars($data['ParsedResults'][0]['ParsedText']) . "</textarea>";
    } else {
        echo "<p style='color:red'>Error: " . implode(', ', $data['ErrorMessage'] ?? ['Unknown error']) . "</p>";
    }
}
?>