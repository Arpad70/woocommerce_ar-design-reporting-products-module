<?php
/**
 * Plugin Name: AR Design Reporting - Products Module
 * Description: Samostatný produktový modul pre dashboard AR Design Reporting (predaje produktov, sklad, história skladu, exporty XLSX).
 * Version: 0.3.30
 * Author: AR Design
 * Text Domain: ar-design-reporting-products-module
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Update URI: https://github.com/Arpad70/woocommerce_ar-design-reporting-products-module
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('ARD_REPORTING_PRODUCTS_MODULE_VERSION', '0.3.30');
define('ARD_REPORTING_PRODUCTS_MODULE_BASENAME', plugin_basename(__FILE__));
define('ARD_REPORTING_PRODUCTS_MODULE_PATH', plugin_dir_path(__FILE__));
define('ARD_REPORTING_PRODUCTS_MODULE_REPOSITORY', 'Arpad70/woocommerce_ar-design-reporting-products-module');
define('ARD_REPORTING_PRODUCTS_MODULE_TEXT_DOMAIN', 'ar-design-reporting-products-module');
define('ARD_REPORTING_PRODUCTS_MODULE_SLUG', 'ar-design-reporting-products-module');
define('ARD_REPORTING_PRODUCTS_MODULE_PLUGIN_NAME', 'AR Design Reporting - Products Module');
define('ARD_REPORTING_PRODUCTS_MODULE_DESCRIPTION', 'Samostatný produktový modul pre dashboard AR Design Reporting (predaje produktov, sklad, história skladu, exporty XLSX).');
define('ARD_REPORTING_PRODUCTS_MODULE_ROLLBACK_MESSAGE', 'Aktualizácia AR Design Reporting - Products Module zlyhala. Predchádzajúca verzia bola automaticky obnovená zo zálohy.');

$ardReportingModuleBootstrapConfig = array(
	'version' => ARD_REPORTING_PRODUCTS_MODULE_VERSION,
	'basename' => ARD_REPORTING_PRODUCTS_MODULE_BASENAME,
	'path' => ARD_REPORTING_PRODUCTS_MODULE_PATH,
	'repository' => ARD_REPORTING_PRODUCTS_MODULE_REPOSITORY,
	'slug' => ARD_REPORTING_PRODUCTS_MODULE_SLUG,
	'plugin_name' => ARD_REPORTING_PRODUCTS_MODULE_PLUGIN_NAME,
	'text_domain' => ARD_REPORTING_PRODUCTS_MODULE_TEXT_DOMAIN,
	'description' => ARD_REPORTING_PRODUCTS_MODULE_DESCRIPTION,
	'rollback_message' => ARD_REPORTING_PRODUCTS_MODULE_ROLLBACK_MESSAGE,
	'register_updater' => false,
);

require_once ARD_REPORTING_PRODUCTS_MODULE_PATH . 'bootstrap/runtime-skeleton.php';

final class ArDesignReportingProductsModule
{
	private const CRON_HOOK = 'ard_reporting_products_capture_stock_daily';

	/** @var self|null */
	private static $instance = null;

	private bool $assets_printed = false;

	public static function init(): self
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct()
	{
		add_filter('ard_reporting_dashboard_sections', array($this, 'registerDashboardSection'), 20, 2);
		add_action('admin_post_ard_export_product_top_xlsx', array($this, 'handleExportProductTopXlsx'));
		add_action('admin_post_ard_export_product_stock_history_xlsx', array($this, 'handleExportProductStockHistoryXlsx'));
		add_action(self::CRON_HOOK, array($this, 'captureStockSnapshot'));
	}

	/**
	 * @param array<int, array<string, mixed>> $sections
	 * @param array<string, mixed> $context
	 * @return array<int, array<string, mixed>>
	 */
	public function registerDashboardSection(array $sections, array $context = array()): array
	{
		$sections[] = array(
			'id' => 'products_reporting',
			'title' => __('Produktový reporting', 'ar-design-reporting-products-module'),
			'slot' => 'primary',
			'dashboard_tab' => 'business_performance',
			'priority' => 20,
			'capability' => 'manage_woocommerce',
			'render_callback' => array($this, 'renderDashboardSection'),
			'supports_filters' => true,
			'supports_compare_filters' => false,
			'wrapper_class' => 'ard-ext-card ard-ext-card--products ard-ext-card--table',
			'show_title' => true,
		);

		return $sections;
	}

	public static function activate(): void
	{
		self::createSchema();
		self::ensureScheduled();
		self::init()->captureStockSnapshot();
	}

	public static function deactivate(): void
	{
		self::unscheduleAll();
	}

	public static function uninstall(): void
	{
		global $wpdb;

		$table = $wpdb->prefix . 'ard_product_stock_history';
		$wpdb->query("DROP TABLE IF EXISTS {$table}");
		self::unscheduleAll();
	}

	public function renderDashboardSection(array $context = array()): void
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			return;
		}

		$filters = isset($context['filters']) && is_array($context['filters'])
			? $this->normalizeFilters($context['filters'])
			: $this->normalizeFilters($_GET);
		$query_args = $this->buildDashboardQueryArgs($filters, $context);

		$selected_product_id = isset($context['selected_product_id']) ? absint((int) $context['selected_product_id']) : 0;
		$this->ensureStockHistorySeeded();
		$top_sellers = $this->getTopSellingProducts($filters, 20);
		$top_stock = $this->getTopStockProducts($filters, 20);
		$sales_series = $this->getDailySalesSeries($filters, 90, $selected_product_id);
		$stock_history = $selected_product_id > 0
			? $this->getProductStockHistory($selected_product_id, (string) ($filters['date_from'] ?? ''), (string) ($filters['date_to'] ?? ''), 365)
			: array();
		$selected_product_name = $selected_product_id > 0 ? $this->resolveProductName($selected_product_id) : '';
		$selected_product = $selected_product_id > 0 && function_exists('wc_get_product') ? wc_get_product($selected_product_id) : null;
		$selected_product_label = $selected_product_name !== ''
			? $selected_product_name
			: ($selected_product_id > 0 ? sprintf(__('Produkt #%d', 'ar-design-reporting-products-module'), $selected_product_id) : '');
		$selected_product_stock_summary = '';
		if ($selected_product instanceof \WC_Product) {
			$stock_quantity = $selected_product->get_stock_quantity();
			$selected_product_stock_summary = sprintf(
				__('Aktuálny sklad: %s', 'ar-design-reporting-products-module'),
				$this->formatProductStock($stock_quantity !== null ? (string) $stock_quantity : '', (string) $selected_product->get_stock_status())
			);
		}

		$admin_url = $this->getReportingAdminUrl();

		echo '<p class="ard-prm-intro">' . esc_html__('Najpredávanejšie produkty, zásoby, historický sklad a exporty v samostatnom module.', 'ar-design-reporting-products-module') . '</p>';

		echo '<div class="ard-prm-grid">';
		echo '<div>';
		echo '<h3 class="ard-heading-reset">' . esc_html__('Najpredávanejšie produkty', 'ar-design-reporting-products-module') . '</h3>';
		echo '<div class="ard-prm-table-wrap">';
		echo '<table class="widefat striped ard-table ard-prm-table" data-ard-prm-paginated-table="top-sellers">';
		echo '<thead><tr><th>' . esc_html__('Produkt', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('SKU', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Predané ks', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Obrat', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Objednávky', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Sklad teraz', 'ar-design-reporting-products-module') . '</th></tr></thead>';
		echo '<tbody>';
		if (empty($top_sellers)) {
			echo '<tr><td colspan="6">' . esc_html__('Za zvolené obdobie nie sú dostupné predaje produktov.', 'ar-design-reporting-products-module') . '</td></tr>';
		} else {
			foreach ($top_sellers as $row) {
				$product_id = (int) ($row['product_id'] ?? 0);
				$product_name = (string) ($row['product_name'] ?? '');
				$product_link = $this->buildProductDrilldownUrl($query_args, $product_id);
				echo '<tr>';
				echo '<td>' . ($product_id > 0 ? '<a href="' . esc_url($product_link) . '" data-ard-prm-product-link="1">' . esc_html($product_name) . '</a>' : esc_html($product_name)) . '</td>';
				echo '<td>' . esc_html((string) ($row['sku'] ?? '')) . '</td>';
				echo '<td>' . esc_html(number_format((float) ($row['qty_sold'] ?? 0), 2, ',', ' ')) . '</td>';
				echo '<td>' . esc_html(number_format((float) ($row['gross_revenue'] ?? 0), 2, ',', ' ') . ' ' . $this->getStoreCurrencySymbol()) . '</td>';
				echo '<td>' . esc_html((string) ((int) ($row['orders_count'] ?? 0))) . '</td>';
				echo '<td>' . esc_html($this->formatProductStock((string) ($row['stock_quantity'] ?? ''), (string) ($row['stock_status'] ?? ''))) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table></div>';
		echo '<div class="ard-prm-pagination" data-ard-prm-pagination="top-sellers" aria-label="' . esc_attr__('Stránkovanie top predajov produktov', 'ar-design-reporting-products-module') . '">';
		echo '<button type="button" class="button" data-ard-prm-page-action="prev">' . esc_html__('Predchádzajúca', 'ar-design-reporting-products-module') . '</button>';
		echo '<span class="ard-prm-page-info" data-ard-prm-page-info></span>';
		echo '<button type="button" class="button" data-ard-prm-page-action="next">' . esc_html__('Ďalšia', 'ar-design-reporting-products-module') . '</button>';
		echo '<label class="ard-prm-page-size"><span>' . esc_html__('Na stránku', 'ar-design-reporting-products-module') . ':</span><select data-ard-prm-page-size><option value="5" selected>5</option><option value="10">10</option><option value="20">20</option><option value="50">50</option></select></label>';
		echo '</div></div>';

		echo '<div>';
		echo '<h3 class="ard-heading-reset">' . esc_html__('Najviac produktov skladom', 'ar-design-reporting-products-module') . '</h3>';
		echo '<div class="ard-prm-table-wrap">';
		echo '<table class="widefat striped ard-table ard-prm-table" data-ard-prm-paginated-table="top-stock">';
		echo '<thead><tr><th>' . esc_html__('Produkt', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('SKU', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Sklad', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Predané ks v období', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Predalo sa?', 'ar-design-reporting-products-module') . '</th></tr></thead>';
		echo '<tbody>';
		if (empty($top_stock)) {
			echo '<tr><td colspan="5">' . esc_html__('Produkty so skladom nie sú dostupné.', 'ar-design-reporting-products-module') . '</td></tr>';
		} else {
			foreach ($top_stock as $row) {
				$product_id = (int) ($row['product_id'] ?? 0);
				$product_name = (string) ($row['product_name'] ?? '');
				$product_link = $this->buildProductDrilldownUrl($query_args, $product_id);
				echo '<tr>';
				echo '<td>' . ($product_id > 0 ? '<a href="' . esc_url($product_link) . '" data-ard-prm-product-link="1">' . esc_html($product_name) . '</a>' : esc_html($product_name)) . '</td>';
				echo '<td>' . esc_html((string) ($row['sku'] ?? '')) . '</td>';
				echo '<td>' . esc_html($this->formatProductStock((string) ($row['stock_quantity'] ?? ''), (string) ($row['stock_status'] ?? ''))) . '</td>';
				echo '<td>' . esc_html(number_format((float) ($row['qty_sold'] ?? 0), 2, ',', ' ')) . '</td>';
				echo '<td>' . esc_html(((int) ($row['was_sold_in_period'] ?? 0) > 0) ? __('Ano', 'ar-design-reporting-products-module') : __('Ne', 'ar-design-reporting-products-module')) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table></div>';
		echo '<div class="ard-prm-pagination" data-ard-prm-pagination="top-stock" aria-label="' . esc_attr__('Stránkovanie top skladových produktov', 'ar-design-reporting-products-module') . '">';
		echo '<button type="button" class="button" data-ard-prm-page-action="prev">' . esc_html__('Predchádzajúca', 'ar-design-reporting-products-module') . '</button>';
		echo '<span class="ard-prm-page-info" data-ard-prm-page-info></span>';
		echo '<button type="button" class="button" data-ard-prm-page-action="next">' . esc_html__('Ďalšia', 'ar-design-reporting-products-module') . '</button>';
		echo '<label class="ard-prm-page-size"><span>' . esc_html__('Na stránku', 'ar-design-reporting-products-module') . ':</span><select data-ard-prm-page-size><option value="5" selected>5</option><option value="10">10</option><option value="20">20</option><option value="50">50</option></select></label>';
		echo '</div></div>';
		echo '</div>';

		echo '<div class="ard-prm-chart-grid">';
		echo '<div class="ard-ext-card ard-ext-card--chart ard-prm-chart-card"><h3>' . esc_html($selected_product_id > 0 ? sprintf(__('Predaje produktu v čase: %s', 'ar-design-reporting-products-module'), $selected_product_label) : __('Predaje produktov v čase', 'ar-design-reporting-products-module')) . '</h3><canvas id="ard-prm-sales-chart" height="180"></canvas></div>';
		echo '<div class="ard-ext-card ard-ext-card--chart ard-prm-chart-card"><h3>' . esc_html__('Historický sklad produktu', 'ar-design-reporting-products-module') . '</h3>';
		if ($selected_product_stock_summary !== '') {
			echo '<p class="ard-prm-stock-summary">' . esc_html($selected_product_stock_summary) . '</p>';
		}
		echo '<canvas id="ard-prm-stock-chart" height="180"></canvas></div>';
		echo '</div>';

		echo '<h3 id="ard-prm-drilldown" class="ard-subsection-heading">' . esc_html__('Drill-down produktu', 'ar-design-reporting-products-module') . '</h3>';
		echo '<form method="get" action="' . esc_url($admin_url) . '" class="ard-card-form ard-card-form--compact ard-card-form--spaced ard-prm-filter-form">';
		echo '<input type="hidden" name="page" value="ar-design-reporting" />';
		echo '<input type="hidden" name="status" value="' . esc_attr((string) ($query_args['status'] ?? '')) . '" />';
		echo '<input type="hidden" name="classification" value="' . esc_attr((string) ($query_args['classification'] ?? '')) . '" />';
		echo '<input type="hidden" name="kpi_included" value="' . esc_attr((string) ($query_args['kpi_included'] ?? '')) . '" />';
		echo '<input type="hidden" name="date_from" value="' . esc_attr((string) ($query_args['date_from'] ?? '')) . '" />';
		echo '<input type="hidden" name="date_to" value="' . esc_attr((string) ($query_args['date_to'] ?? '')) . '" />';
		echo '<input type="hidden" name="compare_date_from" value="' . esc_attr((string) ($query_args['compare_date_from'] ?? '')) . '" />';
		echo '<input type="hidden" name="compare_date_to" value="' . esc_attr((string) ($query_args['compare_date_to'] ?? '')) . '" />';
		echo '<p class="ard-form-field ard-prm-filter-control"><label for="ard-prm-product-id-filter">' . esc_html__('ID produktu', 'ar-design-reporting-products-module') . '</label><br /><input id="ard-prm-product-id-filter" type="number" min="1" name="product_id" value="' . esc_attr($selected_product_id > 0 ? (string) $selected_product_id : '') . '" class="regular-text" /> ';
		echo '<button type="submit" class="button button-secondary">' . esc_html__('Načítať detail', 'ar-design-reporting-products-module') . '</button></p>';
		echo '</form>';

		echo '<div class="ard-prm-table-wrap">';
		echo '<table class="widefat striped ard-table ard-prm-table">';
		echo '<thead><tr><th>' . esc_html__('Čas (GMT)', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Sklad', 'ar-design-reporting-products-module') . '</th><th>' . esc_html__('Stav skladu', 'ar-design-reporting-products-module') . '</th></tr></thead>';
		echo '<tbody>';
		if (empty($stock_history)) {
			echo '<tr><td colspan="3">' . esc_html($selected_product_id > 0 ? sprintf(__('Pre %s nie je v zvolenom období dostupná história skladu.', 'ar-design-reporting-products-module'), $selected_product_label) : __('Vyber produkt pre zobrazenie historického skladu.', 'ar-design-reporting-products-module')) . '</td></tr>';
		} else {
			foreach ($stock_history as $row) {
				echo '<tr>';
				echo '<td>' . esc_html($this->formatGmtDate((string) ($row['captured_at_gmt'] ?? ''))) . '</td>';
				echo '<td>' . esc_html(number_format((float) ($row['stock_quantity'] ?? 0), 2, ',', ' ')) . '</td>';
				echo '<td>' . esc_html($this->formatProductStockStatus((string) ($row['stock_status'] ?? ''))) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table></div>';

		echo '<div class="ard-button-row ard-prm-toolbar">';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('ard_export_product_top_xlsx');
		echo '<input type="hidden" name="action" value="ard_export_product_top_xlsx" />';
		echo '<input type="hidden" name="status" value="' . esc_attr((string) ($query_args['status'] ?? '')) . '" />';
		echo '<input type="hidden" name="classification" value="' . esc_attr((string) ($query_args['classification'] ?? '')) . '" />';
		echo '<input type="hidden" name="kpi_included" value="' . esc_attr((string) ($query_args['kpi_included'] ?? '')) . '" />';
		echo '<input type="hidden" name="date_from" value="' . esc_attr((string) ($query_args['date_from'] ?? '')) . '" />';
		echo '<input type="hidden" name="date_to" value="' . esc_attr((string) ($query_args['date_to'] ?? '')) . '" />';
		echo '<button type="submit" class="button button-secondary">' . esc_html__('Exportovať produkty (XLSX)', 'ar-design-reporting-products-module') . '</button>';
		echo '</form>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('ard_export_product_stock_history_xlsx');
		echo '<input type="hidden" name="action" value="ard_export_product_stock_history_xlsx" />';
		echo '<input type="hidden" name="product_id" value="' . esc_attr($selected_product_id > 0 ? (string) $selected_product_id : '') . '" />';
		echo '<input type="hidden" name="date_from" value="' . esc_attr((string) ($query_args['date_from'] ?? '')) . '" />';
		echo '<input type="hidden" name="date_to" value="' . esc_attr((string) ($query_args['date_to'] ?? '')) . '" />';
		echo '<button type="submit" class="button button-secondary" ' . ($selected_product_id > 0 ? '' : 'disabled') . '>' . esc_html__('Exportovať históriu skladu produktu (XLSX)', 'ar-design-reporting-products-module') . '</button>';
		echo '</form>';
		echo '</div>';

		$this->renderAssets($sales_series, $stock_history, $selected_product_id, $selected_product_label);
	}

	public function handleExportProductTopXlsx(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_export_product_top_xlsx');

		$raw = is_array($_POST) ? wp_unslash($_POST) : array();
		$filters = $this->normalizeFilters($raw);
		$rows = $this->getTopSellingProducts($filters, 1000);

		$sheet_rows = array();
		$sheet_rows[] = array('ID produktu', 'Názov produktu', 'SKU', 'Predané kusy', 'Obrat', 'Počet objednávok', 'Sklad teraz', 'Stav skladu');
		foreach ($rows as $row) {
			$sheet_rows[] = array(
				(string) ((int) ($row['product_id'] ?? 0)),
				(string) ($row['product_name'] ?? ''),
				(string) ($row['sku'] ?? ''),
				(string) number_format((float) ($row['qty_sold'] ?? 0), 2, '.', ''),
				(string) number_format((float) ($row['gross_revenue'] ?? 0), 2, '.', ''),
				(string) ((int) ($row['orders_count'] ?? 0)),
				(string) number_format((float) ($row['stock_quantity'] ?? 0), 2, '.', ''),
				(string) ($row['stock_status'] ?? ''),
			);
		}

		$this->streamXlsx('ar-design-reporting-produkty-' . gmdate('Ymd-His') . '.xlsx', $sheet_rows);
		exit;
	}

	public function handleExportProductStockHistoryXlsx(): void
	{
		$this->ensurePermissions();
		check_admin_referer('ard_export_product_stock_history_xlsx');

		$raw = is_array($_POST) ? wp_unslash($_POST) : array();
		$product_id = isset($raw['product_id']) ? absint((string) $raw['product_id']) : 0;
		if ($product_id <= 0) {
			wp_die(esc_html__('Nie je zvolený produkt pre export histórie skladu.', 'ar-design-reporting-products-module'));
		}

		$from = isset($raw['date_from']) ? sanitize_text_field((string) $raw['date_from']) : '';
		$to = isset($raw['date_to']) ? sanitize_text_field((string) $raw['date_to']) : '';
		$rows = $this->getProductStockHistory($product_id, $from, $to, 5000);
		$product_name = get_the_title($product_id);
		$product_title = is_string($product_name) && '' !== trim($product_name) ? $product_name : sprintf(__('Produkt #%d', 'ar-design-reporting-products-module'), $product_id);

		$sheet_rows = array();
		$sheet_rows[] = array('ID produktu', 'Názov produktu', 'Čas záznamu (GMT)', 'Množstvo na sklade', 'Stav skladu');
		foreach ($rows as $row) {
			$sheet_rows[] = array(
				(string) $product_id,
				(string) $product_title,
				$this->formatGmtDate((string) ($row['captured_at_gmt'] ?? '')),
				(string) number_format((float) ($row['stock_quantity'] ?? 0), 2, '.', ''),
				(string) ($row['stock_status'] ?? ''),
			);
		}

		$this->streamXlsx('ar-design-reporting-sklad-produktu-' . $product_id . '-' . gmdate('Ymd-His') . '.xlsx', $sheet_rows);
		exit;
	}

	public function captureStockSnapshot(): int
	{
		global $wpdb;

		$product_meta_lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$posts_table = $wpdb->posts;
		$history_table = $this->tableStockHistory();
		$captured_at_gmt = current_time('mysql', true);

		$products = $wpdb->get_results(
			"SELECT meta.product_id, meta.stock_quantity, meta.stock_status
			FROM {$product_meta_lookup} meta
			INNER JOIN {$posts_table} posts ON posts.ID = meta.product_id
			WHERE posts.post_type IN ('product', 'product_variation')
				AND posts.post_status = 'publish'",
			ARRAY_A
		);

		if (! is_array($products) || empty($products)) {
			return 0;
		}

		$inserted = 0;
		foreach ($products as $product) {
			$product_id = isset($product['product_id']) ? (int) $product['product_id'] : 0;
			if ($product_id <= 0) {
				continue;
			}

			$stock_quantity = null;
			if (isset($product['stock_quantity']) && '' !== (string) $product['stock_quantity'] && is_numeric($product['stock_quantity'])) {
				$stock_quantity = (string) (float) $product['stock_quantity'];
			}

			$result = $wpdb->insert(
				$history_table,
				array(
					'product_id' => $product_id,
					'stock_quantity' => $stock_quantity,
					'stock_status' => isset($product['stock_status']) ? sanitize_key((string) $product['stock_status']) : 'instock',
					'captured_at_gmt' => $captured_at_gmt,
				),
				array('%d', '%s', '%s', '%s')
			);

			if (false !== $result) {
				$inserted++;
			}
		}

		return $inserted;
	}

	private static function createSchema(): void
	{
		global $wpdb;

		$table = $wpdb->prefix . 'ard_product_stock_history';
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			stock_quantity decimal(20,4) DEFAULT NULL,
			stock_status varchar(20) NOT NULL DEFAULT 'instock',
			captured_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_captured (product_id, captured_at_gmt),
			KEY captured (captured_at_gmt)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	private static function ensureScheduled(): void
	{
		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
		}
	}

	private static function unscheduleAll(): void
	{
		$timestamp = wp_next_scheduled(self::CRON_HOOK);
		while (false !== $timestamp) {
			wp_unschedule_event($timestamp, self::CRON_HOOK);
			$timestamp = wp_next_scheduled(self::CRON_HOOK);
		}
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<string, string>
	 */
	private function normalizeFilters(array $filters): array
	{
		$status = isset($filters['status']) ? sanitize_key((string) $filters['status']) : '';
		$classification = isset($filters['classification']) ? sanitize_key((string) $filters['classification']) : '';
		$kpi_included = isset($filters['kpi_included']) ? sanitize_key((string) $filters['kpi_included']) : '';
		$date_from = isset($filters['date_from']) ? sanitize_text_field((string) $filters['date_from']) : '';
		$date_to = isset($filters['date_to']) ? sanitize_text_field((string) $filters['date_to']) : '';

		if (! in_array($kpi_included, array('0', '1'), true)) {
			$kpi_included = '';
		}

		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
			$date_from = '';
		}
		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
			$date_to = '';
		}
		if ('' !== $date_from && '' !== $date_to && $date_from > $date_to) {
			$tmp = $date_from;
			$date_from = $date_to;
			$date_to = $tmp;
		}

		return array(
			'status' => $status,
			'classification' => $classification,
			'kpi_included' => $kpi_included,
			'date_from' => $date_from,
			'date_to' => $date_to,
		);
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	private function getTopSellingProducts(array $filters, int $limit): array
	{
		global $wpdb;

		$lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
		$orders_table = $wpdb->prefix . 'wc_order_stats';
		$product_meta_lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$limit = max(1, min(200, $limit));

		$where_parts = array("orders.status NOT IN ('wc-cancelled', 'wc-failed', 'wc-refunded')");
		$params = array();
		$this->appendDateRangeFilter($where_parts, $params, $filters);

		$sql = "SELECT lookup.product_id,
			SUM(lookup.product_qty) AS qty_sold,
			SUM(lookup.product_gross_revenue) AS gross_revenue,
			COUNT(DISTINCT lookup.order_id) AS orders_count,
			MAX(meta.sku) AS sku,
			MAX(meta.stock_quantity) AS stock_quantity,
			MAX(meta.stock_status) AS stock_status
			FROM {$lookup_table} lookup
			INNER JOIN {$orders_table} orders ON orders.order_id = lookup.order_id
			LEFT JOIN {$product_meta_lookup} meta ON meta.product_id = lookup.product_id
			WHERE " . implode(' AND ', $where_parts) . "
			GROUP BY lookup.product_id
			ORDER BY qty_sold DESC
			LIMIT {$limit}";

		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

		$rows = $wpdb->get_results($sql, ARRAY_A);
		if (! is_array($rows)) {
			return array();
		}

		foreach ($rows as &$row) {
			$product_id = isset($row['product_id']) ? (int) $row['product_id'] : 0;
			$row['product_name'] = $this->resolveProductName($product_id);
			$row['was_sold_in_period'] = ((float) ($row['qty_sold'] ?? 0) > 0.0) ? 1 : 0;
		}

		return $rows;
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	private function getTopStockProducts(array $filters, int $limit): array
	{
		global $wpdb;

		$product_meta_lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
		$orders_table = $wpdb->prefix . 'wc_order_stats';
		$posts_table = $wpdb->posts;
		$limit = max(1, min(200, $limit));

		$where_parts = array(
			"posts.post_type IN ('product', 'product_variation')",
			"posts.post_status = 'publish'",
		);

		$sales_where = array("orders.status NOT IN ('wc-cancelled', 'wc-failed', 'wc-refunded')");
		$sales_params = array();
		$this->appendDateRangeFilter($sales_where, $sales_params, $filters);

		$sales_sql = "SELECT lookup.product_id,
			SUM(lookup.product_qty) AS qty_sold,
			SUM(lookup.product_gross_revenue) AS gross_revenue,
			COUNT(DISTINCT lookup.order_id) AS orders_count
			FROM {$lookup_table} lookup
			INNER JOIN {$orders_table} orders ON orders.order_id = lookup.order_id
			WHERE " . implode(' AND ', $sales_where) . "
			GROUP BY lookup.product_id";
		if (! empty($sales_params)) {
			$sales_sql = $wpdb->prepare($sales_sql, $sales_params);
		}

		$sql = "SELECT meta.product_id,
			meta.sku,
			meta.stock_quantity,
			meta.stock_status,
			COALESCE(sales.qty_sold, 0) AS qty_sold,
			COALESCE(sales.gross_revenue, 0) AS gross_revenue,
			COALESCE(sales.orders_count, 0) AS orders_count
			FROM {$product_meta_lookup} meta
			INNER JOIN {$posts_table} posts ON posts.ID = meta.product_id
			LEFT JOIN ({$sales_sql}) sales ON sales.product_id = meta.product_id
			WHERE " . implode(' AND ', $where_parts) . "
			ORDER BY meta.stock_quantity DESC
			LIMIT {$limit}";

		$rows = $wpdb->get_results($sql, ARRAY_A);
		if (! is_array($rows)) {
			return array();
		}

		foreach ($rows as &$row) {
			$product_id = isset($row['product_id']) ? (int) $row['product_id'] : 0;
			$row['product_name'] = $this->resolveProductName($product_id);
			$row['was_sold_in_period'] = ((float) ($row['qty_sold'] ?? 0) > 0.0) ? 1 : 0;
		}

		return $rows;
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	private function getDailySalesSeries(array $filters, int $limit, int $product_id = 0): array
	{
		global $wpdb;

		$lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
		$orders_table = $wpdb->prefix . 'wc_order_stats';
		$limit = max(7, min(365, $limit));
		$where_parts = array("orders.status NOT IN ('wc-cancelled', 'wc-failed', 'wc-refunded')");
		$params = array();
		if ($product_id > 0) {
			$where_parts[] = 'lookup.product_id = %d';
			$params[] = $product_id;
		}
		$this->appendDateRangeFilter($where_parts, $params, $filters, 'orders.date_created');

		$sql = "SELECT DATE(orders.date_created) AS sales_day,
			SUM(lookup.product_gross_revenue) AS gross_revenue
			FROM {$lookup_table} lookup
			INNER JOIN {$orders_table} orders ON orders.order_id = lookup.order_id
			WHERE " . implode(' AND ', $where_parts) . "
			GROUP BY DATE(orders.date_created)
			ORDER BY sales_day ASC
			LIMIT {$limit}";
		if (! empty($params)) {
			$sql = $wpdb->prepare($sql, $params);
		}

		$rows = $wpdb->get_results($sql, ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function getProductStockHistory(int $product_id, string $from, string $to, int $limit): array
	{
		global $wpdb;
		if ($product_id <= 0) {
			return array();
		}

		$limit = max(1, min(5000, $limit));
		$where_parts = array('product_id = %d');
		$params = array($product_id);

		if ('' !== $from) {
			$where_parts[] = 'captured_at_gmt >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ('' !== $to) {
			$where_parts[] = 'captured_at_gmt <= %s';
			$params[] = $to . ' 23:59:59';
		}

		$sql = "SELECT product_id, stock_quantity, stock_status, captured_at_gmt
			FROM {$this->tableStockHistory()}
			WHERE " . implode(' AND ', $where_parts) . "
			ORDER BY captured_at_gmt ASC
			LIMIT {$limit}";

		$sql = $wpdb->prepare($sql, $params);
		$rows = $wpdb->get_results($sql, ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	private function getReportingAdminUrl(): string
	{
		return add_query_arg(
			array(
				'page' => 'ar-design-reporting',
			),
			admin_url('admin.php')
		);
	}

	/**
	 * @param array<string, string> $query_args
	 */
	private function buildProductDrilldownUrl(array $query_args, int $product_id): string
	{
		return add_query_arg(
			array_merge(
				$this->sanitizeDashboardQueryArgs($query_args),
				array(
					'product_id' => (string) $product_id,
				)
			),
			$this->getReportingAdminUrl()
		) . '#ard-prm-drilldown';
	}

	/**
	 * @param array<string, mixed> $query_args
	 * @return array<string, string>
	 */
	private function sanitizeDashboardQueryArgs(array $query_args): array
	{
		$sanitized = array(
			'page' => 'ar-design-reporting',
		);

		foreach ($query_args as $key => $value) {
			if (! is_string($key)) {
				continue;
			}

			$string_value = is_scalar($value) ? (string) $value : '';
			if ('' === $string_value) {
				continue;
			}

			$sanitized[$key] = $string_value;
		}

		$sanitized['page'] = 'ar-design-reporting';

		return $sanitized;
	}

	private function ensureStockHistorySeeded(): void
	{
		global $wpdb;

		$table = $this->tableStockHistory();
		$has_any = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} LIMIT 1");
		if ($has_any > 0) {
			return;
		}

		$this->captureStockSnapshot();
	}

	private function renderAssets(array $sales_series, array $stock_history, int $selected_product_id, string $selected_product_label = ''): void
	{
		if ($this->assets_printed) {
			return;
		}
		$this->assets_printed = true;

		$sales_labels = array();
		$sales_values = array();
		foreach ($sales_series as $row) {
			$sales_labels[] = (string) ($row['sales_day'] ?? '');
			$sales_values[] = (float) ($row['gross_revenue'] ?? 0);
		}
		$stock_labels = array();
		$stock_values = array();
		foreach ($stock_history as $row) {
			$stock_labels[] = (string) ($row['captured_at_gmt'] ?? '');
			$stock_values[] = (float) ($row['stock_quantity'] ?? 0);
		}

		$sales_payload = wp_json_encode(array('labels' => $sales_labels, 'values' => $sales_values));
		$stock_payload = wp_json_encode(array('labels' => $stock_labels, 'values' => $stock_values, 'productId' => $selected_product_id));
		$stock_empty_message = $selected_product_id > 0
			? sprintf(__('Pre %s nie je v zvolenom období dostupná história skladu.', 'ar-design-reporting-products-module'), $selected_product_label !== '' ? $selected_product_label : sprintf(__('produkt #%d', 'ar-design-reporting-products-module'), $selected_product_id))
			: __('Vyberte produkt pre zobrazenie historického skladu.', 'ar-design-reporting-products-module');
		$sales_payload = is_string($sales_payload) ? $sales_payload : '{"labels":[],"values":[]}';
		$stock_payload = is_string($stock_payload) ? $stock_payload : '{"labels":[],"values":[],"productId":0}';
		$stock_empty_message = wp_json_encode($stock_empty_message);
		$stock_empty_message = is_string($stock_empty_message) ? $stock_empty_message : '""';

		echo '<style>
		.ard-ext-card--products { width: 100%; }
		.ard-prm-intro { margin: 0 0 12px; }
		.ard-prm-stock-summary { margin: 0 0 8px; color: #50575e; font-size: 13px; }
		.ard-prm-filter-form { width:100%; max-width:none; }
		.ard-prm-filter-control { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
		.ard-prm-toolbar { margin-top:10px; }
		.ard-prm-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:8px; }
		.ard-prm-table-wrap { max-width:100%; overflow-x:auto; border-radius:12px; }
		.ard-prm-table { width:100%; min-width:760px; table-layout:fixed; font-size:13px; }
		.ard-prm-table td, .ard-prm-table th { white-space:normal; overflow-wrap:anywhere; word-break:break-word; }
		.ard-prm-chart-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:14px; }
		.ard-prm-chart-card { min-height:260px; padding:10px 12px; }
		.ard-prm-chart-card h3 { margin-top:2px; margin-bottom:10px; }
			.ard-prm-chart-empty { color:#64748b; font-size:12px; padding:12px 0 0; }
			.ard-prm-pagination { margin-top:8px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
			.ard-prm-page-info { font-size:12px; color:#475569; min-width:110px; text-align:center; }
			.ard-prm-page-size { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; }
			.ard-prm-page-size select { min-width:72px; }
			@media (max-width: 900px) {
				.ard-prm-grid, .ard-prm-chart-grid { grid-template-columns:1fr; }
				.ard-prm-table { min-width:640px; font-size:12px; }
			}
			</style>';

		echo '<script>
			document.addEventListener("DOMContentLoaded", function () {
				var root = document.querySelector(".ard-reporting-dashboard");
				if (!root) { return; }
				function initPaginatedTable(tableKey) {
					var table = root.querySelector("[data-ard-prm-paginated-table=\"" + tableKey + "\"]");
					var pager = root.querySelector("[data-ard-prm-pagination=\"" + tableKey + "\"]");
					if (!table || !pager) { return; }
					var tbody = table.querySelector("tbody");
					if (!tbody) { return; }
					var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr"));
					if (!rows.length) { return; }
					var prevBtn = pager.querySelector("[data-ard-prm-page-action=\"prev\"]");
					var nextBtn = pager.querySelector("[data-ard-prm-page-action=\"next\"]");
					var info = pager.querySelector("[data-ard-prm-page-info]");
					var sizeSelect = pager.querySelector("[data-ard-prm-page-size]");
					if (!prevBtn || !nextBtn || !info || !sizeSelect) { return; }
					var pageSize = Math.max(1, parseInt(sizeSelect.value || "5", 10) || 5);
					var currentPage = 1;
					function renderPage() {
						var totalRows = rows.length;
						var totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
						if (currentPage > totalPages) { currentPage = totalPages; }
						var start = (currentPage - 1) * pageSize;
						var end = start + pageSize;
						rows.forEach(function (row, index) {
							row.style.display = (index >= start && index < end) ? "" : "none";
						});
						info.textContent = "Strana " + currentPage + " / " + totalPages;
						prevBtn.disabled = currentPage <= 1;
						nextBtn.disabled = currentPage >= totalPages;
					}
					prevBtn.addEventListener("click", function () {
						if (currentPage > 1) {
							currentPage -= 1;
							renderPage();
						}
					});
					nextBtn.addEventListener("click", function () {
						var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
						if (currentPage < totalPages) {
							currentPage += 1;
							renderPage();
						}
					});
					sizeSelect.addEventListener("change", function () {
						pageSize = Math.max(1, parseInt(sizeSelect.value || "5", 10) || 5);
						currentPage = 1;
						renderPage();
					});
					renderPage();
				}
				initPaginatedTable("top-sellers");
				initPaginatedTable("top-stock");
				(function normalizeProductLinks() {
					var anchors = root.querySelectorAll("a[data-ard-prm-product-link]");
					if (!anchors.length) { return; }
				anchors.forEach(function (anchor) {
					var rawHref = anchor.getAttribute("href") || "";
					if (!rawHref) { return; }
					try {
						var url = new URL(rawHref, window.location.origin);
						if (!/\/wp-admin\/admin\.php$/i.test(url.pathname)) { return; }
						url.searchParams.set("page", "ar-design-reporting");
						["status", "classification", "kpi_included", "date_from", "date_to", "compare_date_from", "compare_date_to", "product_id"].forEach(function (key) {
							var value = url.searchParams.get(key);
							if (value === "") {
								url.searchParams.delete(key);
							}
						});
						url.hash = "#ard-prm-drilldown";
						anchor.setAttribute("href", url.toString());
					} catch (error) {
						/* ignore malformed URLs and keep original href */
					}
				});
			})();
			function drawLineChart(canvasId, data, color, emptyText, options) {
				var canvas = root.querySelector("#" + canvasId);
				if (!canvas) { return; }
				options = options || {};
				var axisMode = options.axisMode || "default";
				var labelMode = options.labelMode || "default";
				var labels = Array.isArray(data.labels) ? data.labels : [];
				var values = Array.isArray(data.values) ? data.values : [];
				if (!labels.length || !values.length) {
					var empty = document.createElement("div");
					empty.className = "ard-prm-chart-empty";
					empty.textContent = emptyText;
					canvas.replaceWith(empty);
					return;
				}
				var ctx = canvas.getContext("2d");
				if (!ctx) { return; }
				var width = canvas.clientWidth > 0 ? canvas.clientWidth : 640;
				var height = canvas.height > 0 ? canvas.height : 180;
				var ratio = window.devicePixelRatio || 1;
				canvas.width = Math.round(width * ratio);
				canvas.height = Math.round(height * ratio);
				ctx.scale(ratio, ratio);
				var padding = { top:20, right:18, bottom:34, left:62 };
				var innerW = width - padding.left - padding.right;
				var innerH = height - padding.top - padding.bottom;
				var rawMin = Math.min.apply(null, values);
				var rawMax = Math.max.apply(null, values);
				if (!isFinite(rawMin) || !isFinite(rawMax)) { return; }
				var startAtZero = !!options.startAtZero;
				var min = startAtZero ? 0 : rawMin;
				var max = rawMax;
				if (startAtZero) {
					max = rawMax <= 0 ? 1 : rawMax * 1.1;
				} else if (rawMin === rawMax) {
					min = rawMin - 1;
					max = rawMax + 1;
				} else {
					var rangePadding = (rawMax - rawMin) * 0.1;
					min = rawMin - rangePadding;
					max = rawMax + rangePadding;
				}
				if (max <= min) { max = min + 1; }
				var tickCount = 5;
				var numberFormat = function (value) {
					if (axisMode === "stock") {
						var rounded = Math.round(value);
						if (Math.abs(value - rounded) < 0.001) {
							return rounded.toLocaleString("sk-SK", { maximumFractionDigits: 0 });
						}
						return value.toLocaleString("sk-SK", { minimumFractionDigits: 1, maximumFractionDigits: 1 });
					}
					return value.toLocaleString("sk-SK", { maximumFractionDigits: 2 });
				};
				var formatAxisLabel = function (rawLabel) {
					var raw = String(rawLabel || "");
					if (labelMode === "date") {
						var dateMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
						if (dateMatch) {
							return dateMatch[3] + "." + dateMatch[2] + ".";
						}
					}
					if (labelMode === "datetime") {
						var dateTimeMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
						if (dateTimeMatch) {
							return dateTimeMatch[3] + "." + dateTimeMatch[2] + ". " + dateTimeMatch[4] + ":" + dateTimeMatch[5];
						}
					}
					return raw;
				};
				ctx.clearRect(0, 0, width, height);
				ctx.fillStyle = "#ffffff";
				ctx.fillRect(0, 0, width, height);
				ctx.strokeStyle = "#e2e8f0";
				ctx.lineWidth = 1;
				ctx.fillStyle = "#475569";
				ctx.font = "11px sans-serif";
				for (var i = 0; i < tickCount; i += 1) {
					var ratioY = i / (tickCount - 1);
					var gy = padding.top + innerH * ratioY;
					var tickValue = max - ((max - min) * ratioY);
					ctx.beginPath();
					ctx.moveTo(padding.left, gy);
					ctx.lineTo(padding.left + innerW, gy);
					ctx.stroke();
					ctx.textAlign = "right";
					ctx.textBaseline = i === 0 ? "top" : (i === tickCount - 1 ? "bottom" : "middle");
					ctx.fillText(numberFormat(tickValue), padding.left - 8, gy);
				}
				ctx.strokeStyle = color;
				ctx.fillStyle = color;
				ctx.lineWidth = 2;
				ctx.beginPath();
				values.forEach(function (value, index) {
					var x = padding.left + (innerW * index) / Math.max(1, values.length - 1);
					var y = padding.top + (max - value) / (max - min) * innerH;
					if (index === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
				});
				ctx.stroke();
				values.forEach(function (value, index) {
					var x = padding.left + (innerW * index) / Math.max(1, values.length - 1);
					var y = padding.top + (max - value) / (max - min) * innerH;
					ctx.beginPath();
					ctx.arc(x, y, 2.6, 0, Math.PI * 2);
					ctx.fill();
				});
				ctx.fillStyle = "#475569";
				ctx.font = "11px sans-serif";
				ctx.textBaseline = "top";
				var xLabelIndexes = labels.length <= 1
					? [0]
					: labels.length === 2
						? [0, 1]
						: [0, Math.floor((labels.length - 1) / 2), labels.length - 1];
				var xLabelPositions = xLabelIndexes.length === 1
					? [padding.left + (innerW / 2)]
					: xLabelIndexes.length === 2
						? [padding.left, padding.left + innerW]
						: [padding.left, padding.left + (innerW / 2), padding.left + innerW];
				var xLabelAlignments = xLabelIndexes.length === 1
					? ["center"]
					: xLabelIndexes.length === 2
						? ["left", "right"]
						: ["left", "center", "right"];
				var usedIndexes = {};
				xLabelIndexes.forEach(function (labelIndex, labelPositionIndex) {
					if (usedIndexes[labelIndex]) { return; }
					usedIndexes[labelIndex] = true;
					ctx.textAlign = xLabelAlignments[labelPositionIndex] || "center";
					ctx.fillText(formatAxisLabel(labels[labelIndex] || ""), xLabelPositions[labelPositionIndex], height - padding.bottom + 12);
				});
			}
			var salesData = ' . $sales_payload . ';
			var stockData = ' . $stock_payload . ';
			drawLineChart("ard-prm-sales-chart", salesData || {}, "#0f766e", "Za zvolené obdobie nie sú dostupné údaje o predajoch produktov.", { startAtZero: true, axisMode: "sales", labelMode: "date" });
			drawLineChart("ard-prm-stock-chart", stockData || {}, "#4f46e5", ' . $stock_empty_message . ', { startAtZero: false, axisMode: "stock", labelMode: "datetime" });
		});
		</script>';
	}

	private function tableStockHistory(): string
	{
		global $wpdb;

		return $wpdb->prefix . 'ard_product_stock_history';
	}

	private function ensurePermissions(): void
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			wp_die(esc_html__('Na vykonanie tejto akcie nemáte oprávnenie.', 'ar-design-reporting-products-module'));
		}
	}

	/**
	 * @param array<int, string> $where_parts
	 * @param array<int, string> $params
	 * @param array<string, string> $filters
	 */
	private function appendDateRangeFilter(array &$where_parts, array &$params, array $filters, string $column = 'orders.date_created_gmt'): void
	{
		if ('' !== ($filters['date_from'] ?? '')) {
			$where_parts[] = "DATE({$column}) >= %s";
			$params[] = (string) $filters['date_from'];
		}

		if ('' !== ($filters['date_to'] ?? '')) {
			$where_parts[] = "DATE({$column}) <= %s";
			$params[] = (string) $filters['date_to'];
		}
	}

	private function resolveProductName(int $product_id): string
	{
		if ($product_id <= 0) {
			return '';
		}

		$name = get_the_title($product_id);
		if (is_string($name) && '' !== trim($name)) {
			return $name;
		}

		return sprintf(__('Produkt #%d', 'ar-design-reporting-products-module'), $product_id);
	}

	private function formatProductStock(string $stock_quantity, string $stock_status): string
	{
		$status_label = $this->formatProductStockStatus($stock_status);
		$stock_quantity = trim($stock_quantity);

		if ('' === $stock_quantity || ! is_numeric($stock_quantity)) {
			return $status_label;
		}

		return number_format((float) $stock_quantity, 2, ',', ' ') . ' (' . $status_label . ')';
	}

	private function formatProductStockStatus(string $stock_status): string
	{
		$labels = array(
			'instock' => __('Skladom', 'ar-design-reporting-products-module'),
			'outofstock' => __('Vypredané', 'ar-design-reporting-products-module'),
			'onbackorder' => __('Na objednávku', 'ar-design-reporting-products-module'),
		);
		$key = sanitize_key($stock_status);

		return $labels[$key] ?? ('' !== $key ? $key : __('Nevyplněno', 'ar-design-reporting-products-module'));
	}

	private function formatGmtDate(string $raw_value): string
	{
		if ('' === $raw_value) {
			return __('Nevyplněno', 'ar-design-reporting-products-module');
		}

		try {
			$date = new DateTimeImmutable($raw_value, new DateTimeZone('UTC'));
			return $date->format('d.m.Y H:i:s');
		} catch (Exception $exception) {
			return $raw_value;
		}
	}

	private function getStoreCurrencySymbol(): string
	{
		if (function_exists('get_woocommerce_currency') && function_exists('get_woocommerce_currency_symbol')) {
			return get_woocommerce_currency_symbol(get_woocommerce_currency());
		}

		return '€';
	}

	/**
	 * @param array<int, array<int, string>> $rows
	 */
	private function streamXlsx(string $filename, array $rows): void
	{
		if (! class_exists(ZipArchive::class)) {
			wp_die(esc_html__('XLSX export vyžaduje rozšírenie ZipArchive na serveri.', 'ar-design-reporting-products-module'));
		}

		$temp_file = wp_tempnam('ard-products-module-xlsx');
		if (! is_string($temp_file) || '' === $temp_file) {
			wp_die(esc_html__('Nepodarilo sa pripraviť dočasný súbor pre XLSX export.', 'ar-design-reporting-products-module'));
		}

		$zip = new ZipArchive();
		$open_result = $zip->open($temp_file, ZipArchive::OVERWRITE);
		if (true !== $open_result) {
			wp_die(esc_html__('Nepodarilo sa vytvoriť XLSX export.', 'ar-design-reporting-products-module'));
		}

		$zip->addFromString('[Content_Types].xml', $this->buildXlsxContentTypesXml());
		$zip->addFromString('_rels/.rels', $this->buildXlsxRootRelsXml());
		$zip->addFromString('xl/workbook.xml', $this->buildXlsxWorkbookXml());
		$zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildXlsxWorkbookRelsXml());
		$zip->addFromString('xl/styles.xml', $this->buildXlsxStylesXml());
		$zip->addFromString('xl/worksheets/sheet1.xml', $this->buildXlsxSheetXml($rows));
		$zip->close();

		nocache_headers();
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($temp_file));
		readfile($temp_file);
		@unlink($temp_file);
	}

	private function buildXlsxContentTypesXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
	}

	private function buildXlsxRootRelsXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	private function buildXlsxWorkbookXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';
	}

	private function buildXlsxWorkbookRelsXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	private function buildXlsxStylesXml(): string
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
			. '</styleSheet>';
	}

	/**
	 * @param array<int, array<int, string>> $rows
	 */
	private function buildXlsxSheetXml(array $rows): string
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

		foreach ($rows as $row_index => $cells) {
			$row_number = $row_index + 1;
			$xml .= '<row r="' . $row_number . '">';
			foreach ($cells as $col_index => $value) {
				$ref = $this->xlsxColumnName($col_index + 1) . $row_number;
				$escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
				$xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
			}
			$xml .= '</row>';
		}

		$xml .= '</sheetData></worksheet>';
		return $xml;
	}

	private function xlsxColumnName(int $column_index): string
	{
		$name = '';
		while ($column_index > 0) {
			$column_index--;
			$name = chr(($column_index % 26) + 65) . $name;
			$column_index = (int) floor($column_index / 26);
		}

		return $name;
	}

	/**
	 * @param array<string, string> $filters
	 * @param array<string, mixed> $context
	 * @return array<string, string>
	 */
	private function buildDashboardQueryArgs(array $filters, array $context = array()): array
	{
		if (isset($context['query_args']) && is_array($context['query_args'])) {
			return $this->sanitizeDashboardQueryArgs($context['query_args']);
		}

		$compare_filters = isset($context['compare_filters']) && is_array($context['compare_filters'])
			? $this->normalizeFilters($context['compare_filters'])
			: array();

		return $this->sanitizeDashboardQueryArgs(array(
			'page' => 'ar-design-reporting',
			'status' => (string) ($filters['status'] ?? ''),
			'classification' => (string) ($filters['classification'] ?? ''),
			'kpi_included' => (string) ($filters['kpi_included'] ?? ''),
			'date_from' => (string) ($filters['date_from'] ?? ''),
			'date_to' => (string) ($filters['date_to'] ?? ''),
			'compare_date_from' => (string) ($compare_filters['date_from'] ?? ''),
			'compare_date_to' => (string) ($compare_filters['date_to'] ?? ''),
		));
	}
}

final class ArDesignReportingProductsModuleUpdater
{
	private const CACHE_TTL = 900;

	private string $repository_full_name;
	private string $plugin_basename;
	private string $current_version;

	public function __construct(string $repository_full_name, string $plugin_basename, string $current_version)
	{
		$this->repository_full_name = $repository_full_name;
		$this->plugin_basename = $plugin_basename;
		$this->current_version = $current_version;
	}

	public function register(): void
	{
		add_filter('pre_set_site_transient_update_plugins', array($this, 'injectUpdateData'));
		add_filter('plugins_api', array($this, 'injectPluginInfo'), 20, 3);
		add_action('upgrader_process_complete', array($this, 'clearCacheAfterUpgrade'), 10, 2);
	}

	/**
	 * @param object $transient
	 * @return object
	 */
	public function injectUpdateData($transient)
	{
		if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
			return $transient;
		}

		$release = $this->getLatestRelease();
		if (empty($release)) {
			return $transient;
		}

		$latest_version = (string) ($release['version'] ?? '');
		$package_url = (string) ($release['package_url'] ?? '');
		$details_url = (string) ($release['details_url'] ?? '');

		if ('' === $latest_version || '' === $package_url || version_compare($latest_version, $this->current_version, '<=')) {
			return $transient;
		}

		$transient->response[$this->plugin_basename] = (object) array(
			'slug' => 'ar-design-reporting-products-module',
			'plugin' => $this->plugin_basename,
			'new_version' => $latest_version,
			'url' => $details_url,
			'package' => $package_url,
		);

		return $transient;
	}

	/**
	 * @param mixed $result
	 * @param mixed $action
	 * @param mixed $args
	 * @return mixed
	 */
	public function injectPluginInfo($result, $action, $args)
	{
		if ('plugin_information' !== $action || ! is_object($args) || ! isset($args->slug) || 'ar-design-reporting-products-module' !== $args->slug) {
			return $result;
		}

		$release = $this->getLatestRelease();
		$version = ! empty($release['version']) ? (string) $release['version'] : $this->current_version;
		$details = ! empty($release['details_url']) ? (string) $release['details_url'] : 'https://github.com/' . $this->repository_full_name;
		$body = ! empty($release['body']) ? (string) $release['body'] : '';

		return (object) array(
			'name' => 'AR Design Reporting - Products Module',
			'slug' => 'ar-design-reporting-products-module',
			'version' => $version,
			'author' => '<a href="https://github.com/' . esc_attr($this->repository_full_name) . '">Arpad70</a>',
			'homepage' => $details,
			'download_link' => (string) ($release['package_url'] ?? ''),
			'sections' => array(
				'description' => __('Produktový modul pre dashboard AR Design Reporting.', 'ar-design-reporting-products-module'),
				'changelog' => '' !== $body ? wp_kses_post(nl2br(esc_html($body))) : __('Changelog nie je dostupný.', 'ar-design-reporting-products-module'),
			),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function getLatestRelease(): array
	{
		$cached = get_transient($this->getCacheKey());
		if (is_array($cached) && isset($cached['version'])) {
			return $cached;
		}

		$request_url = sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository_full_name);
		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => 'ar-design-reporting-products-module/' . $this->current_version,
				),
			)
		);

		if (is_wp_error($response)) {
			return array();
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);
		if (200 !== $status_code) {
			return array();
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode((string) $body, true);
		if (! is_array($data)) {
			return array();
		}

		$tag_name = isset($data['tag_name']) ? (string) $data['tag_name'] : '';
		$version = ltrim($tag_name, 'v');
		$package = $this->extractZipAssetUrl($data);
		$details = isset($data['html_url']) ? (string) $data['html_url'] : '';
		$changelog = isset($data['body']) ? (string) $data['body'] : '';

		if ('' === $version || '' === $package) {
			return array();
		}

		$release = array(
			'version' => $version,
			'package_url' => $package,
			'details_url' => $details,
			'body' => $changelog,
		);

		set_transient($this->getCacheKey(), $release, self::CACHE_TTL);

		return $release;
	}

	/**
	 * @param mixed $upgrader
	 * @param mixed $options
	 */
	public function clearCacheAfterUpgrade($upgrader, $options): void
	{
		if (! is_array($options) || ! isset($options['type'], $options['action'])) {
			return;
		}

		if ('plugin' !== $options['type'] || 'update' !== $options['action']) {
			return;
		}

		$plugins = isset($options['plugins']) && is_array($options['plugins']) ? $options['plugins'] : array();
		if (in_array($this->plugin_basename, $plugins, true)) {
			delete_transient($this->getCacheKey());
		}
	}

	/**
	 * @param array<string, mixed> $release_data
	 */
	private function extractZipAssetUrl(array $release_data): string
	{
		$assets = isset($release_data['assets']) && is_array($release_data['assets']) ? $release_data['assets'] : array();

		foreach ($assets as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$name = isset($asset['name']) ? (string) $asset['name'] : '';
			$url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

			if ('' === $url || ! str_ends_with(strtolower($name), '.zip')) {
				continue;
			}

			if ('ar-design-reporting-products-module.zip' === strtolower($name)) {
				return $url;
			}
		}

		return '';
	}

	private function getCacheKey(): string
	{
		return 'ard_reporting_products_module_release_data_' . md5($this->repository_full_name);
	}
}

ArDesignReportingProductsModule::init();
register_activation_hook(__FILE__, array('ArDesignReportingProductsModule', 'activate'));
register_deactivation_hook(__FILE__, array('ArDesignReportingProductsModule', 'deactivate'));
register_uninstall_hook(__FILE__, array('ArDesignReportingProductsModule', 'uninstall'));

$ard_products_updater = new ArDesignReportingProductsModuleUpdater(
	ARD_REPORTING_PRODUCTS_MODULE_REPOSITORY,
	ARD_REPORTING_PRODUCTS_MODULE_BASENAME,
	ARD_REPORTING_PRODUCTS_MODULE_VERSION
);
$ard_products_updater->register();
