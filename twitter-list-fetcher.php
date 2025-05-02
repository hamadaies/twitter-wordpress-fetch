<?php
/**
 * Plugin Name: Twitter User Timeline Fetcher
 * Description: Fetches recent tweets from your Twitter account and creates WordPress posts.
 * Version: 1.4
 * Author: Fadex 
 */

if (!defined('ABSPATH')) exit;

class Twitter_Fetcher {
    private $options;
    private $option_name = 'twitter_fetcher_options';
    private $rate_limit_option = 'twitter_fetcher_rate_limits';

    public function __construct() {
        $this->options = get_option($this->option_name, array(
            'bearer_token' => '',
            'user_id' => '',
        ));

        add_action('init', array($this, 'schedule_twitter_fetch'));
        add_action('fetch_twitter_tweets_daily', array($this, 'fetch_twitter_tweets'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_manual_tweet_fetch'));
    }

    public function schedule_twitter_fetch() {
        if (!wp_next_scheduled('fetch_twitter_tweets_daily')) {
            wp_schedule_event(time(), 'daily', 'fetch_twitter_tweets_daily');
        }
    }

    public function fetch_twitter_tweets($return_errors = false) {
        // Check rate limits before making the request
        $rate_limits = get_option($this->rate_limit_option, array());
        
        if (!empty($rate_limits) && isset($rate_limits['remaining']) && $rate_limits['remaining'] <= 0) {
            // Check if reset time has passed
            $current_time = time();
            if (isset($rate_limits['reset']) && $rate_limits['reset'] > $current_time) {
                $wait_time = $rate_limits['reset'] - $current_time;
                $error_msg = sprintf('Rate limit reached. Please wait %d seconds or until %s before making another request.', 
                    $wait_time, 
                    date('H:i:s', $rate_limits['reset'])
                );
                error_log($error_msg);
                return $return_errors ? $error_msg : false;
            }
        }

        if (empty($this->options['bearer_token']) || empty($this->options['user_id'])) {
            $error_msg = 'Twitter API credentials are not configured.';
            error_log($error_msg);
            return $return_errors ? $error_msg : false;
        }

        $now = new DateTime("now", new DateTimeZone("UTC"));
        $yesterday = clone $now;
        $yesterday->modify("-24 hours");
        $start_time = $yesterday->format('Y-m-d\TH:i:s\Z');

        $url = add_query_arg(
            array(
                'tweet.fields' => 'created_at,text,referenced_tweets,attachments',
                'expansions' => 'referenced_tweets.id,attachments.media_keys,referenced_tweets.id.attachments.media_keys',
                'media.fields' => 'url,preview_image_url,type,variants',
                'start_time' => $start_time,
                'max_results' => '10'
            ),
            'https://api.twitter.com/2/users/' . esc_attr($this->options['user_id']) . '/tweets'
        );

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->options['bearer_token'],
                'User-Agent'    => 'v2UserTweetsFetcher'
            ),
            'timeout' => 15
        );

        error_log('Making Twitter API request to: ' . esc_url($url));
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $error_msg = 'Twitter API Request Failed: ' . $response->get_error_message();
            error_log($error_msg);
            return $return_errors ? $error_msg : false;
        }

        // Store rate limit information from headers
        $this->store_rate_limit_info($response);

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $error_msg = 'Twitter API returned status code: ' . $status_code;
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            error_log('Twitter API Error Response: ' . wp_json_encode($error_data));
            
            if (isset($error_data['errors'])) {
                $error_msg .= ' - ' . esc_html(json_encode($error_data['errors']));
            }
            
            // Special handling for rate limit errors
            if ($status_code === 429) {
                $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                if ($retry_after) {
                    $error_msg .= ' - Please wait ' . intval($retry_after) . ' seconds before trying again.';
                } else {
                    $error_msg .= ' - Rate limit exceeded. Please wait before trying again.';
                }
            }
            
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

        $included_tweets = isset($data['includes']['tweets']) ? $data['includes']['tweets'] : [];
        $included_media = isset($data['includes']['media']) ? $data['includes']['media'] : [];
        
        $counter = 0;

        foreach ($data['data'] as $tweet) {
            $original_tweet = $this->get_retweeted_tweet_data($tweet, $included_tweets);
            $tweet_id = sanitize_text_field($tweet['id']);
            $tweet_text = $original_tweet ? $original_tweet['text'] : $tweet['text'];
            $tweet_date = date("Y-m-d H:i:s", strtotime($tweet['created_at']));
            
            $media_html = '';
            $featured_image_url = null;
            
            $source_tweet = $original_tweet ?: $tweet;
            
            if (isset($source_tweet['attachments']['media_keys'])) {
                $media_keys = $source_tweet['attachments']['media_keys'];
                $media_html = $this->process_media_attachments($media_keys, $included_media);
                $featured_image_url = $this->get_first_image_url($media_keys, $included_media);
            }

            if (!$this->tweet_exists($tweet_id)) {
                $formatted_text = $this->format_tweet_text($tweet_text);
                
                $post_data = array(
                    'post_title'   => wp_strip_all_tags('Tweet on ' . date("M d, Y", strtotime($tweet['created_at']))),
                    'post_content' => $formatted_text . $media_html,
                    'post_status'  => 'publish',
                    'post_author'  => 1,
                    'post_date'    => $tweet_date,
                    'post_type'    => 'post',
                    'meta_input'   => array('tweet_id' => $tweet_id)
                );

                $post_id = wp_insert_post($post_data);
                
                if ($post_id && $featured_image_url) {
                    $this->set_featured_image($post_id, $featured_image_url);
                    $counter++;
                } elseif ($post_id) {
                    $counter++;
                }
            }
        }
        
        $success_msg = sprintf('Successfully imported %d tweets.', $counter);
        return $return_errors ? $success_msg : true;
    }

    private function store_rate_limit_info($response) {
        $rate_limits = array();
        
        $limit = wp_remote_retrieve_header($response, 'x-rate-limit-limit');
        $remaining = wp_remote_retrieve_header($response, 'x-rate-limit-remaining');
        $reset = wp_remote_retrieve_header($response, 'x-rate-limit-reset');
        
        if ($limit) $rate_limits['limit'] = intval($limit);
        if ($remaining) $rate_limits['remaining'] = intval($remaining);
        if ($reset) $rate_limits['reset'] = intval($reset);
        
        if (!empty($rate_limits)) {
            // Set human-readable reset time for display
            if (isset($rate_limits['reset'])) {
                $rate_limits['reset_time'] = date('Y-m-d H:i:s', $rate_limits['reset']);
            }
            
            $rate_limits['last_updated'] = current_time('mysql');
            update_option($this->rate_limit_option, $rate_limits);
        }
    }

    private function get_first_image_url($media_keys, $included_media) {
        if (empty($media_keys) || empty($included_media)) {
            return null;
        }
        
        foreach ($media_keys as $key) {
            foreach ($included_media as $media) {
                if ($media['media_key'] === $key) {
                    if ($media['type'] === 'photo' && isset($media['url'])) {
                        return $media['url'];
                    } elseif (($media['type'] === 'video' || $media['type'] === 'animated_gif') && isset($media['preview_image_url'])) {
                        return $media['preview_image_url'];
                    }
                }
            }
        }
        
        return null;
    }
    
    private function set_featured_image($post_id, $image_url) {
        $upload_dir = wp_upload_dir();
        $image_data = file_get_contents($image_url);
        $filename = basename($image_url);
        
        if (wp_mkdir_p($upload_dir['path'])) {
            $file = $upload_dir['path'] . '/' . $filename;
        } else {
            $file = $upload_dir['basedir'] . '/' . $filename;
        }
        
        file_put_contents($file, $image_data);
        
        $wp_filetype = wp_check_filetype($filename, null);
        
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        $attach_id = wp_insert_attachment($attachment, $file, $post_id);
        
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        set_post_thumbnail($post_id, $attach_id);
        
        return $attach_id;
    }

    private function process_media_attachments($media_keys, $included_media) {
        $media_html = '';
        
        if (empty($media_keys) || empty($included_media)) {
            return $media_html;
        }
        
        foreach ($media_keys as $key) {
            foreach ($included_media as $media) {
                if ($media['media_key'] === $key) {
                    switch ($media['type']) {
                        case 'photo':
                            if (isset($media['url'])) {
                                $media_html .= '<figure class="twitter-media twitter-image">';
                                $media_html .= '<img src="' . esc_url($media['url']) . '" alt="Tweet image" style="max-width:100%;" />';
                                $media_html .= '</figure>';
                            }
                            break;
                            
                        case 'video':
                        case 'animated_gif':
                            if (isset($media['preview_image_url'])) {
                                $media_html .= '<figure class="twitter-media twitter-video">';
                                $media_html .= '<img src="' . esc_url($media['preview_image_url']) . '" alt="Tweet video thumbnail" style="max-width:100%;" />';
                                $media_html .= '<div class="video-note">[View on Twitter to see this video]</div>';
                                $media_html .= '</figure>';
                            }
                            break;
                    }
                }
            }
        }
        
        return $media_html;
    }
    
    private function format_tweet_text($text) {
        $pattern = '/(https?:\/\/[^\s]+)/';
        $text = preg_replace($pattern, '<a href="$1" target="_blank" rel="nofollow">$1</a>', $text);
        
        $pattern = '/@(\w+)/';
        $text = preg_replace($pattern, '<a href="https://twitter.com/$1" target="_blank" rel="nofollow">@$1</a>', $text);
        
        $pattern = '/#(\w+)/';
        $text = preg_replace($pattern, '<a href="https://twitter.com/hashtag/$1" target="_blank" rel="nofollow">#$1</a>', $text);
        
        return '<div class="tweet-text">' . wpautop($text) . '</div>';
    }

    private function get_retweeted_tweet_data($tweet, $included_tweets) {
        if (!isset($tweet['referenced_tweets']) || empty($included_tweets)) {
            return null;
        }
        
        foreach ($tweet['referenced_tweets'] as $ref) {
            if ($ref['type'] === 'retweeted') {
                foreach ($included_tweets as $included) {
                    if ($included['id'] === $ref['id']) {
                        return $included;
                    }
                }
            }
        }
        return null;
    }

    private function tweet_exists($tweet_id) {
        global $wpdb;
        $query = $wpdb->prepare("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = 'tweet_id' AND meta_value = %s", $tweet_id);
        return (int) $wpdb->get_var($query) > 0;
    }

    public function add_admin_menu() {
        add_options_page(
            'Twitter Fetcher Settings', 
            'Twitter Fetcher', 
            'manage_options', 
            'twitter_fetcher', 
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting(
            'twitter_fetcher_settings',
            $this->option_name,
            array($this, 'sanitize_options')
        );

        add_settings_section(
            'twitter_fetcher_main_section',
            'API Settings',
            array($this, 'render_settings_section'),
            'twitter_fetcher'
        );

        add_settings_field(
            'twitter_bearer_token',
            'Twitter Bearer Token',
            array($this, 'render_bearer_token_field'),
            'twitter_fetcher',
            'twitter_fetcher_main_section'
        );

        add_settings_field(
            'twitter_user_id',
            'Twitter User ID',
            array($this, 'render_user_id_field'),
            'twitter_fetcher',
            'twitter_fetcher_main_section'
        );
    }

    public function sanitize_options($input) {
        $sanitized = array();
        
        if (isset($input['bearer_token'])) {
            $sanitized['bearer_token'] = sanitize_text_field($input['bearer_token']);
        }
        
        if (isset($input['user_id'])) {
            $sanitized['user_id'] = sanitize_text_field($input['user_id']);
        }
        
        return $sanitized;
    }

    public function render_settings_section() {
        echo '<p>Enter your Twitter API credentials below. You can obtain these from the Twitter Developer Portal.</p>';
    }

    public function render_bearer_token_field() {
        $value = isset($this->options['bearer_token']) ? $this->options['bearer_token'] : '';
        echo '<input type="password" id="twitter_bearer_token" name="' . $this->option_name . '[bearer_token]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Your Twitter API Bearer Token</p>';
    }

    public function render_user_id_field() {
        $value = isset($this->options['user_id']) ? $this->options['user_id'] : '';
        echo '<input type="text" id="twitter_user_id" name="' . $this->option_name . '[user_id]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Your Twitter User ID (numeric ID, not username)</p>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('twitter_fetcher_settings');
                do_settings_sections('twitter_fetcher');
                submit_button('Save Settings');
                ?>
            </form>
            
            <hr>
            
            <h2>API Rate Limits</h2>
            <?php $this->display_rate_limits(); ?>
            
            <hr>
            
            <h2>Manual Tweet Fetch</h2>
            <p>Click the button below to manually fetch tweets from the last 24 hours and add them as blog posts.</p>
            <form method="post">
                <?php wp_nonce_field('twitter_fetch_tweets_nonce', 'twitter_fetch_nonce'); ?>
                <input type="hidden" name="fetch_tweets_now" value="1">
                <?php submit_button('Fetch Tweets Now', 'secondary'); ?>
            </form>
        </div>
        <?php
    }
    
    public function display_rate_limits() {
        $rate_limits = get_option($this->rate_limit_option, array());
        
        if (empty($rate_limits)) {
            echo '<p>No rate limit information available. Make a request first.</p>';
            return;
        }
        
        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';
        
        if (isset($rate_limits['limit'])) {
            echo '<tr>';
            echo '<th scope="row">Limit</th>';
            echo '<td>' . esc_html($rate_limits['limit']) . ' requests</td>';
            echo '</tr>';
        }
        
        if (isset($rate_limits['remaining'])) {
            echo '<tr>';
            echo '<th scope="row">Remaining</th>';
            echo '<td>' . esc_html($rate_limits['remaining']) . ' requests</td>';
            echo '</tr>';
        }
        
        if (isset($rate_limits['reset_time'])) {
            echo '<tr>';
            echo '<th scope="row">Resets At</th>';
            echo '<td>' . esc_html($rate_limits['reset_time']) . '</td>';
            echo '</tr>';
        }
        
        if (isset($rate_limits['last_updated'])) {
            echo '<tr>';
            echo '<th scope="row">Last Updated</th>';
            echo '<td>' . esc_html($rate_limits['last_updated']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        // Add warning if rate limit is nearly reached
        if (isset($rate_limits['remaining']) && $rate_limits['remaining'] < 5) {
            echo '<div class="notice notice-warning inline"><p><strong>Warning:</strong> You are approaching your rate limit. Only ' . esc_html($rate_limits['remaining']) . ' requests remaining.</p></div>';
        }
    }

    public function handle_manual_tweet_fetch() {
        if (
            isset($_POST['fetch_tweets_now']) && 
            current_user_can('manage_options') && 
            isset($_POST['twitter_fetch_nonce']) && 
            wp_verify_nonce($_POST['twitter_fetch_nonce'], 'twitter_fetch_tweets_nonce')
        ) {
            $result = $this->fetch_twitter_tweets(true);
            
            if ($result === true) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>Fetched latest tweets successfully!</p></div>';
                });
            } else {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-error is-dismissible"><p>Error fetching tweets: ' . esc_html($result) . '</p></div>';
                });
            }
        }
    }
}

function twitter_fetcher_init() {
    new Twitter_Fetcher();
}
add_action('plugins_loaded', 'twitter_fetcher_init');

register_uninstall_hook(__FILE__, 'twitter_fetcher_uninstall');
function twitter_fetcher_uninstall() {
    delete_option('twitter_fetcher_options');
    delete_option('twitter_fetcher_rate_limits');
    wp_clear_scheduled_hook('fetch_twitter_tweets_daily');
}
