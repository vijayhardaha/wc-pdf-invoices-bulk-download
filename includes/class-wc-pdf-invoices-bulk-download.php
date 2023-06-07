<?php
/**
 * WooCommerce PDF Invoices Bulk Download setup
 *
 * @since 1.0.0
 * @package WC_PDF_Invoices_Bulk_Download
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Main WC_PDF_Invoices_Bulk_Download Class.
 *
 * @class WC_PDF_Invoices_Bulk_Download
 */
final class WC_PDF_Invoices_Bulk_Download {

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var WC_PDF_Invoices_Bulk_Download
	 */
	protected static $instance = null;

	/**
	 * Main WC_PDF_Invoices_Bulk_Download Instance.
	 *
	 * Ensures only one instance of WC_PDF_Invoices_Bulk_Download is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @return WC_PDF_Invoices_Bulk_Download - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->includes();
		$this->init_hooks();

		// Check if WooCommerce is active.
		require_once ABSPATH . '/wp-admin/includes/plugin.php';
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) && ! function_exists( 'WC' ) ) {
			return;
		}
	}

	/**
	 * When WP has loaded all plugins, trigger the `wc_pdf_invoices_bulk_download_loaded` hook.
	 *
	 * This ensures `wc_pdf_invoices_bulk_download_loaded` is called only after all other plugins
	 * are loaded.
	 *
	 * @since 1.0.0
	 */
	public function on_plugins_loaded() {
		do_action( 'wc_pdf_invoices_bulk_download_loaded' );
	}

	/**
	 * Hook into actions and filters.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		register_shutdown_function( array( $this, 'log_errors' ) );

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ), -1 );
		add_action( 'init', array( $this, 'init' ), 0 );
	}

	/**
	 * Ensures fatal errors are logged so they can be picked up in the status report.
	 *
	 * @since 1.0.0
	 */
	public function log_errors() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
				/* translators: 1: Error Message 2: File Name and Path 3: Line Number */
				$error_message = sprintf( __( '%1$s in %2$s on line %3$s', 'wc-pdf-invoices-bulk-download' ), $error['message'], $error['file'], $error['line'] ) . PHP_EOL;
				// phpcs:disable WordPress.PHP.DevelopmentFunctions
				error_log( $error_message );
				// phpcs:enable
			}
		}
	}

	/**
	 * What type of request is this?
	 *
	 * @since 1.0.0
	 * @param string $type admin, ajax, cron or frontend.
	 * @return bool
	 */
	private function is_request( $type ) {
		switch ( $type ) {
			case 'admin':
				return is_admin();
			case 'ajax':
				return defined( 'DOING_AJAX' );
			case 'cron':
				return defined( 'DOING_CRON' );
			case 'frontend':
				return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
		}
	}

	/**
	 * Include required core files used in admin and on the frontend.
	 *
	 * @since 1.0.0
	 */
	public function includes() {
		require WC_PDF_INVOICES_BULK_DOWNLOAD_ABSPATH . 'vendor/autoload.php';
		include_once WC_PDF_INVOICES_BULK_DOWNLOAD_ABSPATH . 'includes/class-wc-pdf-invoices-bulk-download-admin.php';
		include_once WC_PDF_INVOICES_BULK_DOWNLOAD_ABSPATH . 'includes/class-wc-pdf-invoices-bulk-download-async-request.php';
	}

	/**
	 * Init WC_PDF_Invoices_Bulk_Download when WordPress Initialises.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Before init action.
		do_action( 'before_wc_pdf_invoices_bulk_download_init' );

		// Set up localisation.
		$this->load_plugin_textdomain();

		// Init action.
		do_action( 'wc_pdf_invoices_bulk_download_init' );
	}

	/**
	 * Load Localisation files.
	 *
	 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
	 *
	 * Locales found in:
	 *      - WP_LANG_DIR/wc-pdf-invoices-bulk-download/wc-pdf-invoices-bulk-download-LOCALE.mo
	 *      - WP_LANG_DIR/plugins/wc-pdf-invoices-bulk-download-LOCALE.mo
	 *
	 * @since 1.0.0
	 */
	public function load_plugin_textdomain() {
		if ( function_exists( 'determine_locale' ) ) {
			$locale = determine_locale();
		} else {
			$locale = is_admin() ? get_user_locale() : get_locale();
		}

		$locale = apply_filters( 'plugin_locale', $locale, 'wc-pdf-invoices-bulk-download' );

		unload_textdomain( 'wc-pdf-invoices-bulk-download' );
		load_textdomain( 'wc-pdf-invoices-bulk-download', WP_LANG_DIR . '/wc-pdf-invoices-bulk-download/wc-pdf-invoices-bulk-download-' . $locale . '.mo' );
		load_plugin_textdomain( 'wc-pdf-invoices-bulk-download', false, plugin_basename( dirname( WC_PDF_INVOICES_BULK_DOWNLOAD_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Get the plugin url.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', WC_PDF_INVOICES_BULK_DOWNLOAD_PLUGIN_FILE ) );
	}

	/**
	 * Get the plugin path.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( WC_PDF_INVOICES_BULK_DOWNLOAD_PLUGIN_FILE ) );
	}

	/**
	 * Get Ajax URL.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function ajax_url() {
		return admin_url( 'admin-ajax.php', 'relative' );
	}
}
