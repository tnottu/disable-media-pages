<?php

/*
 * Plugin name: Disable Media Pages
 * Description: Plugin to disable "attachment" pages for WordPress media.
 * Author: Johannes Siipola
 * Author URI: https://siipo.la
 * Version: 1.0.0
 * License: GPL v2 or later
 * Text Domain: disable-media-pages
 */

require __DIR__ . '/vendor/autoload.php';

class DisableMediaPages {
    public function __construct() {

        $update_checker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/joppuyo/disable-media-pages',
            __FILE__,
            'disable-media-pages'
        );

        $update_checker->getVcsApi()->enableReleaseAssets();

        add_filter('wp_unique_post_slug', [$this, 'unique_slug'], 10, 6);
        add_filter('template_redirect', [$this, 'set_404']);
        add_filter('redirect_canonical', [$this, 'set_404'], 0);
        add_filter('attachment_link', [$this, 'change_attachment_link'], 10, 2);
        add_filter('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        add_filter(
            'plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']
        );
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('rest_api_init', [$this, 'rest_api_init']);
    }

    public static function debug(...$messages) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log(print_r($messages, true));
    }
}

    function set_404()
    {
        if (is_attachment()) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
        }
    }

    function change_attachment_link($url, $id)
    {
        $attachment_url = wp_get_attachment_url($id);
        if ($attachment_url) {
            return $attachment_url;
        }
        return $url;
    }

    function unique_slug($slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug) {
        if ($post_type === 'attachment') {
            return $this->generate_uuid_v4();
        }
        return $slug;
    }

    function admin_enqueue_scripts()
    {
        $plugin_data = get_plugin_data(__FILE__);
        $version = $plugin_data['Version'];
        $url = plugin_dir_url(__FILE__);
        $path = plugin_dir_path(__FILE__);

        wp_enqueue_script(
            'dmp-script',
            "{$url}dist/script.js",
            [],
            WP_DEBUG ? md5_file($path . 'dist/script.js') : $version
        );
        
        wp_localize_script('dmp-script', 'disable_media_pages', [
            'root' => rest_url(),
            'token' => wp_create_nonce('wp_rest'),
        ]);

        wp_enqueue_style(
            'dmp-style',
            "{$url}dist/style.css",
            [],
            WP_DEBUG ? md5_file($path . 'dist/style.css') : $version
        );
    }

    public function plugin_action_links($links) {
        $settings_link =
            '<a href="options-general.php?page=disable-media-pages">' .
            __('Settings', 'disable-media-pages') .
            '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function admin_menu() {
        add_submenu_page(
            null,
            __(
                'Disable Media Pages',
                'disable-media-pages'
            ),
            __(
                'Disable Media Pages',
                'disable-media-pages'
            ),
            'manage_options',
            'disable-media-pages',
            [$this, 'settings_page']
        );
    }

    public function settings_page() {
        echo '<div id="disable-media-pages"><disable-media-pages></disable-media-pages></div>';
    }

    public function rest_api_init()
    {
        register_rest_route('disable-media-pages/v1', '/get_all_attachments', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_api_get_all_attachments'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
        register_rest_route('disable-media-pages/v1', '/process/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_api_process_attachment'],
            'args' => ['id'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    public function rest_api_get_all_attachments(WP_REST_Request $data)
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => -1,
        ]);

        $json = [
            'posts' => $query->posts,
            'total' => $query->post_count,
        ];

        return new WP_REST_Response($json);
    }

    public function rest_api_process_attachment(WP_REST_Request $data)
    {
        $attachment = get_post($data->get_param('id'));
        $slug = $attachment->post_name;

        $is_uuid = (bool) preg_match('/[0-9a-f]{8}[0-9a-f]{4}4[0-9a-f]{3}[89ab][0-9a-f]{3}[0-9a-f]{12}/', $slug);

        if (!$is_uuid) {
            $new_attachment = [
                'ID' => $attachment->ID,
                'post_name' => $this->generate_uuid_v4(),
            ];

            wp_update_post($new_attachment);
        }

        return new WP_REST_Response([]);
    }

    /**
     * @return string|string[]
     */
    public function generate_uuid_v4()
    {
        return str_replace('-', '', wp_generate_uuid4());
    }

}

$disable_media_pages = new DisableMediaPages();