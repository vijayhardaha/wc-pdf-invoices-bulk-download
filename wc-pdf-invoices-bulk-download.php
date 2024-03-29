<?php
/**
 * Plugin Name: WooCommerce PDF Invoices Bulk Download
 * Plugin URI: https://github.com/vijayhardaha/wc-pdf-invoices-bulk-download
 * Description: WooCommerce PDF Invoices Bulk Download plugin allows you to download WooCommerce PDF invoices in bulk as zip files. You can filter and group invoices by date range, months order status and much more.
 * Version: 2.0.2
 * Author: Vijay Hardaha
 * Author URI: https://twitter.com/vijayhardaha
 * License: GPLv2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-pdf-invoices-bulk-download
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.0
 * Tested up to: 6.0
 *
 * @package WC_PDF_Invoices_Bulk_Download
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

define( 'WC_PDF_INVOICES_BULK_DOWNLOAD_PLUGIN_FILE', __FILE__ );
define( 'WC_PDF_INVOICES_BULK_DOWNLOAD_ABSPATH', dirname( WC_PDF_INVOICES_BULK_DOWNLOAD_PLUGIN_FILE ) . '/' );
define( 'WC_PDF_INVOICES_BULK_DOWNLOAD_PLUGIN_BASENAME', plugin_basename( WC_PDF_INVOICES_BULK_DOWNLOAD_PLUGIN_FILE ) );
define( 'WC_PDF_INVOICES_BULK_DOWNLOAD_VERSION', '2.0.2' );
define( 'WC_PDF_INVOICES_BULK_DOWNLOAD_PLUGIN_NAME', 'WooCommerce PDF Invoices Bulk Download' );

// Include the main WC_PDF_Invoices_Bulk_Download class.
if ( ! class_exists( 'WC_PDF_Invoices_Bulk_Download', false ) ) {
	include_once dirname( __FILE__ ) . '/includes/class-wc-pdf-invoices-bulk-download.php';
}

/**
 * Returns the main instance of WC_PDF_Invoices_Bulk_Download.
 *
 * @since 1.0.0
 * @return WC_PDF_Invoices_Bulk_Download
 */
function wc_pdf_invoices_bulk_download() {
	return WC_PDF_Invoices_Bulk_Download::instance();
}

// Global for backwards compatibility.
$GLOBALS['wc_pdf_invoices_bulk_download'] = wc_pdf_invoices_bulk_download();
