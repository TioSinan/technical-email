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
 * Load plugin textdomain
 */
add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'technical-email', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

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
 * OTOMATIK GÜNCELLEME SERVISI
 */
$tmm_update_url = 'https://tio.studio/pluginservis/technical-email.json';
$tmm_plugin_slug = plugin_basename(__FILE__); 

add_filter('site_transient_update_plugins', function($transient) use ($tmm_update_url, $tmm_plugin_slug) {
    if (empty($transient->checked)) return $transient;

    $remote = wp_remote_get($tmm_update_url, array(
        'timeout' => 10,
        'headers' => array('Accept' => 'application/json')
    ));

    if (!is_wp_error($remote) && wp_remote_retrieve_response_code($remote) == 200) {
        $data = json_decode(wp_remote_retrieve_body($remote));
        
        if ($data && version_compare($transient->checked[$tmm_plugin_slug], $data->version, '<')) {
            $obj = new stdClass();
            $obj->id          = 'tio.studio/technical-email';
            $obj->slug        = 'technical-email';
            $obj->plugin      = $tmm_plugin_slug;
            $obj->new_version = $data->version;
            $obj->package     = $data->download_url;
            $obj->url         = 'https://tio.studio';
            $obj->icons       = array('default' => 'https://s.w.org/plugins/gears/icon-256x256.png');
            
            $transient->response[$tmm_plugin_slug] = $obj;
        }
    }
    return $transient;
});

// Otomatik güncelleme desteğini WordPress'e bildir
add_filter('auto_update_plugin', function($update, $item) use ($tmm_plugin_slug) {
    if (isset($item->plugin) && $item->plugin === $tmm_plugin_slug) {
        return true;
    }
    return $update;
}, 10, 2);

// Pop-up Bilgisi
add_filter('plugins_api', function($res, $action, $args) use ($tmm_update_url) {
    if ($action !== 'plugin_information' || $args->slug !== 'technical-email') return $res;

    $remote = wp_remote_get($tmm_update_url);
    if (!is_wp_error($remote) && wp_remote_retrieve_response_code($remote) == 200) {
        $data = json_decode(wp_remote_retrieve_body($remote));
        $res = new stdClass();
        $res->name = $data->name;
        $res->slug = $data->slug;
        $res->version = $data->version;
        $res->download_link = $data->download_url;
        $res->sections = (array) $data->sections;
        $res->last_updated = '2025-12-18';
        return $res;
    }
    return $res;
}, 20, 3);