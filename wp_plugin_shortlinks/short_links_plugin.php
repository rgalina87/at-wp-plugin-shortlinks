<?php
/**
 * Plugin Name: Short Links
 * Version: 1.0.0
 * Author: GR
 * Description: Create custom short links
 */

//Adding Singleton class
abstract class Singleton {

    private static array $instances = [];

    private function __construct() {
    }

    public static function get_instance(): Singleton {
        if ( ! isset( self::$instances[ static::class ] ) ) {
            self::$instances[ static::class ] = new static();
        }
        return self::$instances[ static::class ];
    }
}

//Creating inherited class for ShortLinksPlugin
class ShortLinksPlugin extends Singleton {

    public function __construct() {
        add_action('init', array($this, 'register_short_links_post_type'));
        add_filter('manage_short_link_posts_columns', array($this, 'add_short_links_columns'));
        add_action('manage_short_link_posts_custom_column', array($this, 'populate_short_links_columns'), 10, 2);
        add_filter('manage_edit-short_link_sortable_columns', array($this, 'make_short_links_columns_sortable'));
        add_action('pre_get_posts', array($this, 'sort_short_links_columns'));
        add_action('quick_edit_custom_box', array($this, 'add_short_links_quick_edit'), 10, 2);
        add_action('add_meta_boxes', array($this, 'add_click_count_meta_field'));
        add_action('save_post', array($this, 'save_click_count_meta'));
        add_action('template_redirect', array($this, 'track_clicks'));
    }

    //Register Custom Post Type "Short Links"
    public function register_short_links_post_type() {
        register_post_type('short_link',
            array(
                'labels' => array(
                    'name' => __('Short Links'),
                    'singular_name' => __('Short Link')
                ),
                'public' => true,
                'has_archive' => true,
                'rewrite' => array('slug' => 'short-links'),
            )
        );
    }


    public function add_short_links_columns($columns) {
        $columns['short_link'] = __('Short Link');
        $columns['original_link'] = __('Original Link');
        $columns['click_count'] = __('Click Count');
        return $columns;
    }

    public function populate_short_links_columns($column, $post_id) {
        switch ($column) {
            case 'short_link':
                $short_link = get_post_meta($post_id, 'short_link', true);
                $original_link = get_post_meta($post_id, 'original_link', true);

                //with ACF Plugin
//                $short_link = get_field('short_link', $post_id);
//                $original_link = get_field('original_link', $post_id);

                // Check if the short link is valid
                $is_valid_short_link = $this->check_link($short_link);

                if ($is_valid_short_link) {
                    // Redirect to the original link
                    wp_redirect($original_link);
                    exit;
                } else {
                    // Short link is not valid, show 404 page
                    global $wp_query;
                    $wp_query->set_404();
                    status_header(404);
                    nocache_headers();
                    include(get_query_template('404'));
                    exit;
                }
                break;
            case 'original_link':
                $original_link = get_post_meta($post_id, 'original_link', true);

                // Check if the original link is valid
                $is_valid_original_link = $this->check_link($original_link);

                if ($is_valid_original_link) {
                    $output = sprintf(<<<HTML
                        <a href="%s" target="_blank">%s</a>
HTML, esc_url($original_link), esc_html($original_link));
                } else {
                    // Original link is not valid, show 404 page
                    global $wp_query;
                    $wp_query->set_404();
                    status_header(404);
                    nocache_headers();
                    include(get_query_template('404'));
                    exit;
                }

                echo $output;
                break;
            case 'click_count':
                $click_count = get_post_meta($post_id, 'click_count', true);
                echo $click_count;
                break;
            default:
                break;
        }
    }

    public function make_short_links_columns_sortable($columns) {
        $columns['short_link'] = 'short_link';
        $columns['original_link'] = 'original_link';
        $columns['click_count'] = 'click_count';
        return $columns;
    }

    public function sort_short_links_columns($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('orderby') == 'short_link') {
            $query->set('meta_key', 'short_link');
            $query->set('orderby', 'meta_value');
        }

        if ($query->get('orderby') == 'original_link') {
            $query->set('meta_key', 'original_link');
            $query->set('orderby', 'meta_value');
        }

        if ($query->get('orderby') == 'click_count') {
            $query->set('meta_key', 'click_count');
            $query->set('orderby', 'meta_value_num');
        }
    }

    public function add_short_links_quick_edit($column_name, $post_type) {
        if ($column_name == 'short_link' || $column_name == 'original_link') {
            echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><span class="title"></span>';
            echo '<label><span class="title">' . __('Short Link') . '</span><input type="text" name="short_link" value=""></label>';
            echo '<label><span class="title">' . __('Original Link') . '</span><input type="text" name="original_link" value=""></label>';
            echo '</div></fieldset>';
        }
    }

    public function add_click_count_meta_field() {
        add_meta_box(
            'click_count_meta_box',
            __('Click Count'),
            array($this, 'render_click_count_meta_box'),
            'short_link',
            'side',
            'default'
        );
    }

    public function render_click_count_meta_box($post) {
        $click_count = get_post_meta($post->ID, 'click_count', true);
        echo '<p>' . __('Total Clicks: ') . $click_count . '</p>';
    }

    public function save_click_count_meta($post_id) {
        if (array_key_exists('click_count', $_POST)) {
            update_post_meta(
                $post_id,
                'click_count',
                $_POST['click_count']
            );
        }
    }

    public function track_clicks() {
        if (is_singular('short_link')) {
            $post_id = get_the_ID();
            $click_count = get_post_meta($post_id, 'click_count', true);
            $click_count = $click_count ? intval($click_count) : 0;

            $user_id = get_current_user_id();
            $session_key = 'short_link_click_' . $post_id;

            $last_click_time = get_user_meta($user_id, $session_key, true);
            if (!$last_click_time || time() - $last_click_time > 120) { // Check if more than 2 minutes have passed
                update_user_meta($user_id, $session_key, time());
                update_post_meta($post_id, 'click_count', $click_count + 1);
            }
        }
    }

    private function check_link($link) {
        // Example for checking if the URL returns a 200 status code
        $response = wp_remote_get($link);
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code === 200;
    }

}

$short_links_plugin = ShortLinksPlugin::get_instance();

?>
