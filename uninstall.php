<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

if (class_exists('ArDesignReportingProductsModule')) {
	ArDesignReportingProductsModule::uninstall();
	exit;
}

global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ard_product_stock_history');

$hook = 'ard_reporting_products_capture_stock_daily';
$timestamp = wp_next_scheduled($hook);
while (false !== $timestamp) {
	wp_unschedule_event($timestamp, $hook);
	$timestamp = wp_next_scheduled($hook);
}
