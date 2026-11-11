<?php
/**
 * Plugin Name:       Ofnoa Print Upscaler
 * Description:       מנוע AI לשדרוג תמונות וגרפיקה ל-4K: שיפור אופציונלי לפי פרומפט (Gemini / deAPI), הגדלה ב-Real-ESRGAN (free.ai / deAPI / מחשב מקומי / Replicate, עם מעבר אוטומטי בין מנועים), דאשבורד "שדרוג מהיר" למנהל, בדיקת נאמנות אוטומטית, סוכני הגהה ובדיקת איות (Claude), והחזרה כ-PDF במייל.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ofnoacomps
 * Text Domain:       ofnoa-print-upscaler
 * License:           GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OFP_VERSION', '1.5.0' );
define( 'OFP_FILE', __FILE__ );
define( 'OFP_DIR', plugin_dir_path( __FILE__ ) );
define( 'OFP_URL', plugin_dir_url( __FILE__ ) );

require_once OFP_DIR . 'includes/class-ofp-settings.php';
require_once OFP_DIR . 'includes/class-ofp-jobs.php';
require_once OFP_DIR . 'includes/class-ofp-image.php';
require_once OFP_DIR . 'includes/class-ofp-replicate.php';
require_once OFP_DIR . 'includes/class-ofp-engines.php';
require_once OFP_DIR . 'includes/class-ofp-worker.php';
require_once OFP_DIR . 'includes/class-ofp-claude.php';
require_once OFP_DIR . 'includes/class-ofp-pdf.php';
require_once OFP_DIR . 'includes/class-ofp-mailer.php';
require_once OFP_DIR . 'includes/class-ofp-processor.php';
require_once OFP_DIR . 'includes/class-ofp-frontend.php';
require_once OFP_DIR . 'includes/class-ofp-admin.php';
require_once OFP_DIR . 'includes/class-ofp-quick.php';
require_once OFP_DIR . 'includes/class-ofp-updater.php';

register_activation_hook( __FILE__, array( 'OFP_Jobs', 'install' ) );
register_deactivation_hook( __FILE__, array( 'OFP_Processor', 'unschedule' ) );

add_action( 'plugins_loaded', function () {
	OFP_Jobs::maybe_upgrade();
	OFP_Settings::init();
	OFP_Processor::init();
	OFP_Frontend::init();
	OFP_Admin::init();
	OFP_Quick::init();
	OFP_Updater::init();
	OFP_Worker::init();
} );

// Elementor widget (optional – the shortcode works everywhere too).
add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
	require_once OFP_DIR . 'includes/class-ofp-elementor-widget.php';
	$widgets_manager->register( new OFP_Elementor_Widget() );
} );
