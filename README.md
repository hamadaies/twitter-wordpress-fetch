# twitter-wordpress-fetch

Twitter Auto Poster – WordPress Plugin

Fetches your latest tweets (from the past 24 hours) and publishes them as WordPress blog posts automatically.

Features
• Fetches tweets from the last 24 hours to avoid API limits
• Automatic daily fetch via WP-Cron (configurable)
• Manual fetch button in the settings page
• Prevents duplicate tweets from being posted
• Displays errors in WordPress admin if fetching fails

Installation 1. Download or clone this repository:

git clone https://github.com/hamadaies/twitter-auto-poster.git

    2.	Move the folder to your LocalWP installation:

/wp-content/plugins/twitter-auto-poster

    3.	Go to WordPress Admin → Plugins and activate the plugin
    4.	Navigate to Settings → Twitter Fetcher and enter your API credentials
    5.	Click Save Changes

Setup Twitter API 1. Go to Twitter Developer Portal 2. Create a new app and get your Bearer Token 3. Copy and paste the token into the plugin settings

Usage

Automatic Fetch
• Runs once a day using WP-Cron
• Fetches only tweets from the last 24 hours

Manual Fetch
• Go to Settings → Twitter Fetcher
• Click Fetch Tweets Now
• Errors (if any) will be shown in WordPress

Configuration

Option Description
Twitter User ID The ID of your Twitter account
Bearer Token Required for API authentication
Max Tweets Per Fetch Default: 10, limits tweets per request
Enable Debug Mode Logs API errors for troubleshooting
