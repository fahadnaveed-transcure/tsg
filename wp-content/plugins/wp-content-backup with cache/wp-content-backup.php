<?php
/**
 * Plugin Name: Transcure WP Content Backup for Static Site
 * Description: Backs up wp-content to a static ZIP file in the WordPress root, excluding cache directories. Logs cache sizes, backup source, ZIP size, and uses separators. Logs only root cache directories for skips.
 * Version:     1.6
 * Author:      Transcure
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---- Constants ----
define('WP_BACKUP_ZIP_PATH', ABSPATH . 'wp-content.zip');
define('WP_BACKUP_LOG_PATH', ABSPATH . 'backup.log');

// ---- Utility Functions ----

/**
 * Logs a message to backup.log and stores it for display, using PKT timezone.
 *
 * @param string $message Message to log.
 * @return array Current logs.
 */
function wp_backup_log($message) {
    static $logs = [];
    // Use Asia/Karachi (PKT, UTC+5) for timestamps
    $log_entry = '[' . date('Y-m-d H:i:s', time() + 5 * 3600) . ' PKT] ' . $message . "\n";
    $logs[] = $log_entry;

    try {
        file_put_contents(WP_BACKUP_LOG_PATH, $log_entry, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log('Backup Log Error: ' . $e->getMessage());
    }

    return $logs;
}

/**
 * Calculates the total size of a directory recursively.
 *
 * @param string $dir Directory path.
 * @return int Size in bytes, or 0 if directory doesn't exist.
 */
function wp_backup_get_dir_size($dir) {
    if (!is_dir($dir)) {
        return 0;
    }

    $size = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }

    return $size;
}

/**
 * Converts bytes to human-readable format.
 *
 * @param int $bytes Size in bytes.
 * @return string Human-readable size (e.g., '12.5 MB').
 */
function wp_backup_format_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' bytes';
}

/**
 * Refreshes log file on Sundays, clearing all entries.
 */
function wp_backup_refresh_log() {
    if (date('w') !== '0' || !file_exists(WP_BACKUP_LOG_PATH)) {
        return;
    }

    try {
        file_put_contents(WP_BACKUP_LOG_PATH, '');
        wp_backup_log('Log refreshed on Sunday');
    } catch (Exception $e) {
        error_log('Log Refresh Error: ' . $e->getMessage());
    }
}

// ---- Plugin Lifecycle ----

/**
 * Schedules log refresh on activation.
 */
register_activation_hook(__FILE__, 'wp_backup_activate');
function wp_backup_activate() {
    if (!wp_next_scheduled('wp_backup_log_refresh')) {
        wp_schedule_event(strtotime('next Sunday'), 'weekly', 'wp_backup_log_refresh');
    }
}

/**
 * Clears scheduled events on deactivation.
 */
register_deactivation_hook(__FILE__, 'wp_backup_deactivate');
function wp_backup_deactivate() {
    wp_clear_scheduled_hook('wp_backup_log_refresh');
}

// ---- Backup Logic ----

/**
 * Backs up wp-content to wp-content.zip in the WordPress root.
 *
 * @param string $source Source of the backup (Admin Panel, REST API, Web Trigger).
 * @return WP_REST_Response|WP_Error Response or error.
 */
function wp_fullsite_backup_run($source = 'Unknown') {
    wp_backup_refresh_log();
    wp_backup_log("Starting backup (Source: $source)");

    // Calculate and log cache sizes
    $cache_dirs = [
        'cache' => WP_CONTENT_DIR . '/cache',
        'uploads/cache' => WP_CONTENT_DIR . '/uploads/cache',
        'plugins/w3-total-cache' => WP_CONTENT_DIR . '/plugins/w3-total-cache',
        'plugins/wp-super-cache' => WP_CONTENT_DIR . '/plugins/wp-super-cache',
    ];
    foreach ($cache_dirs as $name => $path) {
        $size = wp_backup_get_dir_size($path);
        if ($size > 0) {
            wp_backup_log("Cache directory wp-content/$name: " . wp_backup_format_size($size));
        }
    }

    // Verify root permissions
    if (!is_writable(ABSPATH)) {
        wp_backup_log('Root not writable');
        return new WP_Error('root_not_writable', 'Cannot write to WordPress root', ['status' => 500]);
    }

    // Clean up old ZIP
    if (file_exists(WP_BACKUP_ZIP_PATH)) {
        if (unlink(WP_BACKUP_ZIP_PATH)) {
            wp_backup_log('Deleted old ZIP: ' . WP_BACKUP_ZIP_PATH);
        } else {
            wp_backup_log('Failed to delete old ZIP');
            return new WP_Error('zip_cleanup_failed', 'Cannot delete old ZIP', ['status' => 500]);
        }
    }

    // Create ZIP
    $zip = new ZipArchive();
    if (true !== $zip->open(WP_BACKUP_ZIP_PATH, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        wp_backup_log('Failed to create ZIP');
        return new WP_Error('zip_failed', 'Could not create ZIP archive', ['status' => 500]);
    }

    // Add wp-content files
    $root = realpath(WP_CONTENT_DIR);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
	// Define file extensions to exclude
    $excluded_extensions = ['log', 'tmp', 'cache', 'bak', 'wpress'];
	
    $file_count = 0;
    $root_cache_dirs = ['cache', 'uploads/cache', 'plugins/w3-total-cache', 'plugins/wp-super-cache'];
    foreach ($it as $file) {
        $path = $file->getRealPath();
        $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        $relative = str_replace('\\', '/', $relative);

        if ($file->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
			// Check file extension
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			
            if (!in_array($extension, $excluded_extensions)) {
                $zip->addFile($path, $relative);
                $file_count++;
            } else {
                wp_backup_log("Skipped file with excluded extension: $relative");
            }
        }
    }
    wp_backup_log('Added ' . $file_count . ' files');

    // Close ZIP
    if ($zip->close()) {
        $zip_size = filesize(WP_BACKUP_ZIP_PATH);
        wp_backup_log('ZIP created: ' . WP_BACKUP_ZIP_PATH . ' (' . wp_backup_format_size($zip_size) . ')');
    } else {
        wp_backup_log('Failed to close ZIP');
        return new WP_Error('zip_close_failed', 'Could not close ZIP archive', ['status' => 500]);
    }

    // Verify ZIP
    if (!file_exists(WP_BACKUP_ZIP_PATH) || filesize(WP_BACKUP_ZIP_PATH) === 0) {
        wp_backup_log('ZIP missing or empty');
        return new WP_Error('zip_invalid', 'ZIP file invalid', ['status' => 500]);
    }

    // Prepare response
    $size = size_format(filesize(WP_BACKUP_ZIP_PATH), 1);
    $download = str_replace(ABSPATH, home_url('/'), WP_BACKUP_ZIP_PATH);
    $response = [
        'message' => 'Backup complete.',
        'file_url' => $download,
        'size' => $size,
    ];

    // Add log separator
    wp_backup_log('Backup completed');
    file_put_contents(WP_BACKUP_LOG_PATH, "\n\n\n" . str_repeat('-', 30) . "\n", FILE_APPEND | LOCK_EX);

    return rest_ensure_response($response);
}

// ---- Admin Menu ----

/**
 * Adds admin menu for backup.
 */
add_action('admin_menu', function() {
    add_menu_page(
        'WP Content for Static HTML',
        'WP Content for Static HTML',
        'manage_options',
        'wp-content-static-html',
        function() {
            echo '<div class="wrap"><h1>WP Content for Static HTML</h1>';
            echo '<form method="post"><button name="do_backup" class="button button-primary">Run Backup</button></form>';
            if (isset($_POST['do_backup'])) {
                $res = wp_fullsite_backup_run('Admin Panel');
                if (is_wp_error($res)) {
                    echo '<div class="notice notice-error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>Backup created: <a href="' 
                         . esc_url($res->get_data()['file_url']) . '">' 
                         . esc_html($res->get_data()['file_url']) . '</a> (' 
                         . esc_html($res->get_data()['size']) . ')</p></div>';
                }
            }
            echo '</div>';
        }
    );
});

// ---- REST API ----

/**
 * Registers REST API endpoint for backup.
 */
add_action('rest_api_init', function() {
    register_rest_route('wp-backup/v1', '/run', [
        'methods' => 'POST',
        'callback' => function() {
            return wp_fullsite_backup_run('REST API');
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ]);
});

// ---- Web Trigger ----

/**
 * Handles ?trigger_export=1 to run backup.
 */
add_action('init', 'wp_backup_trigger_export', 1);
function wp_backup_trigger_export() {
    if (!isset($_GET['trigger_export']) || $_GET['trigger_export'] != '1') {
        return;
    }

    wp_backup_log('Web trigger activated');
    wp_backup_log('Query parameters: ' . print_r($_GET, true));
    wp_backup_log('Current user ID: ' . get_current_user_id());

    // Uncomment in production
    /*
    if (!current_user_can('manage_options')) {
        wp_backup_log('Unauthorized access attempt');
        wp_die('Unauthorized access. You must be an administrator.');
    }
    */

    nocache_headers();
    try {
        $res = wp_fullsite_backup_run('Web Trigger');
        if (is_wp_error($res)) {
            wp_backup_log('Backup failed: ' . $res->get_error_message());
            // Add separator even on failure
            file_put_contents(WP_BACKUP_LOG_PATH, "\n\n\n" . str_repeat('-', 30) . "\n", FILE_APPEND | LOCK_EX);
            wp_die('Backup failed: ' . esc_html($res->get_error_message()));
        }

        $url = esc_url($res->get_data()['file_url']);
        $file = esc_html(basename($res->get_data()['file_url']));
        $logs = wp_backup_log('Backup completed');
        $log_output = implode('', $logs) . "\n\n\n" . str_repeat('-', 30) . "\n";
        wp_die("<pre>" . htmlspecialchars($log_output) . "</pre>Backup completed.<br>Download: <a href='$url'>$file</a>");
    } catch (Exception $e) {
        wp_backup_log('Trigger error: ' . $e->getMessage());
        // Add separator even on failure
        file_put_contents(WP_BACKUP_LOG_PATH, "\n\n\n" . str_repeat('-', 30) . "\n", FILE_APPEND | LOCK_EX);
        wp_die('Trigger error: ' . esc_html($e->getMessage()));
    }
}

// ---- Log Refresh Schedule ----

/**
 * Schedules weekly log refresh.
 */
add_action('wp_backup_log_refresh', 'wp_backup_refresh_log');