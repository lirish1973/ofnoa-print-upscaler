<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub-based updates – same mechanism as the other Ofnoa plugins (ofnoa-carousel, ofnoa-cards, ofnoa-forms):
 * plugin-updates.json at the repository root is polled hourly via raw.githubusercontent.com. When its
 * version is newer than the installed one WordPress shows a normal plugin update, and the package is the
 * ZIP the repository's GitHub Action attached to the release. With "עדכון אוטומטי" on (settings page) the
 * update is installed by WordPress' own background updater, without a click.
 */
class OFP_Updater {

	const REPO      = 'lirish1973/ofnoa-print-upscaler';
	const CACHE_KEY = 'ofp_update_manifest';

	private static $basename = '';

	public static function init() {
		self::$basename = plugin_basename( OFP_FILE );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	public static function flush() {
		delete_site_transient( self::CACHE_KEY );
		delete_transient( self::CACHE_KEY );
	}

	/** Remote manifest (cached for an hour; failures are cached too so a GitHub outage never slows wp-admin). */
	public static function manifest( $force = false ) {
		$cached = $force ? false : get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$manifest = array();
		$res      = wp_remote_get( 'https://raw.githubusercontent.com/' . self::REPO . '/main/plugin-updates.json', array( 'timeout' => 10 ) );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$j = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $j ) ) {
				$manifest = $j;
			}
		}
		// Only ever install packages from this repository's own releases.
		if ( ! empty( $manifest['download_url'] ) && 0 !== strpos( (string) $manifest['download_url'], 'https://github.com/' . self::REPO . '/releases/download/' ) ) {
			$manifest = array();
		}
		set_transient( self::CACHE_KEY, $manifest, HOUR_IN_SECONDS );
		return $manifest;
	}

	public static function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$m = self::manifest();
		if ( empty( $m['version'] ) || empty( $m['download_url'] ) ) {
			return $transient;
		}
		$item = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => dirname( self::$basename ),
			'plugin'       => self::$basename,
			'new_version'  => (string) $m['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => (string) $m['download_url'],
			'tested'       => isset( $m['tested'] ) ? (string) $m['tested'] : '',
			'requires_php' => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '',
			'icons'        => array(),
			'banners'      => array(),
		);
		if ( version_compare( (string) $m['version'], OFP_VERSION, '>' ) ) {
			$transient->response[ self::$basename ] = $item;
			unset( $transient->no_update[ self::$basename ] );
		} else {
			unset( $transient->response[ self::$basename ] );
			$transient->no_update[ self::$basename ] = $item; // lets WordPress show the auto-update column.
		}
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( self::$basename ) !== $args->slug ) {
			return $result;
		}
		$m = self::manifest();
		if ( ! $m ) {
			return $result;
		}
		return (object) array(
			'name'          => isset( $m['name'] ) ? $m['name'] : 'Ofnoa Print Upscaler',
			'slug'          => dirname( self::$basename ),
			'version'       => isset( $m['version'] ) ? $m['version'] : OFP_VERSION,
			'author'        => '<a href="https://github.com/lirish1973">Ofnoacomps</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'sections'      => array(
				'description' => isset( $m['description'] ) ? wp_kses_post( $m['description'] ) : '',
				'changelog'   => isset( $m['changelog'] ) ? wp_kses_post( $m['changelog'] ) : '',
			),
			'download_link' => isset( $m['download_url'] ) ? $m['download_url'] : '',
		);
	}

	/** Make sure the extracted package lands in the plugin's own folder whatever the ZIP's top folder is called. */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::$basename || ! $wp_filesystem ) {
			return $source;
		}
		$target = trailingslashit( dirname( $source ) ) . dirname( self::$basename ) . '/';
		if ( trailingslashit( $source ) === $target ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $target ) ? $target : $source;
	}

	public static function row_meta( $links, $file ) {
		if ( $file === self::$basename ) {
			$links[] = '<a href="https://github.com/' . esc_attr( self::REPO ) . '" target="_blank" rel="noopener noreferrer">צפייה ב-GitHub</a>';
		}
		return $links;
	}

	/** Background auto-install, controlled by the plugin's own setting. */
	public static function auto_update( $update, $item ) {
		if ( is_object( $item ) && isset( $item->plugin ) && $item->plugin === self::$basename ) {
			return 'on' === OFP_Settings::get( 'auto_update' );
		}
		return $update;
	}

	/** One line for the settings page / connection test. */
	public static function status_line() {
		$m = self::manifest( true );
		if ( empty( $m['version'] ) ) {
			return new WP_Error( 'ofp_update', 'לא התקבל קובץ העדכונים מ-GitHub (' . self::REPO . ')' );
		}
		return version_compare( (string) $m['version'], OFP_VERSION, '>' )
			? 'זמינה גרסה ' . $m['version'] . ' (מותקנת ' . OFP_VERSION . ')'
			: 'מעודכן – הגרסה האחרונה ב-GitHub היא ' . $m['version'];
	}
}
