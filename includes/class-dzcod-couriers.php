<?php
/**
 * Sending orders to the delivery company: settings, manual/bulk/automatic sending, tracking.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Courier integration manager.
 */
class DZCOD_Couriers {

	const OPTION = 'dzcod_couriers';
	const HOOK   = 'dzcod_send_parcel';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'provider'             => '',
			'auto_status'          => '',
			'yalidine_id'          => '',
			'yalidine_token'       => '',
			'yalidine_from_wilaya' => '',
			'procolis_token'       => '',
			'procolis_key'         => '',
			'procolis_confirmed'   => 1,
			'ecotrack_token'       => '',
			'ecotrack_url'         => '',
		);
	}

	/**
	 * Settings merged with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter(
			'option_page_capability_dzcod_couriers',
			function () {
				return 'manage_woocommerce';
			}
		);
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_auto_send' ), 20, 4 );
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ), 10, 1 );
		add_action( 'admin_post_dzcod_send_parcel', array( __CLASS__, 'admin_send' ) );
		add_action( 'admin_post_dzcod_courier_test', array( __CLASS__, 'admin_test' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );

		// Bulk action + tracking column, on both the classic and the HPOS orders screen.
		foreach ( array( 'edit-shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( __CLASS__, 'bulk_action' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		}
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'column' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_cpt' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'dzcod_couriers',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitize courier settings. Empty secret fields keep the saved value.
	 *
	 * @param array $in Input.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$old = self::settings();
		$in  = is_array( $in ) ? $in : array();
		$out = $old;

		$provider        = isset( $in['provider'] ) ? sanitize_key( $in['provider'] ) : '';
		$out['provider'] = ( '' === $provider || isset( DZCOD_Courier_API::providers()[ $provider ] ) ) ? $provider : '';

		$status             = isset( $in['auto_status'] ) ? sanitize_key( $in['auto_status'] ) : '';
		$valid              = array_map(
			function ( $s ) {
				return substr( $s, 3 );
			},
			array_keys( wc_get_order_statuses() )
		);
		$out['auto_status'] = in_array( $status, $valid, true ) ? $status : '';

		foreach ( array( 'yalidine_id', 'yalidine_from_wilaya', 'procolis_token', 'ecotrack_url' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		foreach ( array( 'yalidine_token', 'procolis_key', 'ecotrack_token' ) as $k ) {
			if ( isset( $in[ $k ] ) && '' !== trim( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		$out['procolis_confirmed'] = empty( $in['procolis_confirmed'] ) ? 0 : 1;
		$out['ecotrack_url']       = DZCOD_Courier_API::ecotrack_origin( $out['ecotrack_url'] );
		return $out;
	}

	/**
	 * Name of the configured company.
	 *
	 * @return string
	 */
	public static function provider_title() {
		$s = self::settings();
		$p = DZCOD_Courier_API::providers();
		return isset( $p[ $s['provider'] ] ) ? $p[ $s['provider'] ][0] : '';
	}

	/**
	 * Build the neutral parcel from an order. Works for form orders and normal checkout orders.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function parcel_from_order( $order ) {
		$all  = DZCOD_Locations::all();
		$code = (string) $order->get_meta( '_dzcod_wilaya' );
		if ( '' === $code ) {
			$state = (string) $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
			$code  = str_pad( preg_replace( '/\D/', '', $state ), 2, '0', STR_PAD_LEFT );
		}
		// Couriers still work with the 58 historical wilayas: send new wilayas under their parent.
		$legacy = isset( $all[ $code ]['l'] ) ? $all[ $code ]['l'] : $code;

		$products = array();
		foreach ( $order->get_items() as $item ) {
			$products[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$commune = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
		$address = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1();

		$parcel = array(
			'reference'   => (string) $order->get_order_number(),
			'name'        => $name,
			'phone'       => DZCOD_Antifraud::normalize_phone( $order->get_billing_phone() ),
			'wilaya_code' => $legacy,
			'wilaya_name' => isset( $all[ $legacy ] ) ? $all[ $legacy ]['n'] : '',
			'commune'     => (string) $commune,
			'address'     => '' !== (string) $address ? (string) $address : (string) $commune,
			'amount'      => (float) $order->get_total(),
			'products'    => implode( ', ', $products ),
			'stopdesk'    => 'desk' === $order->get_meta( '_dzcod_delivery_type' ),
			'note'        => (string) $order->get_customer_note(),
		);
		return (array) apply_filters( 'dzcod_courier_parcel', $parcel, $order );
	}

	/**
	 * Send one order to the configured company.
	 *
	 * @param int  $order_id Order id.
	 * @param bool $force    Send again even if a tracking number exists.
	 * @return array Result from DZCOD_Courier_API.
	 */
	public static function send( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );
		$s     = self::settings();
		if ( ! $order ) {
			return array(
				'ok'    => false,
				'error' => __( 'Order not found.', 'dz-cod-order-form' ),
			);
		}
		if ( '' === $s['provider'] ) {
			return array(
				'ok'    => false,
				'error' => __( 'No delivery company configured.', 'dz-cod-order-form' ),
			);
		}
		if ( ! $force && '' !== (string) $order->get_meta( '_dzcod_tracking' ) ) {
			return array(
				'ok'       => true,
				'tracking' => (string) $order->get_meta( '_dzcod_tracking' ),
				'error'    => '',
			);
		}

		// Lock: never create two parcels for the same order at the same time.
		$lock = 'dzcod_sending_' . $order_id;
		if ( get_transient( $lock ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'This order is already being sent.', 'dz-cod-order-form' ),
			);
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		$parcel = self::parcel_from_order( $order );
		$res    = DZCOD_Courier_API::create( $s['provider'], $s, $parcel );
		$title  = self::provider_title();

		if ( $res['ok'] ) {
			$order->update_meta_data( '_dzcod_courier', $s['provider'] );
			$order->update_meta_data( '_dzcod_tracking', $res['tracking'] );
			$order->update_meta_data( '_dzcod_label', esc_url_raw( $res['label'] ) );
			$order->delete_meta_data( '_dzcod_courier_error' );
			/* translators: 1: company, 2: tracking */
			$order->add_order_note( sprintf( __( 'Parcel created at %1$s. Tracking: %2$s', 'dz-cod-order-form' ), $title, $res['tracking'] ) );
			$order->save();
			do_action( 'dzcod_parcel_created', $order, $res['tracking'], $s['provider'] );
		} else {
			$order->update_meta_data( '_dzcod_courier_error', $res['error'] );
			/* translators: 1: company, 2: error */
			$order->add_order_note( sprintf( __( 'Could not send to %1$s: %2$s', 'dz-cod-order-form' ), $title, $res['error'] ) );
			$order->save();
		}
		delete_transient( $lock );
		return $res;
	}

	/**
	 * Queue an order for sending in the background (Action Scheduler, bundled with WooCommerce).
	 *
	 * @param int $order_id Order id.
	 */
	public static function queue( $order_id ) {
		$args = array( 'order_id' => (int) $order_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, $args, 'dzcod' ) ) {
				as_enqueue_async_action( self::HOOK, $args, 'dzcod' );
			}
		} else {
			self::send( $order_id );
		}
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $order_id Order id.
	 */
	public static function run_scheduled( $order_id ) {
		self::send( (int) $order_id );
	}

	/**
	 * Auto-send when an order reaches the chosen status. Suspected duplicates are never sent automatically.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public static function maybe_auto_send( $order_id, $from, $to, $order ) {
		$s = self::settings();
		if ( '' === $s['provider'] || '' === $s['auto_status'] || $to !== $s['auto_status'] || ! $order ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( '_dzcod_tracking' ) || $order->get_meta( '_dzcod_duplicate_of' ) ) {
			return;
		}
		self::queue( $order_id );
	}

	/**
	 * Capability check for sending.
	 *
	 * @return bool
	 */
	private static function can_send() {
		return current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * "Send to courier" button handler.
	 */
	public static function admin_send() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! self::can_send() ) {
			wp_die( esc_html__( 'Not allowed.', 'dz-cod-order-form' ) );
		}
		check_admin_referer( 'dzcod_send_parcel_' . $order_id );
		$force = ! empty( $_GET['force'] );
		self::send( $order_id, $force );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * "Test connection" handler.
	 */
	public static function admin_test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dz-cod-order-form' ) );
		}
		check_admin_referer( 'dzcod_courier_test' );
		$s   = self::settings();
		$res = DZCOD_Courier_API::test( $s['provider'], $s );
		set_transient( 'dzcod_courier_test_' . get_current_user_id(), $res, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=dzcod&tab=couriers&tested=1' ) );
		exit;
	}

	/**
	 * Order screen meta box.
	 */
	public static function meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box( 'dzcod-courier', __( 'Delivery company', 'dz-cod-order-form' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	/**
	 * Meta box content.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order ) {
			return;
		}
		$title = self::provider_title();
		if ( '' === $title ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dzcod&tab=couriers' ) ) . '">' . esc_html__( 'Connect a delivery company', 'dz-cod-order-form' ) . '</a></p>';
			return;
		}
		$tracking = (string) $order->get_meta( '_dzcod_tracking' );
		$label    = (string) $order->get_meta( '_dzcod_label' );
		$error    = (string) $order->get_meta( '_dzcod_courier_error' );
		$url      = wp_nonce_url( admin_url( 'admin-post.php?action=dzcod_send_parcel&order_id=' . $order->get_id() ), 'dzcod_send_parcel_' . $order->get_id() );

		if ( '' !== $tracking ) {
			echo '<p><strong>' . esc_html__( 'Tracking:', 'dz-cod-order-form' ) . '</strong> <code>' . esc_html( $tracking ) . '</code></p>';
			if ( '' !== $label ) {
				echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( $label ) . '">' . esc_html__( 'Print label', 'dz-cod-order-form' ) . '</a></p>';
			}
			echo '<p><a href="' . esc_url( add_query_arg( 'force', 1, $url ) ) . '" onclick="return confirm(\'' . esc_js( __( 'This creates a second parcel at the delivery company. Continue?', 'dz-cod-order-form' ) ) . '\');">' . esc_html__( 'Send again', 'dz-cod-order-form' ) . '</a></p>';
			return;
		}
		if ( '' !== $error ) {
			echo '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Fix the commune or address in the shipping details if needed, save, then try again.', 'dz-cod-order-form' ) . '</p>';
		}
		/* translators: %s: company */
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Send to %s', 'dz-cod-order-form' ), $title ) ) . '</a></p>';
	}

	/**
	 * Add the bulk action.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_action( $actions ) {
		$title = self::provider_title();
		if ( '' !== $title ) {
			/* translators: %s: company */
			$actions['dzcod_send'] = sprintf( __( 'Send to %s', 'dz-cod-order-form' ), $title );
		}
		return $actions;
	}

	/**
	 * Handle the bulk action: queue every selected order.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Order ids.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'dzcod_send' !== $action || ! self::can_send() ) {
			return $redirect;
		}
		foreach ( (array) $ids as $id ) {
			self::queue( absint( $id ) );
		}
		return add_query_arg( 'dzcod_queued', count( (array) $ids ), $redirect );
	}

	/**
	 * Notice after a bulk send.
	 */
	public static function bulk_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$n = isset( $_GET['dzcod_queued'] ) ? absint( $_GET['dzcod_queued'] ) : 0;
		if ( $n ) {
			/* translators: %d: number of orders */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d order is being sent to the delivery company. Tracking numbers appear in a moment.', '%d orders are being sent to the delivery company. Tracking numbers appear in a moment.', $n, 'dz-cod-order-form' ), $n ) ) . '</p></div>';
		}
	}

	/**
	 * Add tracking column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function column( $cols ) {
		$cols['dzcod_tracking'] = __( 'Tracking', 'dz-cod-order-form' );
		return $cols;
	}

	/**
	 * Column (classic screen).
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Order id.
	 */
	public static function column_cpt( $col, $post_id ) {
		if ( 'dzcod_tracking' === $col ) {
			self::column_output( wc_get_order( $post_id ) );
		}
	}

	/**
	 * Column (HPOS screen).
	 *
	 * @param string   $col   Column.
	 * @param WC_Order $order Order.
	 */
	public static function column_hpos( $col, $order ) {
		if ( 'dzcod_tracking' === $col ) {
			self::column_output( $order );
		}
	}

	/**
	 * Column content.
	 *
	 * @param WC_Order|false $order Order.
	 */
	private static function column_output( $order ) {
		if ( ! $order ) {
			return;
		}
		$t = (string) $order->get_meta( '_dzcod_tracking' );
		if ( '' !== $t ) {
			echo '<code>' . esc_html( $t ) . '</code>';
		} elseif ( $order->get_meta( '_dzcod_courier_error' ) ) {
			echo '<span style="color:#b32d2e" title="' . esc_attr( (string) $order->get_meta( '_dzcod_courier_error' ) ) . '">' . esc_html__( 'Error', 'dz-cod-order-form' ) . '</span>';
		} else {
			echo '–';
		}
	}

	/**
	 * Settings tab content (called from DZCOD_Admin).
	 */
	public static function render_settings() {
		$s     = self::settings();
		$n     = self::OPTION;
		$plat  = '';
		$prov  = DZCOD_Courier_API::providers();
		$plat  = isset( $prov[ $s['provider'] ] ) ? $prov[ $s['provider'] ][1] : '';
		$saved = esc_attr__( 'Saved — leave empty to keep', 'dz-cod-order-form' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! empty( $_GET['tested'] ) ) {
			$res = get_transient( 'dzcod_courier_test_' . get_current_user_id() );
			if ( is_array( $res ) ) {
				if ( $res['ok'] ) {
					echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Connection works.', 'dz-cod-order-form' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error inline"><p>' . esc_html( $res['error'] ) . '</p></div>';
				}
			}
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dzcod_couriers' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="dzcod-prov"><?php esc_html_e( 'Delivery company', 'dz-cod-order-form' ); ?></label></th><td>
					<select id="dzcod-prov" name="<?php echo esc_attr( $n ); ?>[provider]">
						<option value=""><?php esc_html_e( '— None —', 'dz-cod-order-form' ); ?></option>
						<?php foreach ( $prov as $key => $p ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" data-platform="<?php echo esc_attr( $p[1] ); ?>" <?php selected( $s['provider'], $key ); ?>><?php echo esc_html( $p[0] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Get your API credentials from your delivery company account (developer / API section).', 'dz-cod-order-form' ); ?></p>
				</td></tr>

				<tr class="dzcod-p dzcod-p-yalidine"><th><label for="dzcod-yid"><?php esc_html_e( 'API ID', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-yid" class="regular-text" name="<?php echo esc_attr( $n ); ?>[yalidine_id]" value="<?php echo esc_attr( $s['yalidine_id'] ); ?>" autocomplete="off"></td></tr>
				<tr class="dzcod-p dzcod-p-yalidine"><th><label for="dzcod-ytk"><?php esc_html_e( 'API token', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-ytk" type="password" class="regular-text" name="<?php echo esc_attr( $n ); ?>[yalidine_token]" value="" placeholder="<?php echo $s['yalidine_token'] ? $saved : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" autocomplete="new-password"></td></tr>
				<tr class="dzcod-p dzcod-p-yalidine"><th><label for="dzcod-yfrom"><?php esc_html_e( 'Ship from wilaya', 'dz-cod-order-form' ); ?></label></th><td>
					<select id="dzcod-yfrom" name="<?php echo esc_attr( $n ); ?>[yalidine_from_wilaya]">
						<?php foreach ( DZCOD_Locations::all() as $code => $w ) : ?>
							<?php
							if ( $w['l'] !== $code ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $w['n'] ); ?>" <?php selected( $s['yalidine_from_wilaya'], $w['n'] ); ?>><?php echo esc_html( $code . ' - ' . $w['n'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>

				<tr class="dzcod-p dzcod-p-procolis"><th><label for="dzcod-ptk"><?php esc_html_e( 'Token', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-ptk" class="regular-text" name="<?php echo esc_attr( $n ); ?>[procolis_token]" value="<?php echo esc_attr( $s['procolis_token'] ); ?>" autocomplete="off"></td></tr>
				<tr class="dzcod-p dzcod-p-procolis"><th><label for="dzcod-pkey"><?php esc_html_e( 'Key', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-pkey" type="password" class="regular-text" name="<?php echo esc_attr( $n ); ?>[procolis_key]" value="" placeholder="<?php echo $s['procolis_key'] ? $saved : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" autocomplete="new-password"></td></tr>
				<tr class="dzcod-p dzcod-p-procolis"><th><?php esc_html_e( 'Parcel status', 'dz-cod-order-form' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[procolis_confirmed]" value="1" <?php checked( ! empty( $s['procolis_confirmed'] ) ); ?>> <?php esc_html_e( 'Send parcels as confirmed (ready for pickup)', 'dz-cod-order-form' ); ?></label></td></tr>

				<tr class="dzcod-p dzcod-p-ecotrack-custom"><th><label for="dzcod-eurl"><?php esc_html_e( 'Ecotrack address', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-eurl" type="url" class="regular-text" name="<?php echo esc_attr( $n ); ?>[ecotrack_url]" value="<?php echo esc_attr( $s['ecotrack_url'] ); ?>" placeholder="https://yourcourier.ecotrack.dz"><p class="description"><?php esc_html_e( 'The address you use to log in to your courier’s Ecotrack account, e.g. https://ecorapide-express.ecotrack.dz', 'dz-cod-order-form' ); ?></p></td></tr>
				<tr class="dzcod-p dzcod-p-ecotrack"><th><label for="dzcod-etk"><?php esc_html_e( 'API token', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-etk" type="password" class="regular-text" name="<?php echo esc_attr( $n ); ?>[ecotrack_token]" value="" placeholder="<?php echo $s['ecotrack_token'] ? $saved : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" autocomplete="new-password"></td></tr>

				<tr><th><label for="dzcod-auto"><?php esc_html_e( 'Send automatically', 'dz-cod-order-form' ); ?></label></th><td>
					<select id="dzcod-auto" name="<?php echo esc_attr( $n ); ?>[auto_status]">
						<option value=""><?php esc_html_e( 'No — I send orders manually', 'dz-cod-order-form' ); ?></option>
						<?php foreach ( wc_get_order_statuses() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( substr( $key, 3 ) ); ?>" <?php selected( $s['auto_status'], substr( $key, 3 ) ); ?>>
								<?php /* translators: %s: order status */ echo esc_html( sprintf( __( 'When an order becomes “%s”', 'dz-cod-order-form' ), $label ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Tip: create orders as “Pending”, confirm them by phone, then mark them “Processing” to send them. Suspected duplicates are never sent automatically.', 'dz-cod-order-form' ); ?></p>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php if ( '' !== $plat ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dzcod_courier_test">
				<?php wp_nonce_field( 'dzcod_courier_test' ); ?>
				<?php submit_button( __( 'Test connection', 'dz-cod-order-form' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Orders from the 11 wilayas created in 2025 are sent under their former wilaya (e.g. Bou Saâda → 28 M’Sila), because delivery companies still use the 58 codes.', 'dz-cod-order-form' ); ?></p>
		<script>
		(function(){
			var sel=document.getElementById('dzcod-prov');
			function sync(){
				var o=sel.options[sel.selectedIndex],p=o?o.getAttribute('data-platform'):'';
				document.querySelectorAll('.dzcod-p').forEach(function(r){
					var show=r.classList.contains('dzcod-p-'+p)||(r.classList.contains('dzcod-p-ecotrack-custom')&&sel.value==='ecotrack');
					r.style.display=show?'':'none';
				});
			}
			sel.addEventListener('change',sync);sync();
		}());
		</script>
		<?php
	}
}
