<?php
/**
 * Scheduled database backups: gzip mysqldump plus an admin UI under Tools.
 */

defined( 'ABSPATH' ) || exit;

function omef_backup_dir(): string {
	$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'omef-backups';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return $dir;
}

function omef_backup_list(): array {
	$backups = array();
	foreach ( glob( omef_backup_dir() . '/*.sql.gz' ) ?: array() as $file ) {
		$backups[ basename( $file ) ] = filesize( $file );
	}
	arsort( $backups );
	return $backups;
}

function omef_backup_credentials(): array {
	$host = getenv( 'WORDPRESS_DB_HOST' ) ?: getenv( 'DB_HOST' ) ?: 'localhost';
	$port = 3306;
	if ( str_contains( $host, ':' ) ) {
		[$host, $port] = explode( ':', $host, 2 );
	}

	return array(
		'host'     => $host,
		'port'     => (int) $port,
		'user'     => getenv( 'WORDPRESS_DB_USER' ) ?: getenv( 'DB_USER' ) ?: DB_USER,
		'password' => getenv( 'WORDPRESS_DB_PASSWORD' ) ?: getenv( 'DB_PASSWORD' ) ?: DB_PASSWORD,
		'name'     => getenv( 'WORDPRESS_DB_NAME' ) ?: getenv( 'DB_NAME' ) ?: DB_NAME,
	);
}

function omef_backup_now(): string|WP_Error {
	$dir = omef_backup_dir();
	$stamp = gmdate( 'Ymd-His' );
	$sql = $dir . '/omef-db-' . $stamp . '.sql';
	$target = $sql . '.gz';
	$credentials = omef_backup_credentials();

	$command = sprintf(
		'mysqldump --no-tablespaces --single-transaction --quick --default-character-set=utf8mb4 -h %s -P %d -u %s -p%s %s > %s 2> %s.err; [ $? -eq 0 ] && gzip -f %s',
		escapeshellarg( $credentials['host'] ),
		(int) $credentials['port'],
		escapeshellarg( $credentials['user'] ),
		escapeshellarg( $credentials['password'] ),
		escapeshellarg( $credentials['name'] ),
		escapeshellarg( $sql ),
		escapeshellarg( $sql ),
		escapeshellarg( $sql )
	);

	exec( $command, $output, $exit_code );
	clearstatcache();

	if ( ! is_file( $target ) || filesize( $target ) === 0 || $exit_code !== 0 ) {
		$log = is_file( $sql . '.err' ) ? trim( (string) file_get_contents( $sql . '.err' ) ) : '';
		@unlink( $sql );
		@unlink( $sql . '.err' );
		return new WP_Error( 'omef_backup_failed', 'הגיבוי נכשל.' . ( $log ? ' (' . $log . ')' : '' ) );
	}

	@unlink( $sql );
	@unlink( $sql . '.err' );
	omef_backup_prune();
	return $target;
}

function omef_backup_prune(): void {
	$names = array_keys( omef_backup_list() );
	if ( count( $names ) <= 14 ) {
		return;
	}

	foreach ( array_slice( $names, 14 ) as $name ) {
		unlink( omef_backup_dir() . '/' . $name );
	}
}

function omef_daily_backup(): void {
	$result = omef_backup_now();
	if ( is_wp_error( $result ) ) {
		error_log( 'omef_backup: ' . $result->get_error_message() );
	}
}
add_action( 'omef_daily_backup_cron', 'omef_daily_backup' );

function omef_backups_list_ajax(): void {
	omef_dashboard_guard( 'manage_options' );

	$backups = array();
	foreach ( omef_backup_list() as $name => $size ) {
		$backups[] = array(
			'name'        => $name,
			'size'        => (int) $size,
			'downloadUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=omef_backup_download&file=' . rawurlencode( $name ) ), 'omef_backup_download' ),
		);
	}

	wp_send_json_success( array( 'backups' => $backups ) );
}
add_action( 'wp_ajax_omef_backups_list', 'omef_backups_list_ajax' );

function omef_backups_run_ajax(): void {
	omef_dashboard_guard( 'manage_options' );

	$result = omef_backup_now();
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'message' => 'הגיבוי נוצר: ' . basename( $result ) ) );
}
add_action( 'wp_ajax_omef_backups_run', 'omef_backups_run_ajax' );

function omef_backup_download_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'אין הרשאה.' );
	}
	check_admin_referer( 'omef_backup_download' );

	$name = basename( sanitize_file_name( (string) ( $_GET['file'] ?? '' ) ) );
	$path = omef_backup_dir() . '/' . $name;
	if ( ! is_file( $path ) ) {
		wp_die( 'הקובץ לא נמצא.' );
	}

	nocache_headers();
	header( 'Content-Type: application/gzip' );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path );
	exit;
}
add_action( 'admin_post_omef_backup_download', 'omef_backup_download_action' );