<?php
declare(strict_types=1);
/*
Plugin Name: Visitor Counter Shortcode
Description: Displays the current number of visitors on the site
Version: 1.1
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

    const QUERY_RANGE_OPTIONS = [
        'now' => '15 MINUTE',
        'hour' => '1 HOUR',
        'day' => '1 DAY',
        'week' => '1 WEEK',
        'month' => '1 MONTH',
    ];

    /**
     * Query used to count visitors in a given range
     */
    const QUERY_COUNT = <<<SQL
SELECT COUNT(`ip`) AS `count`
FROM `%s`
WHERE `date` > DATE_SUB(NOW(), INTERVAL %s)
GROUP BY `ip`
SQL;

    /**
     * Query used to clean up statistic table
     */
    const QUERY_CLEAN = 'DELETE FROM `%s` WHERE `date` < DATE_SUB(NOW(), INTERVAL 1 MONTH);';

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

    const ERROR_HTML = <<<HTML
<div class="vcs_error_dialog">
    <p><strong>Visitor Counter error</strong></p>
    <p>
        The plugin failed to render the live count, since the requested scope
        (<em>%s</em>) is not available.
    </p>
    <p>
        Please remove the scope or pick one of: %s
    </p>
</div>
<link rel="stylesheet" href="%s" defer />
HTML;

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
     * @param  array  $attributes Shortcode attributes
     * @return string
     */
    public function shortcode($attributes) : string
    {
        // Check for a scope
        if (!empty($attributes['scope'])) {
            $scope = (string) $attributes['scope'];
        } else {
            $scope = 'now';
        }

        // Validate the given scope
        if (!array_key_exists($scope, self::QUERY_RANGE_OPTIONS)) {
            return $this->renderError($scope);
        }

        // Get count, using the given scope1
        $result = $this->db->get_results(sprintf(
            self::QUERY_COUNT,
            $this->tableName,
            self::QUERY_RANGE_OPTIONS[$scope]
        ));

        // If there are no results (which is wierd), set count to zero.
        if (empty($result) || !is_array($result)) {
            $visitorCount = 0;
        } else {
            $visitorCount = array_shift($result)->count;
        }
        return number_format_i18n($visitorCount, 0);
    }

    /**
     * Renders a rich HTML error in case the counter is misconfigured
     *
     * @param  string|null $scope Scope that the user requested
     * @return string             HTML error
     */
    protected function renderError(string $scope = null) : string
    {
        return sprintf(
            self::ERROR_HTML,
            $scope,
            implode(', ', array_keys(self::QUERY_RANGE_OPTIONS)),
            plugins_url('/assets/error.css', __FILE__)
        );
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

Plugin::hook();
