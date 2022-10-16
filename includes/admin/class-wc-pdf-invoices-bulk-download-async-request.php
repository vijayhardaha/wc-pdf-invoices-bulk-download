<?php
/**
 * Handle bulk download async request.
 *
 * @since 1.0.0
 * @package WC_PDF_Invoices_Bulk_Download
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * WC_PDF_Invoices_Bulk_Download_Async_Request class.
 */
class WC_PDF_Invoices_Bulk_Download_Async_Request {

	/**
	 * Stores the total number of records to be processed.
	 *
	 * (default value: 0)
	 *
	 * @since 2.0.0
	 * @var int
	 */
	private $limit = 0;

	/**
	 * Start time of current import.
	 *
	 * (default value: 0)
	 *
	 * @since 2.0.0
	 * @var int
	 */
	private $start_time = 0;

	/**
	 * Current user ID.
	 *
	 * (default value: 0)
	 *
	 * @since 2.0.0
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_wc_pdf_invoices_bulk_download_request', array( $this, 'ajax_handler' ) );
	}

	/**
	 * Memory exceeded.
	 *
	 * Ensures the batch process never exceeds 90%
	 * of the maximum WordPress memory.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	private function memory_exceeded() {
		$memory_limit   = $this->get_memory_limit() * 0.7; // 70% of max memory
		$current_memory = memory_get_usage( true );
		$return         = false;

		if ( $current_memory >= $memory_limit ) {
			$return = true;
		}

		return apply_filters( 'wc_pdf_invoices_bulk_download_handler_memory_exceeded', $return );
	}

	/**
	 * Get memory limit.
	 *
	 * @since 2.0.0
	 * @return int
	 */
	private function get_memory_limit() {
		if ( function_exists( 'ini_get' ) ) {
			$memory_limit = ini_get( 'memory_limit' );
		} else {
			// Sensible default.
			$memory_limit = '128M';
		}

		if ( ! $memory_limit || -1 === $memory_limit ) {
			// Unlimited, set to 32GB.
			$memory_limit = '32000M';
		}

		return intval( $memory_limit ) * 1024 * 1024;
	}

	/**
	 * Time exceeded.
	 *
	 * Ensures the batch never exceeds a sensible time limit.
	 * A timeout limit of 30s is common on shared hosting.
	 *
	 * @since 2.0.0
	 * @return bool
	 */
	private function time_exceeded() {
		$finish = $this->start_time + apply_filters( 'wc_pdf_invoices_bulk_download_handler_default_time_limit', 20 ); // 20 seconds
		$return = false;

		if ( time() >= $finish ) {
			$return = true;
		}

		return apply_filters( 'wc_pdf_invoices_bulk_download_handler_time_exceeded', $return );
	}

	/**
	 * Process ajax handler.
	 *
	 * @since 2.0.0
	 * @throws Exception Throws errors when occurred.
	 */
	public function ajax_handler() {
		try {
			$security_check = check_ajax_referer( 'wc-pdf-invoices-bulk-download-request', 'security', false );

			if ( empty( $security_check ) ) {
				throw new Exception( __( 'Nonce validation failed, Please reload the page and try again.', 'wc-pdf-invoices-bulk-download' ) );
			}

			$params = array(
				'position'   => isset( $_POST['position'] ) ? absint( $_POST['position'] ) : 0,
				'batch_size' => apply_filters( 'wc_pdf_invoices_bulk_download_batch_size', 10 ),
			);

			$this->user_id = get_current_user_id();

			if ( ! empty( get_transient( 'wc_pdf_invoices_bulk_download_processing_' . $this->user_id ) ) ) {
				delete_transient( 'wc_pdf_invoices_bulk_download_request_data_' . $this->user_id );
			}

			// Prepare order ids for the request and save on transient.
			$ids = $this->prepare_data();

			$results = $this->run( $params );

			wp_send_json_success(
				array(
					'position'     => $results['position'],
					'percentage'   => $results['percentage'],
					'download_url' => $results['download_url'],
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'error' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Prepare order ids from the requested params.
	 *
	 * @since 2.0.0
	 * @throws Exception Throws errors when occurred.
	 */
	private function prepare_data() {
		check_ajax_referer( 'wc-pdf-invoices-bulk-download-request', 'security' );

		set_transient( 'wc_pdf_invoices_bulk_download_processing_' . $this->user_id, 1, HOUR_IN_SECONDS );

		$transient_name = 'wc_pdf_invoices_bulk_download_request_data_' . $this->user_id;
		$order_ids      = get_transient( $transient_name );

		if ( false === $order_ids || empty( $order_ids ) ) {
			if ( ! isset( $_POST['download_filter'] ) || empty( $_POST['download_filter'] ) ) {
				throw new Exception( __( 'Something is wrong, Please reload the page and try again.', 'wc-pdf-invoices-bulk-download' ) );
			}

			$filter = sanitize_text_field( wp_unslash( $_POST['download_filter'] ) );

			if ( ! in_array( $filter, array( 'month-group', 'range-group' ), true ) ) {
				throw new Exception( __( 'Something is wrong, Please reload the page and try again.', 'wc-pdf-invoices-bulk-download' ) );
			}

			if ( 'month-group' === $filter ) {
				if ( ! isset( $_POST['order_month'] ) || ! isset( $_POST['order_year'] ) || empty( $_POST['order_month'] ) || empty( $_POST['order_year'] ) ) {
					throw new Exception( __( 'Start & End date can not be empty.', 'wc-pdf-invoices-bulk-download' ) );
				}

				$timestamp   = strtotime( sprintf( '%s %s', sanitize_text_field( wp_unslash( $_POST['order_month'] ) ), sanitize_text_field( wp_unslash( $_POST['order_year'] ) ) ) );
				$date_after  = gmdate( 'Y-m-01', $timestamp );
				$date_before = gmdate( 'Y-m-t', $timestamp );
			} elseif ( 'range-group' === $filter ) {
				if ( ! isset( $_POST['start_date'] ) || ! isset( $_POST['end_date'] ) || empty( $_POST['start_date'] ) || empty( $_POST['end_date'] ) ) {
					throw new Exception( __( 'Start & End date can not be empty.', 'wc-pdf-invoices-bulk-download' ) );
				}

				$date_after  = sanitize_text_field( wp_unslash( $_POST['start_date'] ) );
				$date_before = sanitize_text_field( wp_unslash( $_POST['end_date'] ) );
			}

			$status = isset( $_POST['order_status'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['order_status'] ) ) : array();

			$args = array(
				'date_after'  => $date_after,
				'date_before' => $date_before,
				'status'      => $status,
				'type'        => 'shop_order',
				'return'      => 'ids',
				'limit'       => -1,
			);

			$order_ids = wc_get_orders( $args );

			if ( empty( $order_ids ) ) {
				throw new Exception( sprintf( /* translators: 1: start date 2: end date */ esc_html__( 'Orders from %1$s to %2$s not found.', 'wc-pdf-invoices-bulk-download' ), $date_after, $date_before ) );
			}

			set_transient( $transient_name, $order_ids, HOUR_IN_SECONDS );
		}

		$this->limit = count( $order_ids );
		$this->data  = $order_ids;
	}

	/**
	 * Return parsed data.
	 *
	 * @since 2.0.0
	 * @param int $offset   Offset.
	 * @param int $limit    Limit.
	 *
	 * @return array
	 */
	private function get_parsed_data( $offset = 0, $limit = 50 ) {
		if ( ! empty( $this->data ) ) {
			return array_slice( $this->data, $offset, $limit );
		}

		return array();
	}

	/**
	 * Run the process.
	 *
	 * @since 2.0.0
	 * @param array $order_id Order ID.
	 * @throws Exception Throws errors when occurred.
	 */
	private function generate_pdf( $order_id ) {
		if ( class_exists( 'BEWPI_Invoice' ) ) {
			$this->wc_pdf_invoices_plugin( $order_id );
			return;
		} elseif ( function_exists( 'wcpdf_get_document' ) ) {
			$this->wc_pdf_invoices_packing_slips( $order_id );
			return;
		}

		throw new Exception( __( 'Invoices for WooCommerce or WooCommerce PDF Invoices & Packing Slips plugin is required to generate the invoices.', 'wc-pdf-invoices-bulk-download' ) );
	}

	/**
	 * Create invoices zip from orders.
	 *
	 * @since 1.0.0
	 * @param array $order_id Order ID.
	 */
	private function wc_pdf_invoices_plugin( $order_id ) {
		$transient_name = 'wc_pdf_invoices_bulk_download_files_' . $this->user_id;
		$files_to_zip   = get_transient( $transient_name );
		$files_to_zip   = false === $files_to_zip ? array() : $files_to_zip;

		$invoice      = new BEWPI_Invoice( $order_id );
		$invoice_path = $invoice->get_full_path();

		if ( ! file_exists( $invoice_path ) ) {
			$files_to_zip[] = $invoice->generate();
		} else {
			$files_to_zip[] = $invoice_path;
		}

		set_transient( $transient_name, $files_to_zip, HOUR_IN_SECONDS );
	}

	/**
	 * Create invoices packing slips zip from orders.
	 *
	 * @since 1.0.0
	 * @param array $order_id Order ID.
	 */
	private function wc_pdf_invoices_packing_slips( $order_id ) {
		$transient_name = 'wc_pdf_invoices_bulk_download_files_' . $this->user_id;
		$files_to_zip   = get_transient( $transient_name );
		$files_to_zip   = false === $files_to_zip ? array() : $files_to_zip;

		$document = wcpdf_get_document( 'invoice', wc_get_order( $order_id ), true );
		if ( $document ) {
			$uploaded_pdf = wp_upload_bits( $document->get_filename(), null, $document->get_pdf(), 'temp' );

			if ( ! empty( $uploaded_pdf ) && false === $uploaded_pdf['error'] ) {
				$files_to_zip[] = $uploaded_pdf['file'];
			}
		}

		set_transient( $transient_name, $files_to_zip, HOUR_IN_SECONDS );
	}

	/**
	 * Run the process.
	 *
	 * @since 2.0.0
	 * @param array $params Process data.
	 *
	 * @throws Exception Throws errors when occurred.
	 * @return array
	 */
	private function run( $params ) {
		$this->start_time = time();
		$index            = 0;

		$results = array(
			'percentage'   => 0,
			'position'     => 0,
			'download_url' => '',
		);

		$batch_size = $params['batch_size'];
		$position   = $params['position'];

		// Fetch the parsed data based on your action using conditional statements.
		$order_ids = $this->get_parsed_data( $position, $batch_size );

		if ( empty( $this->limit ) || empty( $order_ids ) ) {
			throw new Exception( __( 'No Orders found.', 'wc-pdf-invoices-bulk-download' ) );
		}

		foreach ( $order_ids as $order_id ) {
			$this->generate_pdf( $order_id );

			$index ++;
			if ( $this->time_exceeded( $this->start_time ) || $this->memory_exceeded() ) {
				break;
			}
		}

		$position              = $index + $position;
		$percentage            = ( $position * 100 ) / $this->limit;
		$results['position']   = $position;
		$results['percentage'] = absint( $percentage ) >= 100 ? 100 : number_format( $percentage, 2 );

		if ( 100 <= $results['percentage'] ) {
			$archive_name = $this->get_archive_name();
			if ( class_exists( 'BEWPI_Invoice' ) ) {
				$results['download_url'] = $this->create_zip(
					$archive_name,
					array( 'overwrite' => true )
				);
			} else {
				$results['download_url'] = $this->create_zip(
					$archive_name,
					array(
						'overwrite'                => true,
						'remove_pdf_after_process' => true,
					),
					function ( $files_to_zip ) {
						if ( ! empty( $files_to_zip ) ) {
							$file_zip = $files_to_zip[0];
                            @rmdir( dirname( $file_zip ) ); // @codingStandardsIgnoreLine
						}
					}
				);
			}
			delete_transient( 'wc_pdf_invoices_bulk_download_processing_' . $this->user_id );
		}

		return $results;
	}

	/**
	 * Create zip.
	 *
	 * @since 1.0.0
	 *
	 * @param string $destination destination path.
	 * @param array  $args arguments.
	 * @param mixed  $callback callback function.
	 */
	public function create_zip( $destination = '', $args = array(), $callback = null ) {
		$transient_name = 'wc_pdf_invoices_bulk_download_files_' . $this->user_id;
		$files          = get_transient( $transient_name );
		$files          = false === $files ? array() : $files;

		if ( empty( $files ) ) {
			return false;
		}

		$defaults = array(
			'overwrite'                => false,
			'remove_pdf_after_process' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		$upload_dir = wp_upload_dir();
		$dest_path  = trailingslashit( path_join( $upload_dir['basedir'], 'wc-invoice-archives/' ) );
		$dest_url   = trailingslashit( path_join( $upload_dir['baseurl'], 'wc-invoice-archives/' ) );

		if ( file_exists( $dest_path . $destination ) && ! $args['overwrite'] ) {
			return $dest_url . $destination;
		}

		$dest_path .= $destination;
		$dest_url  .= $destination;

		$valid_files = array();

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( file_exists( $file ) ) {
					$valid_files[] = $file;
				}
			}
		}

		if ( count( $valid_files ) ) {
			$zip = new \PHPZip\Zip\File\Zip();
			$zip->setZipFile( $dest_path );

			foreach ( $valid_files as $file ) {
				$zip->addFile( file_get_contents( $file ), basename( $file ), filectime( $file ) ); // @codingStandardsIgnoreLine

				if ( true === $args['remove_pdf_after_process'] ) {
					@unlink( $file ); // @codingStandardsIgnoreLine
				}
			}

			$zip->finalize();
		}

		if ( is_callable( $callback ) ) {
			call_user_func( $callback, $files );
		}

		if ( file_exists( $dest_path ) ) {
			return $dest_url;
		}

		return false;
	}

	/**
	 * Return archive name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_archive_name() {
		return sprintf( 'Invoices-%s.zip', time() );
	}
}
