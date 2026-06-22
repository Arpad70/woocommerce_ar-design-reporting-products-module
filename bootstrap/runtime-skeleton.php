<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

if (! isset($ardReportingModuleBootstrapConfig) || ! is_array($ardReportingModuleBootstrapConfig)) {
	return;
}

$config = $ardReportingModuleBootstrapConfig;
unset($ardReportingModuleBootstrapConfig);

require_once WP_PLUGIN_DIR . '/ar-design-shared-support/includes/updates/ReportingModuleRuntime.php';
ard_shared_register_reporting_module_update_runtime($config);

return;

add_action('init', static function () use ($config): void {
	load_plugin_textdomain((string) $config['text_domain'], false, dirname((string) $config['basename']) . '/languages');
});

if (($config['register_updater'] ?? true) === true) {
	$ardReportingModuleUpdater = new class($config) {
		private const CACHE_TTL = 900;
		private string $repositoryFullName;
		private string $pluginBasename;
		private string $currentVersion;
		private string $pluginSlug;
		private string $pluginName;
		private string $textDomain;
		private string $description;
		public function __construct(array $config)
		{
			$this->repositoryFullName = (string) ($config['repository'] ?? '');
			$this->pluginBasename = (string) ($config['basename'] ?? '');
			$this->currentVersion = (string) ($config['version'] ?? '');
			$this->pluginSlug = (string) ($config['slug'] ?? '');
			$this->pluginName = (string) ($config['plugin_name'] ?? '');
			$this->textDomain = (string) ($config['text_domain'] ?? '');
			$this->description = (string) ($config['description'] ?? '');
		}
		public function register(): void
		{
			add_filter('pre_set_site_transient_update_plugins', array($this, 'injectUpdateData'));
			add_filter('plugins_api', array($this, 'injectPluginInfo'), 20, 3);
			add_action('upgrader_process_complete', array($this, 'clearCacheAfterUpgrade'), 10, 2);
		}
		public function injectUpdateData(mixed $transient): mixed
		{
			if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
				return $transient;
			}
			$release = $this->getLatestRelease();
			if (empty($release)) {
				return $transient;
			}
			$latestVersion = (string) ($release['version'] ?? '');
			$packageUrl = (string) ($release['package_url'] ?? '');
			$detailsUrl = (string) ($release['details_url'] ?? '');
			if ('' === $latestVersion || '' === $packageUrl || version_compare($latestVersion, $this->currentVersion, '<=')) {
				return $transient;
			}
			$transient->response[$this->pluginBasename] = (object) array('slug' => $this->pluginSlug, 'plugin' => $this->pluginBasename, 'new_version' => $latestVersion, 'url' => $detailsUrl, 'package' => $packageUrl);
			return $transient;
		}
		public function injectPluginInfo(mixed $result, mixed $action, mixed $args): mixed
		{
			if ('plugin_information' !== $action || ! is_object($args) || ! isset($args->slug) || $this->pluginSlug !== $args->slug) {
				return $result;
			}
			$release = $this->getLatestRelease();
			$version = ! empty($release['version']) ? (string) $release['version'] : $this->currentVersion;
			$details = ! empty($release['details_url']) ? (string) $release['details_url'] : 'https://github.com/' . $this->repositoryFullName;
			$body = ! empty($release['body']) ? (string) $release['body'] : '';
			return (object) array('name' => $this->pluginName, 'slug' => $this->pluginSlug, 'version' => $version, 'author' => '<a href="https://github.com/' . esc_attr($this->repositoryFullName) . '">Arpad70</a>', 'homepage' => $details, 'download_link' => (string) ($release['package_url'] ?? ''), 'sections' => array('description' => esc_html__($this->description, $this->textDomain), 'changelog' => '' !== $body ? wp_kses_post(nl2br(esc_html($body))) : esc_html__('Changelog nie je dostupný.', $this->textDomain)));
		}
		private function getLatestRelease(): array
		{
			$cached = get_transient($this->getCacheKey());
			if (is_array($cached) && isset($cached['version'])) {
				return $cached;
			}
			$response = wp_remote_get(sprintf('https://api.github.com/repos/%s/releases/latest', $this->repositoryFullName), array('timeout' => 15, 'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => $this->pluginSlug . '/' . $this->currentVersion)));
			if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
				return array();
			}
			$data = json_decode((string) wp_remote_retrieve_body($response), true);
			if (! is_array($data)) {
				return array();
			}
			$release = array('version' => ltrim((string) ($data['tag_name'] ?? ''), 'v'), 'package_url' => $this->extractZipAssetUrl($data), 'details_url' => (string) ($data['html_url'] ?? ''), 'body' => (string) ($data['body'] ?? ''));
			if ('' === $release['version'] || '' === $release['package_url']) {
				return array();
			}
			set_transient($this->getCacheKey(), $release, self::CACHE_TTL);
			return $release;
		}
		public function clearCacheAfterUpgrade(mixed $upgrader, mixed $options): void
		{
			if (! is_array($options) || ! isset($options['type'], $options['action'])) {
				return;
			}
			if ('plugin' !== $options['type'] || 'update' !== $options['action']) {
				return;
			}
			$plugins = isset($options['plugins']) && is_array($options['plugins']) ? $options['plugins'] : array();
			if (in_array($this->pluginBasename, $plugins, true)) {
				delete_transient($this->getCacheKey());
			}
		}
		private function extractZipAssetUrl(array $releaseData): string
		{
			$assets = isset($releaseData['assets']) && is_array($releaseData['assets']) ? $releaseData['assets'] : array();
			$fallbackUrl = '';
			foreach ($assets as $asset) {
				if (! is_array($asset)) {
					continue;
				}
				$name = isset($asset['name']) ? (string) $asset['name'] : '';
				$url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
				if ('' === $url || ! str_ends_with(strtolower($name), '.zip')) {
					continue;
				}
				if (strtolower($this->pluginSlug . '.zip') === strtolower($name)) {
					return $url;
				}
				if ('' === $fallbackUrl) {
					$fallbackUrl = $url;
				}
			}
			return $fallbackUrl;
		}
		private function getCacheKey(): string
		{
			return 'ard_reporting_release_' . md5($this->repositoryFullName . '|' . $this->pluginSlug);
		}
	};
	$ardReportingModuleUpdater->register();
}

$ardReportingModuleRollback = new class((string) ($config['basename'] ?? ''), (string) ($config['path'] ?? ''), (string) ($config['text_domain'] ?? ''), (string) ($config['rollback_message'] ?? '')) {
	private const BACKUP_DIR = 'ard-reporting-module-backups';
	private string $pluginBasename;
	private string $pluginRoot;
	private string $textDomain;
	private string $rollbackMessage;
	private bool $backupCreated = false;
	public function __construct(string $pluginBasename, string $pluginRoot, string $textDomain, string $rollbackMessage)
	{
		$this->pluginBasename = $pluginBasename;
		$this->pluginRoot = untrailingslashit($pluginRoot);
		$this->textDomain = $textDomain;
		$this->rollbackMessage = $rollbackMessage;
	}
	public function register(): void
	{
		add_filter('upgrader_pre_install', array($this, 'createBackupBeforeInstall'), 10, 2);
		add_filter('upgrader_install_package_result', array($this, 'rollbackOnInstallFailure'), 10, 2);
	}
	public function createBackupBeforeInstall(mixed $response, mixed $hookExtra): mixed
	{
		if (! $this->isCurrentPluginUpdate($hookExtra) || ! $this->prepareFilesystem()) {
			return $response;
		}
		$backupTarget = $this->getBackupPath();
		$this->removeDirectory($backupTarget);
		if (! wp_mkdir_p(dirname($backupTarget)) || ! $this->copyDirectory($this->pluginRoot, $backupTarget)) {
			return $response;
		}
		$this->backupCreated = true;
		return $response;
	}
	public function rollbackOnInstallFailure(mixed $result, mixed $hookExtra): mixed
	{
		if (! $this->isCurrentPluginUpdate($hookExtra) || ! $this->backupCreated || ! is_wp_error($result) || ! $this->prepareFilesystem()) {
			return $result;
		}
		$backupTarget = $this->getBackupPath();
		if (! is_dir($backupTarget)) {
			return $result;
		}
		$this->removeDirectory($this->pluginRoot);
		if (! $this->copyDirectory($backupTarget, $this->pluginRoot)) {
			return $result;
		}
		return new \WP_Error('ard_reporting_module_rollback_performed', __($this->rollbackMessage, $this->textDomain));
	}
	private function isCurrentPluginUpdate(mixed $hookExtra): bool
	{
		if (! is_array($hookExtra)) {
			return false;
		}
		if ('plugin' !== ($hookExtra['type'] ?? '') || 'update' !== ($hookExtra['action'] ?? '')) {
			return false;
		}
		$plugins = isset($hookExtra['plugins']) && is_array($hookExtra['plugins']) ? $hookExtra['plugins'] : array();
		return in_array($this->pluginBasename, $plugins, true);
	}
	private function prepareFilesystem(): bool
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return WP_Filesystem();
	}
	private function getBackupPath(): string
	{
		$uploads = wp_upload_dir();
		$base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return untrailingslashit($base) . '/' . self::BACKUP_DIR . '/' . md5($this->pluginBasename) . '/latest';
	}
	private function copyDirectory(string $source, string $destination): bool
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return ! is_wp_error(copy_dir($source, $destination));
	}
	private function removeDirectory(string $path): void
	{
		if (! is_dir($path)) {
			return;
		}
		$items = scandir($path);
		if (! is_array($items)) {
			return;
		}
		foreach ($items as $item) {
			if ('.' === $item || '..' === $item) {
				continue;
			}
			$target = $path . DIRECTORY_SEPARATOR . $item;
			if (is_dir($target)) {
				$this->removeDirectory($target);
				continue;
			}
			@unlink($target);
		}
		@rmdir($path);
	}
};
$ardReportingModuleRollback->register();
