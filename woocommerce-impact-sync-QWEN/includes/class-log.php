<?php
namespace WooCommerceImpactSync;

class Log {
    private static $log_file = 'woocommerce-impact-sync.log';
    private static $log_limit = 5 * 1024 * 1024; // 5MB limit per log file

    /**
     * Write a message to the log file.
     *
     * @param string $message The message to log.
     * @param string $level The log level (e.g., 'info', 'error', 'warning').
     */
    public static function write($message, $level = 'info') {
        $timestamp = current_time('mysql');
        $log_message = "[$timestamp][$level] $message\n";

        // Get the log file path
        $log_path = self::get_log_file_path();

        // Rotate logs if the file exceeds the size limit
        if (file_exists($log_path) && filesize($log_path) > self::$log_limit) {
            self::rotate_logs();
        }

        // Append the log message to the file
        file_put_contents($log_path, $log_message, FILE_APPEND);
    }

    /**
     * Rotate log files by archiving the current log and creating a new one.
     */
    private static function rotate_logs() {
        $log_path = self::get_log_file_path();
        $archive_path = str_replace('.log', '-' . date('Y-m-d-H-i-s') . '.log', $log_path);

        // Rename the current log file to an archive file
        if (file_exists($log_path)) {
            rename($log_path, $archive_path);
        }

        // Optionally delete old log files older than 30 days
        $log_dir = dirname($log_path);
        foreach (glob($log_dir . '/woocommerce-impact-sync-*.log') as $file) {
            if (filemtime($file) < strtotime('-30 days')) {
                unlink($file);
            }
        }
    }

    /**
     * Get the full path to the log file.
     *
     * @return string The log file path.
     */
    private static function get_log_file_path() {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . self::$log_file;
    }

    /**
     * Clear all log files.
     */
    public static function clear_logs() {
        $log_path = self::get_log_file_path();
        $log_dir = dirname($log_path);

        // Delete the main log file
        if (file_exists($log_path)) {
            unlink($log_path);
        }

        // Delete all archived log files
        foreach (glob($log_dir . '/woocommerce-impact-sync-*.log') as $file) {
            unlink($file);
        }
    }
}