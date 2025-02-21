<?php
/*
Plugin Name: WP DB Backup Scheduler
Description: Schedule multiple database backup jobs (daily, weekly, monthly) with configurable times and storage options (server or email). Backups are compressed (ZIP/TAR) and automatically pruned (max 30 for daily, 12 for weekly/monthly). Also includes an admin page to view, download, and delete saved backup files.
Version: 1.3.8a
Author: John and ChatGPT
License: GPL2
*/

// Set PHP timezone based on WordPress settings.
$tz = get_option('timezone_string');
if ( $tz ) {
	date_default_timezone_set( $tz );
} else {
	date_default_timezone_set( 'America/Los_Angeles' );
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define a directory to store backup files.
 */
define( 'WP_DB_BACKUP_DIR', WP_CONTENT_DIR . '/db-backups/' );
if ( ! file_exists( WP_DB_BACKUP_DIR ) ) {
	mkdir( WP_DB_BACKUP_DIR, 0755, true );
}

/**
 * Early Download Handler
 */
add_action( 'admin_init', 'wp_db_backup_handle_download' );
function wp_db_backup_handle_download() {
	if ( isset( $_GET['action'] ) && $_GET['action'] == 'download' && isset( $_GET['file'] ) ) {
		wp_db_backup_serve_file( $_GET['file'] );
		exit;
	}
}

/**
 * Activation & Deactivation Hooks
 */
function wp_db_backup_scheduler_activate() {
	// Optionally, pre-create the backup directory.
}
register_activation_hook( __FILE__, 'wp_db_backup_scheduler_activate' );

function wp_db_backup_scheduler_deactivate() {
	$jobs = wp_db_get_backup_jobs();
	if ( is_array( $jobs ) ) {
		foreach ( $jobs as $job ) {
			wp_clear_scheduled_hook( 'wp_db_backup_execute', array( $job['id'] ) );
		}
	}
}
register_deactivation_hook( __FILE__, 'wp_db_backup_scheduler_deactivate' );

/**
 * Utility Functions for Managing Backup Jobs
 */
function wp_db_get_backup_jobs() {
	$jobs = get_option( 'wp_db_backup_jobs', array() );
	return is_array( $jobs ) ? $jobs : array();
}

function wp_db_save_backup_jobs( $jobs ) {
	update_option( 'wp_db_backup_jobs', $jobs );
}

function wp_db_generate_job_id() {
	return uniqid( 'job_', true );
}

/**
 * Compute Next Run Timestamp for a Job.
 * Expects $job['backup_time'] in HH:MM format.
 */
function wp_db_compute_next_run_time( $job ) {
	$time_str = $job['backup_time'];
	$now = current_time( 'timestamp' );
	
	// For today's run, combine today's date with the backup time.
	$today_date = date( 'Y-m-d', $now );
	$today_run = strtotime( "$today_date $time_str:00" );
	
	if ( $job['schedule_type'] == 'daily' ) {
		return ( $today_run > $now ) ? $today_run : $today_run + DAY_IN_SECONDS;
		
	} elseif ( $job['schedule_type'] == 'weekly' ) {
		$target_day = strtolower( $job['backup_day'] );
		$weekdays = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
		$today_day = strtolower( date( 'l', $now ) );
		$days_diff = (array_search( $target_day, $weekdays ) - array_search( $today_day, $weekdays ) + 7) % 7;
		if ( $days_diff == 0 && $today_run <= $now ) {
			$days_diff = 7;
		}
		$run_date = strtotime( "+$days_diff days", $now );
		$run_date_str = date( 'Y-m-d', $run_date );
		$run_timestamp = strtotime( "$run_date_str $time_str:00" );
		return $run_timestamp;
		
	} elseif ( $job['schedule_type'] == 'monthly' ) {
		$day = ! empty( $job['backup_date'] ) ? intval( $job['backup_date'] ) : date( 'j', $now );
		$current_month_run = strtotime( date( 'Y-m-', $now ) . sprintf( "%02d", $day ) . " $time_str:00" );
		return ( $current_month_run > $now ) ? $current_month_run : strtotime( "+1 month", $current_month_run );
	}
	return $now + DAY_IN_SECONDS;
}

/**
 * Schedule/Unschedule a Backup Job (WP-Cron)
 *
 * Clears any existing event for the job and forces the next run to be in the future.
 */
function wp_db_schedule_backup_job( $job ) {
	wp_clear_scheduled_hook( 'wp_db_backup_execute', array( $job['id'] ) );
	
	$next_run = wp_db_compute_next_run_time( $job );
	$now = current_time( 'timestamp' );
	
	// If the computed next run is within 30 minutes, bump it by one period.
	if ( $next_run - $now < 1800 ) {
		if ( $job['schedule_type'] == 'daily' ) {
			$next_run += DAY_IN_SECONDS;
		} elseif ( $job['schedule_type'] == 'weekly' ) {
			$next_run += WEEK_IN_SECONDS;
		} elseif ( $job['schedule_type'] == 'monthly' ) {
			$next_run = strtotime('+1 month', $next_run);
		}
	}
	
	// Ensure the next run is at least 5 minutes away.
	if ( $next_run - $now < 300 ) {
		$next_run = $now + 300;
	}
	
	wp_schedule_single_event( $next_run, 'wp_db_backup_execute', array( $job['id'] ) );
}

function wp_db_unschedule_backup_job( $job_id ) {
	wp_clear_scheduled_hook( 'wp_db_backup_execute', array( $job_id ) );
}

/**
 * Backup Job Callback: Run Backup & Re-schedule.
 *
 * Uses a transient to prevent duplicate execution.
 */
add_action( 'wp_db_backup_execute', 'wp_db_backup_execute_callback', 10, 1 );
function wp_db_backup_execute_callback( $job_id ) {
	if ( get_transient( 'wp_db_backup_run_' . $job_id ) ) {
		return;
	}
	set_transient( 'wp_db_backup_run_' . $job_id, true, 300 );
	
	$jobs = wp_db_get_backup_jobs();
	$job = null;
	foreach ( $jobs as $j ) {
		if ( $j['id'] == $job_id ) {
			$job = $j;
			break;
		}
	}
	if ( ! $job ) {
		return;
	}
	wp_db_run_backup_job( $job );
	wp_db_schedule_backup_job( $job );
}

/**
 * Run a Backup Job:
 * - Generate an SQL dump.
 * - Write it to a temporary SQL file.
 * - Compress the dump into a ZIP archive using a double-hyphen delimiter.
 * - Delete the uncompressed SQL file so that only the ZIP remains.
 * - Email the backup if configured.
 */
function wp_db_run_backup_job( $job ) {
	global $wpdb;
	$sql = "";
	$tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
	foreach ( $tables as $table ) {
		$table_name = $table[0];
		$create_table = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_N );
		$sql .= "\n\n" . $create_table[1] . ";\n\n";
		$rows = $wpdb->get_results( "SELECT * FROM `$table_name`", ARRAY_A );
		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $row as $val ) {
				$vals[] = "'" . esc_sql( $val ) . "'";
			}
			$sql .= "INSERT INTO `$table_name` VALUES (" . implode( ',', $vals ) . ");\n";
		}
	}
	
	$timestamp = current_time( 'Y-m-d_H-i-s' );
	// Use double hyphens as delimiters to avoid conflicts with underscores in the job ID.
	$basename = "backup--{$job['id']}--{$job['schedule_type']}--{$timestamp}.sql";
	$temp_sql = WP_DB_BACKUP_DIR . $basename;
	
	// Write the SQL dump to a temporary file.
	file_put_contents( $temp_sql, $sql );
	
	$archive_path = '';
	if ( $job['format'] == 'zip' ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			$archive_path = WP_DB_BACKUP_DIR . $basename . ".zip";
			if ( $zip->open( $archive_path, ZipArchive::CREATE ) === true ) {
				$zip->addFile( $temp_sql, $basename );
				$zip->close();
			}
		} else {
			$archive_path = WP_DB_BACKUP_DIR . $basename . ".zip";
			rename( $temp_sql, $archive_path );
		}
		if ( file_exists( $temp_sql ) ) {
			unlink( $temp_sql );
		}
	} elseif ( $job['format'] == 'tar' ) {
		try {
			$archive_path = WP_DB_BACKUP_DIR . $basename . ".tar";
			$phar = new PharData( $archive_path );
			$phar->addFile( $temp_sql, $basename );
			if ( file_exists( $temp_sql ) ) {
				unlink( $temp_sql );
			}
		} catch ( Exception $e ) {
			$archive_path = $temp_sql;
		}
	} else {
		$archive_path = $temp_sql;
	}
	
	if ( $job['storage'] == 'email' && ! empty( $job['email'] ) ) {
		$to = $job['email'];
		$subject = "Database Backup - {$timestamp}";
		$message = "Attached is your database backup.";
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $to, $subject, $message, $headers, array( $archive_path ) );
	}
	
	if ( $job['storage'] == 'server' ) {
		wp_db_cleanup_backups( $job );
	}
}

/**
 * Clean Up Old Backups for a Job.
 * Limits stored backups to 30 for daily, 12 for weekly, and 12 for monthly.
 */
function wp_db_cleanup_backups( $job ) {
	$max_backups = 0;
	if ( $job['schedule_type'] == 'daily' ) {
		$max_backups = 30;
	} elseif ( $job['schedule_type'] == 'weekly' ) {
		$max_backups = 12;
	} elseif ( $job['schedule_type'] == 'monthly' ) {
		$max_backups = 12;
	}
	$pattern = WP_DB_BACKUP_DIR . "backup--{$job['id']}--{$job['schedule_type']}--*.sql*";
	$files = glob( $pattern );
	if ( $files && count( $files ) > $max_backups ) {
		usort( $files, function( $a, $b ) {
			return filemtime( $a ) - filemtime( $b );
		});
		$excess = count( $files ) - $max_backups;
		for ( $i = 0; $i < $excess; $i++ ) {
			@unlink( $files[$i] );
		}
	}
}

/**
 * ADMIN PAGE: Backup Scheduler Management
 */
function wp_db_backup_scheduler_menu() {
	add_menu_page(
		"DB Backup Scheduler",
		"DB Backup Scheduler",
		"manage_options",
		"wp-db-backup-scheduler",
		"wp_db_backup_scheduler_page",
		"dashicons-backup"
	);
}
add_action( 'admin_menu', 'wp_db_backup_scheduler_menu' );

/**
 * Main Scheduler Page: List and Manage Backup Jobs
 */
function wp_db_backup_scheduler_page() {
	if ( isset( $_GET['action'] ) && $_GET['action'] == 'delete' && isset( $_GET['job'] ) ) {
		$job_id = sanitize_text_field( $_GET['job'] );
		$jobs = wp_db_get_backup_jobs();
		foreach ( $jobs as $i => $job ) {
			if ( $job['id'] == $job_id ) {
				wp_db_unschedule_backup_job( $job_id );
				unset( $jobs[$i] );
				update_option( 'wp_db_backup_jobs', $jobs );
				echo '<div class="updated"><p>Job deleted.</p></div>';
				break;
			}
		}
	}
	
	if ( $_SERVER['REQUEST_METHOD'] == 'POST' && isset( $_POST['job'] ) ) {
		$job_data = $_POST['job'];
		$jobs = wp_db_get_backup_jobs();
		if ( isset( $job_data['id'] ) && ! empty( $job_data['id'] ) ) {
			$job_id = sanitize_text_field( $job_data['id'] );
			foreach ( $jobs as &$job ) {
				if ( $job['id'] == $job_id ) {
					wp_db_unschedule_backup_job( $job_id );
					$job['title'] = sanitize_text_field( $job_data['title'] );
					$job['schedule_type'] = sanitize_text_field( $job_data['schedule_type'] );
					$job['backup_time'] = sanitize_text_field( $job_data['backup_time'] );
					$job['backup_day'] = sanitize_text_field( $job_data['backup_day'] );
					$job['backup_date'] = sanitize_text_field( $job_data['backup_date'] );
					$job['storage'] = sanitize_text_field( $job_data['storage'] );
					$job['format'] = sanitize_text_field( $job_data['format'] );
					$job['email'] = sanitize_email( $job_data['email'] );
					wp_db_schedule_backup_job( $job );
					break;
				}
			}
			unset( $job );
			echo '<div class="updated"><p>Job updated.</p></div>';
		} else {
			$job = array(
				'id'             => wp_db_generate_job_id(),
				'title'          => sanitize_text_field( $job_data['title'] ),
				'schedule_type'  => sanitize_text_field( $job_data['schedule_type'] ),
				'backup_time'    => sanitize_text_field( $job_data['backup_time'] ),
				'backup_day'     => sanitize_text_field( $job_data['backup_day'] ),
				'backup_date'    => sanitize_text_field( $job_data['backup_date'] ),
				'storage'        => sanitize_text_field( $job_data['storage'] ),
				'format'         => sanitize_text_field( $job_data['format'] ),
				'email'          => sanitize_email( $job_data['email'] ),
			);
			$jobs[] = $job;
			// When adding a new job, do not run a backup immediately.
			wp_db_schedule_backup_job( $job );
			echo '<div class="updated"><p>Job added.</p></div>';
		}
		update_option( 'wp_db_backup_jobs', $jobs );
	}
	
	$jobs = wp_db_get_backup_jobs();
	?>
	<div class="wrap">
		<h1>DB Backup Scheduler</h1>
		<h2>Existing Backup Jobs</h2>
		<table class="widefat">
			<thead>
				<tr>
					<th>Title</th>
					<th>Schedule</th>
					<th>Time</th>
					<th>Day/Date</th>
					<th>Storage</th>
					<th>Format</th>
					<th>Email</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $jobs ) ) : ?>
					<tr><td colspan="8">No backup jobs found.</td></tr>
				<?php else : ?>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<td><?php echo esc_html( $job['title'] ); ?></td>
							<td><?php echo esc_html( $job['schedule_type'] ); ?></td>
							<td><?php echo esc_html( $job['backup_time'] ); ?></td>
							<td>
								<?php 
								if ( $job['schedule_type'] == 'weekly' ) {
									echo esc_html( $job['backup_day'] );
								} elseif ( $job['schedule_type'] == 'monthly' ) {
									echo esc_html( $job['backup_date'] );
								} else {
									echo '-';
								}
								?>
							</td>
							<td><?php echo esc_html( $job['storage'] ); ?></td>
							<td><?php echo esc_html( $job['format'] ); ?></td>
							<td><?php echo esc_html( $job['email'] ); ?></td>
							<td>
								<a href="<?php echo admin_url( 'admin.php?page=wp-db-backup-scheduler&edit=' . $job['id'] ); ?>">Edit</a> |
								<a href="<?php echo admin_url( 'admin.php?page=wp-db-backup-scheduler&action=delete&job=' . $job['id'] ); ?>" onclick="return confirm('Are you sure you want to delete this job?');">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		$edit_job = null;
		if ( isset( $_GET['edit'] ) ) {
			$edit_id = sanitize_text_field( $_GET['edit'] );
			foreach ( $jobs as $job ) {
				if ( $job['id'] == $edit_id ) {
					$edit_job = $job;
					break;
				}
			}
		}
		?>
		<h2><?php echo isset( $edit_job ) ? 'Edit Backup Job' : 'Add New Backup Job'; ?></h2>
		<form method="POST">
			<table class="form-table">
				<tr>
					<th>Title</th>
					<td>
						<input type="text" name="job[title]" value="<?php echo isset( $edit_job['title'] ) ? esc_attr( $edit_job['title'] ) : ''; ?>" required>
						<?php if ( isset( $edit_job['id'] ) ) : ?>
							<input type="hidden" name="job[id]" value="<?php echo esc_attr( $edit_job['id'] ); ?>">
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>Schedule Type</th>
					<td>
						<select name="job[schedule_type]" id="schedule_type">
							<option value="daily" <?php selected( isset( $edit_job['schedule_type'] ) ? $edit_job['schedule_type'] : '', 'daily' ); ?>>Daily</option>
							<option value="weekly" <?php selected( isset( $edit_job['schedule_type'] ) ? $edit_job['schedule_type'] : '', 'weekly' ); ?>>Weekly</option>
							<option value="monthly" <?php selected( isset( $edit_job['schedule_type'] ) ? $edit_job['schedule_type'] : '', 'monthly' ); ?>>Monthly</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Backup Time</th>
					<td><input type="time" name="job[backup_time]" value="<?php echo isset( $edit_job['backup_time'] ) ? esc_attr( $edit_job['backup_time'] ) : ''; ?>" required></td>
				</tr>
				<tr id="weekly_options" style="display: none;">
					<th>Backup Day (for Weekly)</th>
					<td>
						<select name="job[backup_day]">
							<option value="Monday" <?php selected( isset( $edit_job['backup_day'] ) ? $edit_job['backup_day'] : '', 'Monday' ); ?>>Monday</option>
							<option value="Tuesday" <?php selected( isset( $edit_job['backup_day'] ) ? $edit_job['backup_day'] : '', 'Tuesday' ); ?>>Tuesday</option>
							<option value="Wednesday" <?php selected( isset( $edit_job['backup_day'] ) ? $edit_job['backup_day'] : '', 'Wednesday' ); ?>>Wednesday</option>
							<option value="Thursday" <?php selected( isset( $edit_job['backup_day'] ) ? $edit_job['backup_day'] : '', 'Thursday' ); ?>>Thursday</option>
							<option value="Friday" <?php selected( isset( $edit_job['backup_day'] ) ? $edit_job['backup_day'] : '', 'Friday' ); ?>>Friday</option>
							<option value="Saturday" <?php selected( isset( $edit_job['backup_day'] ) ? $edit_job['backup_day'] : '', 'Saturday' ); ?>>Saturday</option>
							<option value="Sunday" <?php selected( isset( $edit_job['backup_day'] ) ? $edit_job['backup_day'] : '', 'Sunday' ); ?>>Sunday</option>
						</select>
					</td>
				</tr>
				<tr id="monthly_options" style="display: none;">
					<th>Backup Date (Day of Month for Monthly)</th>
					<td>
						<input type="number" name="job[backup_date]" min="1" max="31" value="<?php echo isset( $edit_job['backup_date'] ) ? esc_attr( $edit_job['backup_date'] ) : ''; ?>">
					</td>
				</tr>
				<tr>
					<th>Storage Option</th>
					<td>
						<select name="job[storage]">
							<option value="server" <?php selected( isset( $edit_job['storage'] ) ? $edit_job['storage'] : '', 'server' ); ?>>Store on Server</option>
							<option value="email" <?php selected( isset( $edit_job['storage'] ) ? $edit_job['storage'] : '', 'email' ); ?>>Email as Attachment</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>File Format</th>
					<td>
						<select name="job[format]">
							<option value="zip" <?php selected( isset( $edit_job['format'] ) ? $edit_job['format'] : '', 'zip' ); ?>>ZIP</option>
							<option value="tar" <?php selected( isset( $edit_job['format'] ) ? $edit_job['format'] : '', 'tar' ); ?>>TAR</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Email Recipient</th>
					<td>
						<input type="email" name="job[email]" value="<?php echo isset( $edit_job['email'] ) ? esc_attr( $edit_job['email'] ) : ''; ?>">
					</td>
				</tr>
			</table>
			<p>
				<input type="submit" value="<?php echo isset( $edit_job ) ? 'Update Job' : 'Add Job'; ?>" class="button-primary">
			</p>
		</form>
	</div>
	<script>
	(function(){
		function toggleFields() {
			var type = document.getElementById('schedule_type').value;
			document.getElementById('weekly_options').style.display = (type === 'weekly') ? 'table-row' : 'none';
			document.getElementById('monthly_options').style.display = (type === 'monthly') ? 'table-row' : 'none';
		}
		document.getElementById('schedule_type').addEventListener('change', toggleFields);
		toggleFields();
	})();
	</script>
	<?php
}

/**
 * ADMIN SUBPAGE: Backup Files Management.
 * Now includes an extra "Configured Storage" column.
 */
function wp_db_backup_files_menu() {
	add_submenu_page(
		'wp-db-backup-scheduler',
		'Backup Files',
		'Backup Files',
		'manage_options',
		'wp-db-backup-files',
		'wp_db_backup_files_page'
	);
}
add_action( 'admin_menu', 'wp_db_backup_files_menu' );

function wp_db_backup_files_page() {
	if ( isset( $_GET['action'] ) && $_GET['action'] == 'delete' && isset( $_GET['file'] ) ) {
		 if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_backup_file_' . $_GET['file'] ) ) {
			 wp_die( 'Security check failed' );
		 }
		 $file = basename( $_GET['file'] );
		 $filepath = WP_DB_BACKUP_DIR . $file;
		 if ( file_exists( $filepath ) ) {
			 unlink( $filepath );
			 echo '<div class="updated"><p>Backup file deleted.</p></div>';
		 }
	}
	?>
	<div class="wrap">
		<h1>Backup Files</h1>
		<h3>Backups are compressed (ZIP/TAR) and automatically pruned (max 30 for daily, 12 for weekly/monthly).</h3>
		<?php
		// Use the new delimiter for parsing the backup filename.
		$files = glob( WP_DB_BACKUP_DIR . "backup--*.sql*" );
		if ( ! $files ) {
			 echo '<p>No backup files found.</p>';
		} else {
			 echo '<table class="widefat fixed"><thead><tr>
					<th>Filename</th>
					<th>Job ID</th>
					<th>Schedule Type</th>
					<th>Configured Storage</th>
					<th>Timestamp</th>
					<th>Created</th>
					<th>Size</th>
					<th>Actions</th>
				   </tr></thead><tbody>';
			 // Get the current job configurations.
			 $jobs = wp_db_get_backup_jobs();
			 foreach ( $files as $file ) {
				  $filename = basename( $file );
				  $parts = explode('--', $filename);
				  $job_id = isset( $parts[1] ) ? $parts[1] : '';
				  $schedule_type = isset( $parts[2] ) ? $parts[2] : '';
				  // The timestamp part is in $parts[3], but we won't need it for storage.
				  // Convert file creation time to local time.
				  $created = date_i18n( 'Y-m-d H:i:s', filectime( $file ) + ( get_option('gmt_offset') * 3600 ) );
				  $size = size_format( filesize( $file ) );
				  
				  // Look up job configuration.
				  $configured_storage = 'Unknown';
				  foreach ( $jobs as $job ) {
					  if ( $job['id'] === $job_id ) {
						  $configured_storage = ucfirst( $job['storage'] );
						  break;
					  }
				  }
				  
				  $download_url = add_query_arg( array(
					  'page'   => 'wp-db-backup-files',
					  'action' => 'download',
					  'file'   => $filename,
				  ), admin_url('admin.php') );
				  $delete_url = add_query_arg( array(
					  'page'    => 'wp-db-backup-files',
					  'action'  => 'delete',
					  'file'    => $filename,
					  '_wpnonce'=> wp_create_nonce('delete_backup_file_' . $filename)
				  ), admin_url('admin.php') );
				  
				  // For display, try to extract a "clean" timestamp from $parts[3].
				  $timestamp_clean = isset($parts[3]) ? strtok($parts[3], '.') : '';
				  
				  echo '<tr>
						  <td>' . esc_html( $filename ) . '</td>
						  <td>' . esc_html( $job_id ) . '</td>
						  <td>' . esc_html( $schedule_type ) . '</td>
						  <td>' . esc_html( $configured_storage ) . '</td>
						  <td>' . esc_html( $timestamp_clean ) . '</td>
						  <td>' . esc_html( $created ) . '</td>
						  <td>' . esc_html( $size ) . '</td>
						  <td>
							 <a href="' . esc_url( $download_url ) . '" download>Download</a> | 
							 <a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Are you sure you want to delete this file?\');">Delete</a>
						  </td>
						</tr>';
			 }
			 echo '</tbody></table>';
		}
		?>
	</div>
	<?php
}

/**
 * Serve a Backup File for Download.
 */
function wp_db_backup_serve_file( $filename ) {
	$decoded_filename = urldecode($filename);
	if ( ! preg_match('/^[a-zA-Z0-9_\-\.]+$/', $decoded_filename) ) {
		wp_die('Invalid file name.');
	}
	
	$file = WP_DB_BACKUP_DIR . $decoded_filename;
	if ( ! file_exists($file) ) {
		wp_die('File not found.');
	}
	
	while (ob_get_level()) {
		ob_end_clean();
	}
	
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="'.basename($file).'"');
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Length: ' . filesize($file));
	header('X-Content-Type-Options: nosniff');
	
	$fp = fopen($file, 'rb');
	if ($fp !== false) {
		fpassthru($fp);
		fclose($fp);
	}
	exit;
}
