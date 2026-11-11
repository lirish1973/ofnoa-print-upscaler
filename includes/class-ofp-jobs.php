<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jobs table + private storage.
 */
class OFP_Jobs {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ofp_jobs';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(40) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'queued',
			step_attempts int(11) NOT NULL DEFAULT 0,
			customer_name varchar(190) NOT NULL DEFAULT '',
			customer_email varchar(190) NOT NULL DEFAULT '',
			customer_phone varchar(60) NOT NULL DEFAULT '',
			notes text NULL,
			original_name varchar(255) NOT NULL DEFAULT '',
			original_path varchar(255) NOT NULL DEFAULT '',
			source_path varchar(255) NOT NULL DEFAULT '',
			upscaled_path varchar(255) NOT NULL DEFAULT '',
			pdf_path varchar(255) NOT NULL DEFAULT '',
			prediction_id varchar(80) NOT NULL DEFAULT '',
			width int(11) NOT NULL DEFAULT 0,
			height int(11) NOT NULL DEFAULT 0,
			out_width int(11) NOT NULL DEFAULT 0,
			out_height int(11) NOT NULL DEFAULT 0,
			verdict varchar(30) NOT NULL DEFAULT '',
			report longtext NULL,
			log longtext NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status)
		) {$charset};"
		);
		update_option( 'ofp_db_version', self::DB_VERSION );
		self::storage_dir();
		OFP_Processor::schedule();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'ofp_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/** Private folder inside uploads, blocked from direct web access. */
	public static function storage_dir( $sub = '' ) {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['basedir'] ) . 'ofp-private';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $base . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $base . '/index.php', "<?php // Silence.\n" );
		}
		$dir = $sub ? $base . '/' . $sub : $base;
		if ( $sub && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function create( $data ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = wp_parse_args(
			$data,
			array(
				'token'      => wp_generate_password( 32, false, false ),
				'status'     => 'queued',
				'created_at' => $now,
				'updated_at' => $now,
				'log'        => '',
			)
		);
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function by_token( $token ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s", $token ) ); // phpcs:ignore
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		return $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	public static function log( $id, $msg ) {
		global $wpdb;
		$t    = self::table();
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n";
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET log = CONCAT(IFNULL(log,''), %s), updated_at = %s WHERE id = %d", $line, current_time( 'mysql', true ), $id ) ); // phpcs:ignore
	}

	public static function pending( $limit = 3 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN ('queued','enhancing','upscaling','verifying','proofing','building','mailing') ORDER BY id ASC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	public static function list_recent( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT id,token,status,verdict,customer_name,customer_email,original_name,width,height,out_width,out_height,created_at,updated_at FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) ); // phpcs:ignore
	}

	public static function count_recent_by_ip( $ip, $hours = 1 ) {
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ip = %s AND created_at > %s", $ip, $since ) ); // phpcs:ignore
	}

	public static function job_dir( $job ) {
		return self::storage_dir( 'job-' . (int) $job->id . '-' . substr( $job->token, 0, 8 ) );
	}

	/** Remove a job completely: every file in its private folder, the folder and the DB row. */
	public static function delete( $job ) {
		global $wpdb;
		if ( ! $job || empty( $job->id ) ) {
			return false;
		}
		$dir = self::job_dir( $job );
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			if ( is_file( $f ) ) {
				wp_delete_file( $f );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $job->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Delete files of old jobs (keeps the DB row + report). */
	public static function cleanup() {
		global $wpdb;
		$days = (int) OFP_Settings::get( 'retention_days' );
		if ( $days < 1 ) {
			return;
		}
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$old   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE created_at < %s AND original_path <> ''", $since ) ); // phpcs:ignore
		foreach ( $old as $job ) {
			$dir = self::job_dir( $job );
			foreach ( (array) glob( $dir . '/*' ) as $f ) {
				if ( is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			self::update(
				$job->id,
				array(
					'original_path' => '',
					'source_path'   => '',
					'upscaled_path' => '',
					'pdf_path'      => '',
				)
			);
		}
	}
}
