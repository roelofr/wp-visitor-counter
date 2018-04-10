<?php
declare(strict_types=1);
/*
Plugin Name: Visitor Counter Shortcode
Description: Displays the current number of visitors on the site
Version: 0.1
Author: Roelof
Author URI: https://www.github.com/roelofr/
License: GPL-3
*/

namespace Roelofr\SimpleVisitorCounter;

use WP_Widget;

/**
 * The plugin, which is used to run everything from the code.
 *
 * @author Roelof <github@roelof.io>
 * @license GPL-3
 */
class Plugin
{
    /**
     * Table name, without WordPress prefix
     */
    const TABLE_NAME = 'rsvc_visitors';

    /**
     * Name of the WordPress task
     */
    const WP_EVENT_NAME = 'roelofr-svc-cleanup';

    /**
     * Shortcode that shows recent visitors
     */
    const WP_SHORTCODE = 'visitor-count';

    /**
     * Query used to count visitors, defaults to last 30 minutes
     */
    const QUERY_COUNT = <<<SQL
SELECT COUNT(`ip`) AS `count`
FROM `%s`
WHERE `date` > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY `ip`
SQL;

    /**
     * Query used to clean up statistic table
     */
    const QUERY_CLEAN = 'DELETE FROM `%s` WHERE `date` < DATE_SUB(NOW(), INTERVAL 60 MINUTE);';

    /**
     * Query to create the statistics table
     */
    const QUERY_INSTALL = <<<SQL
CREATE TABLE `%s` (
    id int(11) NOT NULL AUTO_INCREMENT COMMENT "Item ID",
    date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT "Time of visit",
    ip char(100) NOT NULL COMMENT "IP address of visit",
    PRIMARY KEY  (id)
) %s;
SQL;

    /**
     * Query to delete the statistics table
     */
    const QUERY_UNINSTALL = 'DROP TABLE `%s`';

    /**
     * Calculated name of the table
     * @var string
     */
    protected $tableName;

    /**
     * WordPress database connection
     * @var \wpdb
     */
    protected $db;

    /**
     * Register Wordpress bindings
     */
    public static function hook() : void
    {
        $instance = new self;

        // Installation hooks
        register_activation_hook(__FILE__, [$instance, 'install']);
        register_deactivation_hook(__FILE__, [$instance, 'uninstall']);

        // Page load hook
        add_action('init', [$instance, 'hit']);

        // Cronjob hook
        add_action(self::WP_EVENT_NAME, [$instance, 'cronjob']);

        // Shortcode
        add_shortcode(self::WP_SHORTCODE, [$instance, 'shortcode']);
    }

    /**
     * Save some time by linking to functions.
     */
    public function __construct()
    {
        // Get WPDB
        global $wpdb;

        // Save it to variable, by reference
        $this->db = &$wpdb;

        // Build table name using prefix
        $this->tableName = $this->db->prefix . self::TABLE_NAME;
    }

    /**
     * Install the plugin, creating the table used for recording hits
     */
    public function install() : void
    {
        // Create table
        $query = sprintf(
            self::QUERY_INSTALL,
            $this->tableName,
            $this->db->get_charset_collate()
        );

        // Load upgrade script
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Perform DB update
        dbDelta($query);

        // Create cronjob entry
        if (!wp_next_scheduled(self::WP_EVENT_NAME)) {
            wp_schedule_event(time(), 'daily', self::WP_EVENT_NAME, []);
        }
    }

    /**
     * Install the plugin, creating the table used for recording hits
     */
    public function uninstall() : void
    {
        // Drop table
        $this->db->query(sprintf(
            self::QUERY_UNINSTALL,
            $this->tableName
        ));

        // Remove cronjob entry
        if (wp_next_scheduled(self::WP_EVENT_NAME)) {
            wp_clear_scheduled_hook(self::WP_EVENT_NAME);
        }
    }

    /**
     * Register the hit in the database
     */
    public function hit() : void
    {
        // Insert IP address
        $this->db->insert(
            $this->tableName,
            ['ip' => $this->getIp()],
            ['%s']
        );
    }

    /**
     * Returns the user's IP
     *
     * @return string user IP
     */
    protected function getIp() : string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '::1';
    }

    /**
     * Returns the current number of visitors, as plain text.
     *
     * @return string
     */
    public function shortcode() : string
    {
        // Get count
        $result = $this->db->query(sprintf(self::QUERY_COUNT, $this->tableName));
        return $result;
    }

    /**
     * Purge outdated entries
     */
    public function cronjob() : void
    {
        $this->db->query(sprintf(
            self::QUERY_CLEAN,
            $this->tableName
        ));
    }
}
