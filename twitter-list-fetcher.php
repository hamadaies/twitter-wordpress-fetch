<?php
/**
 * Plugin Name: Twitter User Timeline Fetcher
 * Description: Fetches recent tweets from your Twitter account and creates WordPress posts.
 * Version: 1.0
 * Author: Fadex 
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define Twitter API Credentials
define('TWITTER_BEARER_TOKEN', '');
define('TWITTER_USER_ID', ''); // Replace with your Twitter User ID

add_action('init', 'schedule_twitter_fetch');

function schedule_twitter_fetch() {
    if (!wp_next_scheduled('fetch_twitter_tweets_daily')) {
        wp_schedule_event(time(), 'daily', 'fetch_twitter_tweets_daily');
    }
}

add_action('fetch_twitter_tweets_daily', 'fetch_twitter_tweets');

function fetch_twitter_tweets($return_errors = false) {
    $now = new DateTime("now", new DateTimeZone("UTC"));
    $yesterday = clone $now;
    $yesterday->modify("-24 hours");

    $start_time = $yesterday->format(DateTime::ATOM); // ISO 8601 format

    $url = "https://api.twitter.com/2/users/" . TWITTER_USER_ID . "/tweets?tweet.fields=created_at,text,id&start_time=" . urlencode($start_time) . "&max_results=10"; 

    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . TWITTER_BEARER_TOKEN,
            'User-Agent'    => 'v2UserTweetsFetcher'
        )
    );

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        $error_msg = 'Twitter API Request Failed: ' . $response->get_error_message();
        error_log($error_msg);
        return $return_errors ? $error_msg : false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!isset($data['data']) || empty($data['data'])) {
        $error_msg = 'No tweets found in the last 24 hours.';
        error_log($error_msg);
        return $return_errors ? $error_msg : false;
    }

    foreach ($data['data'] as $tweet) {
        $tweet_id = $tweet['id'];
        $tweet_text = $tweet['text'];
        $tweet_date = date("Y-m-d H:i:s", strtotime($tweet['created_at']));

        if (!tweet_exists($tweet_id)) {
            $post_data = array(
                'post_title'   => 'Tweet on ' . date("M d, Y", strtotime($tweet['created_at'])),
                'post_content' => esc_html($tweet_text),
                'post_status'  => 'publish',
                'post_author'  => 1,
                'post_date'    => $tweet_date,
                'post_type'    => 'post',
                'meta_input'   => array('tweet_id' => $tweet_id)
            );

            wp_insert_post($post_data);
        }
    }

    return true; // Success
}
// Function to check if tweet already exists
function tweet_exists($tweet_id) {
    global $wpdb;
    $query = $wpdb->prepare("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = 'tweet_id' AND meta_value = %s", $tweet_id);
    return $wpdb->get_var($query) > 0;
}

// Add Admin Menu for Settings Page
add_action('admin_menu', 'twitter_fetcher_add_admin_menu');

function twitter_fetcher_add_admin_menu() {
    add_options_page('Twitter Fetcher', 'Twitter Fetcher', 'manage_options', 'twitter_fetcher', 'twitter_fetcher_settings_page');
}

// Admin Settings Page
function twitter_fetcher_settings_page() {
    ?>
    <div class="wrap">
        <h1>Twitter Recent Tweets Fetcher</h1>
        <p>Click the button below to manually fetch tweets from the last 24 hours and add them as blog posts.</p>
        <form method="post">
            <input type="hidden" name="fetch_tweets_now" value="1">
            <?php submit_button('Fetch Tweets Now'); ?>
        </form>
    </div>
    <?php
}

// Handle Button Click
add_action('admin_init', 'handle_manual_tweet_fetch');

function handle_manual_tweet_fetch() {
    if (isset($_POST['fetch_tweets_now']) && current_user_can('manage_options')) {
        $result = fetch_twitter_tweets(true); // Pass true to enable error return

        if ($result !== true) {
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-error is-dismissible"><p>Error fetching tweets: ' . esc_html($result) . '</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Fetched latest tweets successfully!</p></div>';
            });
        }
    }
}
?>
