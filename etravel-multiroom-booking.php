<?php
/**
 * Plugin Name: eTravel Multi-Room Booking for MotoPress
 * Description: Exact per-room guest requests, multi-room availability planning, MotoPress handoff, checkout occupancy prefilling, and booking request records for eTravel.
 * Version: 1.1.0
 * Author: eTravel
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: etravel-multiroom
 */

defined( 'ABSPATH' ) || exit;

define( 'ETR_MR_VERSION', '1.1.0' );
define( 'ETR_MR_FILE', __FILE__ );
define( 'ETR_MR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETR_MR_URL', plugin_dir_url( __FILE__ ) );

require_once ETR_MR_DIR . 'includes/class-etravel-mr-settings.php';
require_once ETR_MR_DIR . 'includes/class-etravel-mr-token.php';
require_once ETR_MR_DIR . 'includes/class-etravel-mr-party.php';
require_once ETR_MR_DIR . 'includes/class-etravel-mr-validator.php';
require_once ETR_MR_DIR . 'includes/class-etravel-mr-allocator.php';
require_once ETR_MR_DIR . 'includes/class-etravel-mr-motopress.php';
require_once ETR_MR_DIR . 'includes/class-etravel-mr-frontend.php';
require_once ETR_MR_DIR . 'includes/class-etravel-mr-booking-meta.php';

final class ETravel_Multiroom_Booking {
    public static function boot(): void {
        register_activation_hook( ETR_MR_FILE, [ 'ETravel_MR_Settings', 'activate' ] );
        add_action( 'plugins_loaded', [ __CLASS__, 'init' ], 20 );
    }

    public static function init(): void {
        load_plugin_textdomain( 'etravel-multiroom', false, dirname( plugin_basename( ETR_MR_FILE ) ) . '/languages' );
        ETravel_MR_Settings::init();
        ETravel_MR_Frontend::init();
        ETravel_MR_Booking_Meta::init();

        if ( is_admin() && ! ETravel_MR_MotoPress::is_available() ) {
            add_action( 'admin_notices', [ __CLASS__, 'missing_dependency_notice' ] );
        }
    }

    public static function missing_dependency_notice(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>eTravel Multi-Room Booking:</strong> MotoPress Hotel Booking is not active. The search form can render, but availability planning and checkout integration remain disabled.</p></div>';
    }
}

ETravel_Multiroom_Booking::boot();
