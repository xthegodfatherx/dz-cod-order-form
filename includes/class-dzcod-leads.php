<?php
/**
 * Abandoned orders: customers who typed a valid phone number but never confirmed.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lead capture, admin list and recovery.
 */
class DZCOD_Leads {

	const CPT     = 'dzcod_lead';
	const CLEANUP = 'dzcod_leads_cleanup';
	const GRACE   = 15; // Minutes before a lead counts as abandoned (the customer may still be typing).

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'wp_ajax_dzcod_capture', array( __CLASS__, 'ajax_capture' ) );
		add_action( 'wp_ajax_nopriv_dzcod_capture', array( __CLASS__, 'ajax_capture' ) );
		add_action( 'dzcod_order_created', array( __CLASS__, 'on_order_created' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_dzcod_lead', array( __CLASS__, 'admin_action' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule_cleanup' ) );
		add_action( self::CLEANUP, array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Is capture switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return ! empty( DZCOD_Settings::get( 'leads_enabled' ) );
	}

	/**
	 * Private post type used as storage (no public URLs, no default admin screens).
	 */
	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'label'               => __( 'Abandoned orders', 'dz-cod-order-form' ),
			)
		);
	}

	/**
	 * Read a POST field.
	 *
	 * @param string $key Field.
	 * @param int    $max Max length.
	 * @return string
	 */
	private static function post( $key, $max = 200 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in ajax_capture().
		$v = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		return mb_substr( $v, 0, $max );
	}

	/**
	 * Find a lead by its browser token.
	 *
	 * @param string $token Token.
	 * @return int Post id or 0.
	 */
	private static function find_by_token( $token ) {
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'name'           => 'lead-' . md5( $token ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Save or update the half-filled form (AJAX, debounced by the browser).
	 */
	public static function ajax_capture() {
		if ( ! self::enabled() || ! check_ajax_referer( 'dzcod_order', 'nonce', false ) ) {
			wp_send_json_error();
		}
		if ( '' !== self::post( 'dzcod_website' ) ) {
			wp_send_json_error(); // Honeypot.
		}
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', self::post( 'lead_token', 64 ) );
		$phone = DZCOD_Antifraud::normalize_phone( self::post( 'phone', 20 ) );
		if ( strlen( $token ) < 16 || ! DZCOD_Antifraud::is_valid_phone( $phone ) ) {
			wp_send_json_error();
		}
		$product_id = absint( self::post( 'product_id', 12 ) );
		$product    = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			wp_send_json_error();
		}

		$id = self::find_by_token( $token );
		if ( ! $id ) {
			// New leads are rate limited per visitor; updates to an existing lead are not.
			$ip  = DZCOD_Antifraud::client_ip();
			$key = 'dzcod_lead_rl_' . md5( $ip );
			if ( '' !== $ip && (int) get_transient( $key ) >= 10 ) {
				wp_send_json_error();
			}
			set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
			// Site-wide ceiling so many IPs cannot flood the database.
			$global = (int) get_transient( 'dzcod_lead_global' );
			if ( $global >= (int) apply_filters( 'dzcod_leads_per_hour', 300 ) ) {
				wp_send_json_error();
			}
			set_transient( 'dzcod_lead_global', $global + 1, HOUR_IN_SECONDS );
			self::flush_counts();
		}

		$name    = self::post( 'name', 100 );
		$wilaya  = self::post( 'wilaya', 2 );
		$commune = self::post( 'commune', 100 );
		$all     = DZCOD_Locations::all();
		if ( ! isset( $all[ $wilaya ] ) ) {
			$wilaya  = '';
			$commune = '';
		} elseif ( '' !== $commune && ! DZCOD_Locations::commune_exists( $wilaya, $commune ) ) {
			$commune = '';
		}

		$data = array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'post_title'  => trim( $name . ' — ' . $phone ),
			'post_name'   => 'lead-' . md5( $token ),
		);
		if ( $id ) {
			$data['ID'] = $id;
			wp_update_post( $data );
		} else {
			$id = wp_insert_post( $data );
			if ( ! $id || is_wp_error( $id ) ) {
				wp_send_json_error();
			}
			update_post_meta( $id, '_dzcod_status', 'new' );
		}
		$meta = array(
			'_dzcod_phone'         => $phone,
			'_dzcod_name'          => $name,
			'_dzcod_wilaya'        => $wilaya,
			'_dzcod_commune'       => $commune,
			'_dzcod_address'       => self::post( 'address', 200 ),
			'_dzcod_product_id'    => $product_id,
			'_dzcod_variation_id'  => absint( self::post( 'variation_id', 12 ) ),
			'_dzcod_qty'           => max( 1, min( 99, absint( self::post( 'qty', 3 ) ) ) ),
			'_dzcod_offer'         => self::post( 'offer', 2 ),
			'_dzcod_delivery_type' => 'desk' === self::post( 'delivery_type', 4 ) ? 'desk' : 'home',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		wp_send_json_success();
	}

	/**
	 * A real order came in: the customer is no longer "abandoned".
	 *
	 * @param WC_Order $order Order.
	 */
	public static function on_order_created( $order ) {
		$phone = DZCOD_Antifraud::normalize_phone( $order->get_billing_phone() );
		if ( '' === $phone ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small private table.
					'relation' => 'AND',
					array(
						'key'   => '_dzcod_phone',
						'value' => $phone,
					),
					array(
						'key'     => '_dzcod_status',
						'value'   => array( 'new', 'contacted' ),
						'compare' => 'IN',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		if ( $ids ) {
			self::flush_counts();
		}
	}

	/**
	 * Count of new abandoned orders (shown as a bubble in the menu).
	 *
	 * @return int
	 */
	public static function count_new() {
		$cached = get_transient( 'dzcod_lead_count_new' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$q = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array( array( 'before' => self::GRACE . ' minutes ago' ) ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_dzcod_status',
						'value' => 'new',
					),
				),
			)
		);
		set_transient( 'dzcod_lead_count_new', (int) $q->found_posts, 5 * MINUTE_IN_SECONDS );
		return (int) $q->found_posts;
	}

	/**
	 * Forget cached counts after a change.
	 */
	public static function flush_counts() {
		delete_transient( 'dzcod_lead_count_new' );
		delete_transient( 'dzcod_lead_count_any' );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		if ( ! self::enabled() && ! self::count_any() ) {
			return;
		}
		$n     = self::count_new();
		$label = __( 'Abandoned orders', 'dz-cod-order-form' );
		if ( $n ) {
			$label .= ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . number_format_i18n( $n ) . '</span></span>';
		}
		add_submenu_page( 'woocommerce', __( 'Abandoned orders', 'dz-cod-order-form' ), $label, 'edit_shop_orders', 'dzcod-leads', array( __CLASS__, 'render' ) );
	}

	/**
	 * Any leads at all (keeps the screen reachable after switching capture off).
	 *
	 * @return bool
	 */
	private static function count_any() {
		$cached = get_transient( 'dzcod_lead_count_any' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		set_transient( 'dzcod_lead_count_any', $ids ? 1 : 0, HOUR_IN_SECONDS );
		return ! empty( $ids );
	}

	/**
	 * International WhatsApp link for an Algerian number.
	 *
	 * @param string $phone 0XXXXXXXXX.
	 * @param string $text  Prefilled message.
	 * @return string
	 */
	public static function whatsapp_url( $phone, $text ) {
		return 'https://wa.me/213' . substr( $phone, 1 ) . '?text=' . rawurlencode( $text );
	}

	/**
	 * Admin list.
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters only.
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'new';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$notice = isset( $_GET['dzcod_msg'] ) ? sanitize_key( $_GET['dzcod_msg'] ) : '';
		// phpcs:enable
		$statuses = array(
			'new'       => __( 'To call', 'dz-cod-order-form' ),
			'contacted' => __( 'Contacted', 'dz-cod-order-form' ),
			'recovered' => __( 'Recovered', 'dz-cod-order-form' ),
			'lost'      => __( 'Lost', 'dz-cod-order-form' ),
		);
		$status = isset( $statuses[ $status ] ) ? $status : 'new';
		$args   = array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_dzcod_status',
					'value' => $status,
				),
			),
		);
		if ( 'new' === $status ) {
			$args['date_query'] = array( array( 'before' => self::GRACE . ' minutes ago' ) );
		}
		$q   = new WP_Query( $args );
		$all = DZCOD_Locations::all();
		$msg = array(
			'created'  => array( 'success', __( 'Order created. You will find it in WooCommerce → Orders.', 'dz-cod-order-form' ) ),
			'updated'  => array( 'success', __( 'Updated.', 'dz-cod-order-form' ) ),
			'deleted'  => array( 'success', __( 'Deleted.', 'dz-cod-order-form' ) ),
			'noproduct' => array( 'error', __( 'The product is no longer available.', 'dz-cod-order-form' ) ),
			'failed'   => array( 'error', __( 'The order could not be created.', 'dz-cod-order-form' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Abandoned orders', 'dz-cod-order-form' ); ?></h1>
			<p class="description">
				<?php
				/* translators: 1: minutes, 2: days */
				echo esc_html( sprintf( __( 'Visitors who typed a valid phone number on the order form but did not confirm. They appear here after %1$d minutes and are deleted automatically after %2$d days. Call them, then create the order in one click.', 'dz-cod-order-form' ), self::GRACE, (int) DZCOD_Settings::get( 'leads_retention' ) ) );
				?>
			</p>
			<?php if ( isset( $msg[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msg[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $msg[ $notice ][1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! self::enabled() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Capture is switched off in DZ COD Form → General.', 'dz-cod-order-form' ); ?></p></div>
			<?php endif; ?>
			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $statuses as $key => $label ) {
					$links[] = '<li><a href="' . esc_url( admin_url( 'admin.php?page=dzcod-leads&status=' . $key ) ) . '"' . ( $key === $status ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				?>
			</ul>
			<table class="widefat striped" style="clear:both">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Wilaya / commune', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Product', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'dz-cod-order-form' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $q->have_posts() ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing here.', 'dz-cod-order-form' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $q->posts as $post ) :
					$id      = $post->ID;
					$phone   = (string) get_post_meta( $id, '_dzcod_phone', true );
					$name    = (string) get_post_meta( $id, '_dzcod_name', true );
					$wcode   = (string) get_post_meta( $id, '_dzcod_wilaya', true );
					$commune = (string) get_post_meta( $id, '_dzcod_commune', true );
					$pid     = (int) get_post_meta( $id, '_dzcod_product_id', true );
					$qty     = (int) get_post_meta( $id, '_dzcod_qty', true );
					$order   = (int) get_post_meta( $id, '_dzcod_order_id', true );
					$product = wc_get_product( $pid );
					$pname   = $product ? $product->get_name() : '#' . $pid;
					/* translators: 1: name, 2: product */
					$wa_text = sprintf( __( 'Hello %1$s, we saw your order request for %2$s. Shall we confirm it for delivery?', 'dz-cod-order-form' ), $name, $pname );
					$act     = function ( $do, $label, $class = 'button' ) use ( $id ) {
						$url = wp_nonce_url( admin_url( 'admin-post.php?action=dzcod_lead&do=' . $do . '&lead=' . $id ), 'dzcod_lead_' . $id );
						return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
					};
					?>
					<tr>
						<td><?php echo esc_html( human_time_diff( get_post_time( 'U', true, $post ), time() ) ); ?></td>
						<td>
							<strong><?php echo esc_html( '' !== $name ? $name : '—' ); ?></strong><br>
							<a href="tel:<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( $phone ); ?></a>
							· <a href="<?php echo esc_url( self::whatsapp_url( $phone, $wa_text ) ); ?>" target="_blank" rel="noopener">WhatsApp</a>
						</td>
						<td><?php echo esc_html( isset( $all[ $wcode ] ) ? $wcode . ' - ' . $all[ $wcode ]['n'] . ( '' !== $commune ? ' / ' . $commune : '' ) : '—' ); ?></td>
						<td><?php echo esc_html( $pname . ' × ' . max( 1, $qty ) ); ?></td>
						<td>
							<?php
							if ( $order ) {
								echo '<a href="' . esc_url( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order ) : get_edit_post_link( $order ) ) . '">#' . esc_html( $order ) . '</a> ';
							} else {
								echo $act( 'create', __( 'Create order', 'dz-cod-order-form' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $act.
								if ( 'contacted' !== $status ) {
									echo $act( 'contacted', __( 'Called', 'dz-cod-order-form' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								if ( 'lost' !== $status ) {
									echo $act( 'lost', __( 'Lost', 'dz-cod-order-form' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
							}
							echo $act( 'delete', __( 'Delete', 'dz-cod-order-form' ), 'button-link-delete' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			if ( $q->max_num_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'current' => $paged,
							'total'   => $q->max_num_pages,
						)
					)
				) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Row actions: create order, mark called / lost, delete.
	 */
	public static function admin_action() {
		$id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		if ( ! current_user_can( 'edit_shop_orders' ) || self::CPT !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dz-cod-order-form' ) );
		}
		check_admin_referer( 'dzcod_lead_' . $id );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=dzcod-leads' );
		$msg  = 'updated';

		if ( 'delete' === $do ) {
			wp_delete_post( $id, true );
			self::flush_counts();
			$msg = 'deleted';
		} elseif ( in_array( $do, array( 'contacted', 'lost' ), true ) ) {
			update_post_meta( $id, '_dzcod_status', $do );
			self::flush_counts();
		} elseif ( 'create' === $do ) {
			$msg = self::create_order( $id );
		}
		wp_safe_redirect( add_query_arg( 'dzcod_msg', $msg, remove_query_arg( 'dzcod_msg', $back ) ) );
		exit;
	}

	/**
	 * Turn a lead into a WooCommerce order (prices recalculated on the server).
	 *
	 * @param int $id Lead id.
	 * @return string Message key.
	 */
	private static function create_order( $id ) {
		// One order per lead: refuse a second click and parallel clicks from two staff members.
		if ( (int) get_post_meta( $id, '_dzcod_order_id', true ) ) {
			return 'created';
		}
		$lock = 'dzcod_lead_lock_' . $id;
		if ( get_transient( $lock ) ) {
			return 'failed';
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );
		$result = self::do_create_order( $id );
		delete_transient( $lock );
		self::flush_counts();
		return $result;
	}

	/**
	 * Build the order for a lead (called under a lock).
	 *
	 * @param int $id Lead id.
	 * @return string Message key.
	 */
	private static function do_create_order( $id ) {
		$pid       = (int) get_post_meta( $id, '_dzcod_product_id', true );
		$vid       = (int) get_post_meta( $id, '_dzcod_variation_id', true );
		$product   = wc_get_product( $vid ? $vid : $pid );
		if ( ! $product || $product->is_type( 'variable' ) || ! $product->is_purchasable() ) {
			return 'noproduct';
		}
		$wilaya  = (string) get_post_meta( $id, '_dzcod_wilaya', true );
		$type    = (string) get_post_meta( $id, '_dzcod_delivery_type', true );
		$qty     = max( 1, (int) get_post_meta( $id, '_dzcod_qty', true ) );
		$extras  = array( 'from_admin' => true );
		$offers  = DZCOD_Offers::get( $pid );
		$oi      = (string) get_post_meta( $id, '_dzcod_offer', true );
		if ( $offers && ctype_digit( $oi ) && isset( $offers[ (int) $oi ] ) ) {
			$offer                   = $offers[ (int) $oi ];
			$qty                     = $offer['qty'];
			$extras['line_total']    = DZCOD_Offers::total( $offer, (float) $product->get_price() );
			$extras['free_shipping'] = ! empty( $extras['free_shipping'] ) || $offer['free_shipping'];
		}
		if ( '' !== $wilaya && DZCOD_Offers::free_delivery_applies( DZCOD_Offers::free_delivery( $pid ), $wilaya, 'desk' === $type ? 'desk' : 'home' ) ) {
			$extras['free_shipping'] = true;
		}
		$shipping = '' !== $wilaya ? DZCOD_Settings::delivery_price( $wilaya, $type ) : 0;

		$order = DZCOD_Order::create_order(
			$product,
			$qty,
			(string) get_post_meta( $id, '_dzcod_name', true ),
			(string) get_post_meta( $id, '_dzcod_phone', true ),
			$wilaya,
			(string) get_post_meta( $id, '_dzcod_commune', true ),
			(string) get_post_meta( $id, '_dzcod_address', true ),
			'desk' === $type ? 'desk' : 'home',
			(float) $shipping,
			0,
			$extras
		);
		if ( is_wp_error( $order ) ) {
			return 'failed';
		}
		$order->add_order_note( __( 'Recovered from an abandoned order form. Check the address with the customer.', 'dz-cod-order-form' ) );
		$order->update_meta_data( '_dzcod_recovered', 1 );
		$order->save();
		update_post_meta( $id, '_dzcod_status', 'recovered' );
		update_post_meta( $id, '_dzcod_order_id', $order->get_id() );
		// Same follow-up as a normal form order (Google Sheets, integrations).
		do_action( 'dzcod_order_created', $order );
		do_action( 'dzcod_lead_recovered', $order, $id );
		return 'created';
	}

	/**
	 * Daily cleanup schedule.
	 */
	public static function schedule_cleanup() {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::CLEANUP, array(), 'dzcod' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CLEANUP, array(), 'dzcod' );
		}
	}

	/**
	 * Delete leads older than the retention period.
	 */
	public static function cleanup() {
		$days = max( 1, (int) DZCOD_Settings::get( 'leads_retention' ) );
		$ids  = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array( array( 'before' => $days . ' days ago' ) ),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
