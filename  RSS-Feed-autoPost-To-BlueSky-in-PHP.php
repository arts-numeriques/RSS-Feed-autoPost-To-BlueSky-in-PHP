<?php

/**
 * RSStoBlueSky-PHP - RSS Feed autoPost To BlueSky in PHP (in one file)
 * 
 * This script automatically fetches an RSS feed and posts new entries to Bluesky.
 *
 * @author artsnumeriques
 * @license MIT
 * @year 2024
 * @version 1.0
 */

date_default_timezone_set('Europe/Paris'); // Set the default timezone

ini_set('display_errors', 0); // Don't display errors publicly
ini_set('display_startup_errors', 0); // Don't display startup errors
error_reporting(E_ALL); // Keep logging all errors

ini_set('log_errors', 1); // Enable error logging
ini_set('error_log', 'errors.log'); // Log errors to a file named "errors.log"

// Replace with your Bluesky handle
$handle = "YOUR_BLUESKY_HANDLE";
// Replace with your App Password
$password = "YOUR_APP_PASSWORD";
// Replace with the URL of your RSS feed
$feedUrl = "YOUR_RSS_FEED_URL";

// Log files and tracking files (you can rename them as needed)
$logFile = "feedToBlueSky.log";
$cronLogFile = "feedToBlueSky-cron.log";
$publishedFile = "published_links.json";  // File to store published links

// Function to write logs and display messages
function writeLog($file, $message, $display = true) {
    $logMessage = date('Y-m-d H:i:s') . " - " . $message . "\n";
    file_put_contents($file, $logMessage, FILE_APPEND);
    if ($display) {
        echo $logMessage;
        flush();
        ob_flush();
    }
}

// Write the cron job log
writeLog($cronLogFile, "Cron Job executed.");

// Load the list of already published links from the JSON file
$publishedLinks = [];
if (file_exists($publishedFile)) {
    $jsonData = file_get_contents($publishedFile);
    $publishedLinks = json_decode($jsonData, true);
    if (!is_array($publishedLinks)) {
        $publishedLinks = [];
    }
}

// Create a stream context with a custom User-Agent to bypass blocking
$opts = [
  "http" => [
    "method" => "GET",
    "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36\r\n"
  ]
];
$context = stream_context_create($opts);

// Check access to the RSS feed
$feedContent = file_get_contents($feedUrl, false, $context);
if (!$feedContent) {
    writeLog($logFile, "❌ Error: Unable to retrieve the RSS feed.");
    die("❌ Error: Unable to retrieve the RSS feed.");
} else {
    writeLog($logFile, "✅ RSS feed retrieved (first 200 characters): " . substr($feedContent, 0, 200));
}

// Validate XML
libxml_use_internal_errors(true);
$feed = simplexml_load_string($feedContent);
if (!$feed) {
    $errors = libxml_get_errors();
    foreach ($errors as $error) {
        writeLog($logFile, "❌ XML Error: " . trim($error->message));
    }
    libxml_clear_errors();
    die("❌ Error: Malformed RSS feed.");
}

// Retrieve the latest article from the RSS feed
if (isset($feed->channel->item[0])) {
    $latestItem = $feed->channel->item[0];
    $title = (string)$latestItem->title;
    $link = (string)$latestItem->link;
    $postText = $title . "\n\n" . $link . "\n";
    writeLog($logFile, "Latest article extracted: " . $title);
    
    // Check if this link has already been published
    if (in_array($link, $publishedLinks)) {
        writeLog($logFile, "ℹ️ This link has already been published. Exiting script.");
        die("ℹ️ This link has already been published.");
    }
    
} else {
    writeLog($logFile, "❌ No data retrieved from the RSS feed.");
    die("❌ No data retrieved from the RSS feed.");
}

// Connect to Bluesky
$ch = curl_init("https://bsky.social/xrpc/com.atproto.server.createSession");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "identifier" => $handle,
    "password"   => $password
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!isset($response["accessJwt"])) {
    writeLog($logFile, "❌ Bluesky authentication failed. API Response: " . json_encode($response, JSON_PRETTY_PRINT));
    die("❌ Bluesky authentication failed.");
}
$accessToken = $response["accessJwt"];
writeLog($logFile, "✅ Successfully authenticated on Bluesky.");

///////////////////

/**
 * Fetch embed data for a URL (Website Card preview).
 * Returns an array formatted for an external embed.
 */
function fetchEmbedData($url, $accessToken) {
    // Initialize embed with default values
    $embed = [
        '$type'    => 'app.bsky.embed.external',
        'external' => [
            'uri'         => $url,
            'title'       => '',
            'description' => '',
        ],
    ];
    
    // Retrieve the HTML content of the URL
    $html = @file_get_contents($url);
    if ($html === false) {
        return $embed;
    }
    
    // Load the HTML and parse meta tags
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($doc);
    
    // Extract og:title and og:description
    $titleNodes = $xpath->query('//meta[@property="og:title"]');
    if ($titleNodes->length > 0) {
        $embed['external']['title'] = $titleNodes->item(0)->getAttribute('content');
    }
    $descNodes = $xpath->query('//meta[@property="og:description"]');
    if ($descNodes->length > 0) {
        $embed['external']['description'] = $descNodes->item(0)->getAttribute('content');
    }
    
    // Extract the image (og:image) and upload if present
    $imageNodes = $xpath->query('//meta[@property="og:image"]');
    if ($imageNodes->length > 0) {
        $imgUrl = $imageNodes->item(0)->getAttribute('content');
        // If the URL is relative, attempt to make it absolute
        if (strpos($imgUrl, '://') === false) {
            $imgUrl = rtrim($url, '/') . '/' . ltrim($imgUrl, '/');
        }
        $imgData = @file_get_contents($imgUrl);
        if ($imgData !== false) {
            // Determine the MIME type of the image
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($imgData);
            
            // Upload the image via the Bluesky API
            $ch = curl_init("https://bsky.social/xrpc/com.atproto.repo.uploadBlob");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: " . $mimeType,
                "Authorization: Bearer $accessToken"
            ]);
            $uploadResponse = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (isset($uploadResponse["blob"])) {
                // Assign the blob ref directly as expected by the API
                $embed['external']['thumb'] = $uploadResponse["blob"];
            }
        }
    }
    
    return $embed;
}

// Fetch embed data for the link (Website Card preview)
$embedData = fetchEmbedData($link, $accessToken);
// If the title is empty, use the link as a fallback
if (empty($embedData['external']['title'])) {
    $embedData['external']['title'] = $link;
}

// Calculate the position of the link in the text for rich-text facets
$linkStart = strlen($title . "\n\n");
$linkEnd = $linkStart + strlen($link);

// Create facets array to make the link clickable
$facets = [
    [
        "index" => [
            "byteStart" => $linkStart,
            "byteEnd"   => $linkEnd
        ],
        "features" => [
            [
                '$type' => "app.bsky.richtext.facet#link",
                "uri"   => $link
            ]
        ]
    ]
];

// Publish on Bluesky with the text, facets, and embed
writeLog($logFile, "Post sent to Bluesky: " . json_encode($postText, JSON_PRETTY_PRINT));
$ch = curl_init("https://bsky.social/xrpc/com.atproto.repo.createRecord");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);

$payload = [
    "repo"       => $handle,
    "collection" => "app.bsky.feed.post",
    "record"     => [
        "text"      => $postText,
        "facets"    => $facets,
        "embed"     => $embedData,
        "createdAt" => date("c"),
    ]
];

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $accessToken"
]);

$rawResponse = curl_exec($ch);
$response = json_decode($rawResponse, true);
curl_close($ch);

if (isset($response["uri"])) {
    writeLog($logFile, "✅ Successfully published on Bluesky: " . $response["uri"]);
    
    // Add the link to the list of published links and save to the JSON file
    $publishedLinks[] = $link;
    file_put_contents($publishedFile, json_encode($publishedLinks, JSON_PRETTY_PRINT));
    
} else {
    writeLog($logFile, "❌ Error publishing on Bluesky. API Response: " . $rawResponse);
    die("❌ Error publishing on Bluesky.");
}

////////////////

// Final test: Retrieve HTTP header via cURL (for debugging purposes)
$ch = curl_init($feedUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true); // Retrieve only headers
curl_setopt($ch, CURLOPT_VERBOSE, true); // Debug mode
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP Code received: $httpCode\n";
?>
