<?php
/**
 * Plugin Name: Technical Email
 * Description: Redirects system notifications to the technical contact.
 * Version: 1.0
 * Author: Tio Yazilim
 * Author URI: https://tio.studio
 * Text Domain: technical-email
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TMM_DEFAULT_TECH_EMAIL', 'sinan@tio.studio' );
define( 'TMM_GH_REPO', 'TioSinan/technical-email' );

/**
 * Load plugin textdomain for translations
 */
add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'technical-email', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});

/**
 * Handle wp-config.php path and file operations
 */
function tmm_get_wpconfig_path() {
    $path = ABSPATH . 'wp-config.php';
    if ( ! file_exists( $path ) ) {
        $path = dirname( ABSPATH ) . '/wp-config.php';
    }
    return file_exists( $path ) ? $path : false;
}

function tmm_update_config_recovery_email( $email, $remove = false ) {
    $path = tmm_get_wpconfig_path();
    if ( ! $path || ! is_writable( $path ) ) return;

    $content = file_get_contents( $path );
    $pattern = "/define\s*\(\s*['\"]RECOVERY_MODE_EMAIL['\"]\s*,\s*['\"].*?['\"]\s*\)\s*;/";
    $new_line = $remove ? "" : "define( 'RECOVERY_MODE_EMAIL', '" . esc_sql( $email ) . "' );";

    if ( preg_match( $pattern, $content ) ) {
        $content = preg_replace( $pattern, $new_line, $content );
    } else if ( ! $remove ) {
        $stop_editing = "/* That's all, stop editing! Happy publishing. */";
        if ( strpos( $content, $stop_editing ) !== false ) {
            $content = str_replace( $stop_editing, $new_line . "\n" . $stop_editing, $content );
        } else {
            $content .= "\n" . $new_line;
        }
    }
    file_put_contents( $path, $content );
}

/**
 * Activation and Deactivation hooks
 */
register_activation_hook( __FILE__, function() {
    tmm_update_config_recovery_email( TMM_DEFAULT_TECH_EMAIL );
});

register_deactivation_hook( __FILE__, function() {
    tmm_update_config_recovery_email( '', true );
});

/**
 * Register settings and add field to General Settings page
 */
add_action( 'admin_init', function() {
    register_setting( 'general', 'technical_email_address', array(
        'sanitize_callback' => function($email) {
            tmm_update_config_recovery_email( $email );
            return sanitize_email($email);
        },
        'default' => TMM_DEFAULT_TECH_EMAIL
    ) );

    add_settings_field(
        'technical_email_address_field',
        __( 'Technical Email Address', 'technical-email' ),
        'tmm_render_field',
        'general',
        'default',
        array( 'class' => 'technical-email-row' )
    );
});

function tmm_render_field() {
    $value = get_option( 'technical_email_address', TMM_DEFAULT_TECH_EMAIL );
    ?>
    <input name="technical_email_address" type="email" id="technical_email_address" value="<?php echo esc_attr( $value ); ?>" class="regular-text ltr" />
    <p class="description"><?php echo esc_html__( 'Site updates and technical error (Fatal Error) notifications will be sent here and saved to wp-config.php.', 'technical-email' ); ?></p>
    <?php
}

/**
 * Add "Settings" link to the plugin list page
 */
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), function ( $links ) {
    $settings_link = '<a href="' . admin_url( 'options-general.php' ) . '">' . esc_html__( 'Settings', 'technical-email' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
});

/**
 * Notification redirection logic
 */
function tmm_get_recipient() {
    $email = get_option( 'technical_email_address', TMM_DEFAULT_TECH_EMAIL );
    return ! empty( $email ) ? $email : TMM_DEFAULT_TECH_EMAIL;
}

add_filter( 'auto_core_update_email', 'tmm_redirect_notification' );
add_filter( 'auto_plugin_theme_update_email', 'tmm_redirect_notification' );
function tmm_redirect_notification( $email ) {
    $email['to'] = tmm_get_recipient();
    return $email;
}

add_filter( 'recovery_mode_email', function( $email_data ) {
    $email_data['to'] = tmm_get_recipient();
    return $email_data;
});

add_filter( 'site_status_tests_notifications_emails', function( $emails ) {
    return array( tmm_get_recipient() );
});

/**
 * UPDATE SERVICE VIA GITHUB
 */
function tmm_get_gh_release_data() {
    $transient_key = 'tmm_gh_update_cache';
    $remote = get_transient($transient_key);

    if (false === $remote) {
        $url = "https://api.github.com/repos/" . TMM_GH_REPO . "/releases/latest";
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Technical-Email'
            )
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $remote = json_decode(wp_remote_retrieve_body($response));
        // Cache for 12 hours to ensure performance
        set_transient($transient_key, $remote, 12 * HOUR_IN_SECONDS);
    }
    return $remote;
}

add_filter('site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    $plugin_slug = plugin_basename(__FILE__);
    $current_version = $transient->checked[$plugin_slug];
    $remote = tmm_get_gh_release_data();

    if ($remote && isset($remote->tag_name)) {
        $new_version = ltrim($remote->tag_name, 'v');

        if (version_compare($current_version, $new_version, '<')) {
            $obj = new stdClass();
            $obj->slug = 'technical-email';
            $obj->plugin = $plugin_slug;
            $obj->new_version = $new_version;
            $obj->package = $remote->zipball_url;
            $obj->url = 'https://github.com/' . TMM_GH_REPO;
            
            $transient->response[$plugin_slug] = $obj;
        }
    }
    return $transient;
});

/**
 * Plugin Information Popup
 */
add_filter('plugins_api', function($res, $action, $args) {
    if ($action !== 'plugin_information' || $args->slug !== 'technical-email') return $res;

    $remote = tmm_get_gh_release_data();
    if ($remote) {
        $res = new stdClass();
        $res->name = 'Technical Email';
        $res->slug = 'technical-email';
        $res->version = ltrim($remote->tag_name, 'v');
        $res->author = 'Tio Yazilim';
        $res->download_link = $remote->zipball_url;
        $res->sections = array(
            'description' => 'Redirects system notifications to the technical contact.',
            'changelog'   => isset($remote->body) ? $remote->body : 'Check GitHub for details.'
        );
        return $res;
    }
    return $res;
}, 20, 3);

/**
 * Position the field under the Administration Email Address using jQuery
 */
add_action( 'admin_footer', function() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'options-general' ) return;
    ?>
    <script type="text/javascript">
        (function($) {
            $(function() {
                var adminRow = $('#new_admin_email').closest('tr');
                if (!adminRow.length) adminRow = $('#admin_email').closest('tr');
                var techRow = $('.technical-email-row').closest('tr');
                if (adminRow.length && techRow.length) techRow.insertAfter(adminRow);
            });
        })(jQuery);
    </script>
    <style>.technical-email-row { display: table-row !important; }</style>
    <?php
});