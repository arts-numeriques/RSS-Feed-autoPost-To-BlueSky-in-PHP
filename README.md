# RSS-Feed-autoPost-To-BlueSky-in-PHP
RSStoBlueSky - RSS Feed autoPost To BlueSky in PHP (single-file)

🚀 **RSStoBlueSky-PHP** is a simple PHP script that automatically fetches an RSS feed and posts new entries to [Bluesky](https://bsky.app). 
This script uses the Bluesky API to create posts with rich text and website previews.

## ✨ Features
- 📡 Fetches latest articles from an **RSS feed**.
- 🔗 Posts clickable **links** to Bluesky.
- 🖼️ Includes **website previews (rich embeds)** for better visibility.
- 🛠️ Runs as a **single PHP file**, making it easy to set up.
- 📝 **Logs activity** to track posts and errors.

---

## 🚀 Installation & Setup

### 1️⃣ Requirements
- PHP **7.4+** with `cURL` and `DOMDocument` enabled.
- A **Bluesky account** with an [App Password](https://bsky.app/settings/app-passwords).
- A publicly accessible **RSS feed URL**.

### 2️⃣ Configuration
Edit the script and replace the following placeholders with your **own values**:

$handle = "YOUR_BLUESKY_HANDLE"; // Your Bluesky username
$password = "YOUR_APP_PASSWORD"; // Your Bluesky App Password
$feedUrl = "YOUR_RSS_FEED_URL";  // Your RSS feed URL

### 3️⃣ Running the Script

You can execute the script manually using:

php script.php

Or set up a cron job for automatic posting:

*/30 * * * * /usr/bin/php /path/to/script.php

This runs the script every 30 minutes.

### 🛠️ Error Handling & Logs

Errors are not displayed publicly but logged in errors.log.
Script logs are saved in:
feedToBlueSky.log (main log)
feedToBlueSky-cron.log (cron job log)

### 🔐 Security

Do not share your App Password.
Add errors.log and published_links.json to .gitignore before publishing.

