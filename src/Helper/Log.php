<?php
namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

class Log {
    public static function log($content) {
        $content .= PHP_EOL;  // Add a new line to the content

        // Append the content to the file
        if (file_put_contents( dirname(__DIR__, 2).'/debug.log', $content, FILE_APPEND | LOCK_EX) === false) {
            // Handle error if file write fails
            echo "Error appending content to the file: " . plugin_dir_path( __FILE__ ) . '/debug.log';
        }
    }
}