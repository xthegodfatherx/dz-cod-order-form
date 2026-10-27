<?php
/**
 * Confirmation desk: call new orders, record the outcome, track agents.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order confirmation workflow.
 */
class DZCOD_Desk {

	const LOG = 'dzcod_agent_log';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 55 );
		add_action( 'wp_ajax_dzcod_desk', array( __CLASS__, 'ajax' ) );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Confirm orders', 'dz-cod-order-form' ), __( 'Confirm orders', 'dz-cod-order-form' ), 'edit_shop_orders', 'dzcod-desk', array( __CLASS__, 'render' ) );
	}

	/**
	 * Orders waiting for a call.
	 *
	 * @param string $view to_call|no_answer|later.
	 * @return WC_Order[]
	 */
	public static function orders( $view ) {
		$orders = wc_get_orders(
			array(
				'limit'        => 100,
				'status'       => array( 'pending', 'on-hold', 'processing' ),
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_dzcod',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_dzcod_confirmed',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$now    = time();
		return array_values(
			array_filter(
				$orders,
				function ( $o ) use ( $view, $now ) {
					if ( '' !== (string) $o->get_meta( '_dzcod_tracking' ) ) {
						return false;
					}
					$later    = (int) $o->get_meta( '_dzcod_callback' );
					$attempts = (int) $o->get_meta( '_dzcod_attempts' );
					if ( 'later' === $view ) {
						return $later > $now;
					}
					if ( $later > $now ) {
						return false;
					}
					return 'no_answer' === $view ? $attempts > 0 : 0 === $attempts;
				}
			)
		);
	}

	/**
	 * WhatsApp message from the template.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function whatsapp_text( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' × ' . $item->get_quantity();
		}
		return strtr(
			(string) DZCOD_Settings::get( 'whatsapp_template' ),
			array(
				'{name}'    => $order->get_billing_first_name(),
				'{product}' => implode( ', ', $items ),
				'{total}'   => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total() ) ), ENT_QUOTES, 'UTF-8' ),
				'{order}'   => $order->get_order_number(),
				'{shop}'    => get_bloginfo( 'name' ),
			)
		);
	}

	/**
	 * Add one outcome to the daily agent log (kept 31 days).
	 *
	 * @param string $outcome Outcome.
	 */
	private static function log( $outcome ) {
		$log  = get_option( self::LOG, array() );
		$log  = is_array( $log ) ? $log : array();
		$day  = current_time( 'Y-m-d' );
		$user = (string) get_current_user_id();
		if ( ! isset( $log[ $day ][ $user ][ $outcome ] ) ) {
			$log[ $day ][ $user ][ $outcome ] = 0;
		}
		++$log[ $day ][ $user ][ $outcome ];
		ksort( $log );
		$log = array_slice( $log, -31, null, true );
		update_option( self::LOG, $log, false );
	}

	/**
	 * Apply an outcome to an order.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $outcome confirm|cancel|no_answer|later.
	 * @return string Short result for the row.
	 */
	public static function outcome( $order, $outcome ) {
		$s    = DZCOD_Settings::get();
		$user = wp_get_current_user();
		$who  = $user && $user->exists() ? $user->display_name : '';
		switch ( $outcome ) {
			case 'confirm':
				$order->update_meta_data( '_dzcod_confirmed', time() );
				$order->update_meta_data( '_dzcod_confirm_by', get_current_user_id() );
				$order->delete_meta_data( '_dzcod_callback' );
				$order->save();
				/* translators: %s: agent */
				$note = sprintf( __( 'Confirmed by phone (%s).', 'dz-cod-order-form' ), $who );
				if ( '' !== $s['confirmed_status'] && $order->get_status() !== $s['confirmed_status'] ) {
					$order->update_status( $s['confirmed_status'], $note );
				} else {
					$order->add_order_note( $note );
				}
				do_action( 'dzcod_order_confirmed', $order );
				$result = __( 'Confirmed', 'dz-cod-order-form' );
				break;
			case 'cancel':
				$order->update_meta_data( '_dzcod_confirmed', time() );
				$order->update_meta_data( '_dzcod_confirm_by', get_current_user_id() );
				$order->save();
				/* translators: %s: agent */
				$order->update_status( 'cancelled', sprintf( __( 'Cancelled by the customer on the phone (%s).', 'dz-cod-order-form' ), $who ) );
				$result = __( 'Cancelled', 'dz-cod-order-form' );
				break;
			case 'later':
				$order->update_meta_data( '_dzcod_callback', time() + 2 * HOUR_IN_SECONDS );
				/* translators: %s: agent */
				$order->add_order_note( sprintf( __( 'Customer asked to be called back later (%s).', 'dz-cod-order-form' ), $who ) );
				$order->save();
				$result = __( 'Call back in 2 hours', 'dz-cod-order-form' );
				break;
			default:
				$outcome  = 'no_answer';
				$attempts = (int) $order->get_meta( '_dzcod_attempts' ) + 1;
				$order->update_meta_data( '_dzcod_attempts', $attempts );
				$order->update_meta_data( '_dzcod_callback', time() + HOUR_IN_SECONDS );
				/* translators: 1: attempt number, 2: agent */
				$order->add_order_note( sprintf( __( 'No answer (attempt %1$d, %2$s).', 'dz-cod-order-form' ), $attempts, $who ) );
				$order->save();
				$max = (int) $s['max_attempts'];
				if ( $max > 0 && $attempts >= $max && ! empty( $s['auto_cancel'] ) ) {
					$order->update_meta_data( '_dzcod_confirmed', time() );
					$order->save();
					/* translators: %d: attempts */
					$order->update_status( 'cancelled', sprintf( __( 'Cancelled automatically after %d unanswered calls.', 'dz-cod-order-form' ), $attempts ) );
					$result = __( 'Cancelled (no answer)', 'dz-cod-order-form' );
				} else {
					/* translators: %d: attempts */
					$result = sprintf( __( 'No answer ×%d — retry in 1 hour', 'dz-cod-order-form' ), $attempts );
				}
		}
		self::log( $outcome );
		return $result;
	}

	/**
	 * AJAX outcome buttons.
	 */
	public static function ajax() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dz-cod-order-form' ) ) );
		}
		check_ajax_referer( 'dzcod_desk', 'nonce' );
		$order   = wc_get_order( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
		$outcome = isset( $_POST['outcome'] ) ? sanitize_key( $_POST['outcome'] ) : '';
		if ( ! $order || ! $order->get_meta( '_dzcod' ) || ! in_array( $outcome, array( 'confirm', 'cancel', 'no_answer', 'later' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'dz-cod-order-form' ) ) );
		}
		if ( $order->get_meta( '_dzcod_confirmed' ) ) {
			wp_send_json_error( array( 'message' => __( 'Already handled by someone else.', 'dz-cod-order-form' ) ) );
		}
		$lock = 'dzcod_desk_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			wp_send_json_error( array( 'message' => __( 'Someone is handling this order right now.', 'dz-cod-order-form' ) ) );
		}
		set_transient( $lock, get_current_user_id(), 30 );
		$msg = self::outcome( $order, $outcome );
		delete_transient( $lock );
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * Agent stats for the last 7 days.
	 *
	 * @return array user id => [ confirm, cancel, no_answer, later ]
	 */
	public static function stats() {
		$log  = get_option( self::LOG, array() );
		$from = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -6 days' ) );
		$out  = array();
		foreach ( (array) $log as $day => $users ) {
			if ( $day < $from ) {
				continue;
			}
			foreach ( (array) $users as $uid => $counts ) {
				foreach ( (array) $counts as $k => $v ) {
					$out[ $uid ][ $k ] = ( isset( $out[ $uid ][ $k ] ) ? $out[ $uid ][ $k ] : 0 ) + (int) $v;
				}
			}
		}
		return $out;
	}

	/**
	 * Desk screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
		$view   = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'to_call';
		$views  = array(
			'to_call'   => __( 'To call', 'dz-cod-order-form' ),
			'no_answer' => __( 'No answer', 'dz-cod-order-form' ),
			'later'     => __( 'Call later', 'dz-cod-order-form' ),
		);
		$view   = isset( $views[ $view ] ) ? $view : 'to_call';
		$orders = self::orders( $view );
		$s      = DZCOD_Settings::get();
		$all    = DZCOD_Locations::all();
		?>
		<div class="wrap dzcod-desk">
			<h1><?php esc_html_e( 'Confirm orders', 'dz-cod-order-form' ); ?></h1>
			<?php if ( $s['order_status'] === $s['confirmed_status'] ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					/* translators: %s: status */
					echo esc_html( sprintf( __( 'Tip: new orders and confirmed orders currently have the same status (%s). Set “New order status” to Pending payment in General, so only confirmed orders become Processing — and are sent to the delivery company if automatic sending is on.', 'dz-cod-order-form' ), wc_get_order_status_name( $s['confirmed_status'] ) ) );
					?>
				</p></div>
			<?php endif; ?>
			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $views as $k => $label ) {
					$links[] = '<li><a href="' . esc_url( admin_url( 'admin.php?page=dzcod-desk&view=' . $k ) ) . '"' . ( $k === $view ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ( $k === $view ? ' <span class="count">(' . count( $orders ) . ')</span>' : '' ) . '</a></li>';
				}
				echo wp_kses_post( implode( ' | ', $links ) );
				?>
			</ul>
			<table class="widefat striped" style="clear:both">
				<thead><tr>
					<th><?php esc_html_e( 'Order', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Delivery', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Products', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Total', 'dz-cod-order-form' ); ?></th>
					<th><?php esc_html_e( 'Outcome', 'dz-cod-order-form' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $orders ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing to call right now. 🎉', 'dz-cod-order-form' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $orders as $o ) :
					$phone = DZCOD_Antifraud::normalize_phone( $o->get_billing_phone() );
					$code  = (string) $o->get_meta( '_dzcod_wilaya' );
					$items = array();
					foreach ( $o->get_items() as $item ) {
						$items[] = $item->get_name() . ' × ' . $item->get_quantity();
					}
					$edit = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $o->get_id() ) : get_edit_post_link( $o->get_id() );
					?>
					<tr data-order="<?php echo esc_attr( $o->get_id() ); ?>">
						<td><a href="<?php echo esc_url( $edit ); ?>">#<?php echo esc_html( $o->get_order_number() ); ?></a><br><small><?php echo esc_html( human_time_diff( $o->get_date_created() ? $o->get_date_created()->getTimestamp() : time(), time() ) ); ?></small>
							<?php if ( (int) $o->get_meta( '_dzcod_attempts' ) ) : ?>
								<?php /* translators: %d: attempts */ ?>
								<br><small><?php echo esc_html( sprintf( __( '%d calls', 'dz-cod-order-form' ), (int) $o->get_meta( '_dzcod_attempts' ) ) ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ); ?></strong><br>
							<a class="dzcod-call" href="tel:<?php echo esc_attr( $phone ); ?>">📞 <?php echo esc_html( $phone ); ?></a>
							<?php if ( '' !== $phone ) : ?>
								· <a href="<?php echo esc_url( DZCOD_Leads::whatsapp_url( $phone, self::whatsapp_text( $o ) ) ); ?>" target="_blank" rel="noopener">WhatsApp</a>
							<?php endif; ?>
							<br><?php echo wp_kses_post( DZCOD_History::badge( DZCOD_History::for_phone( $phone, $o->get_id() ) ) ); ?>
						</td>
						<td><?php echo esc_html( ( isset( $all[ $code ] ) ? $code . ' - ' . $all[ $code ]['n'] : '' ) . ' / ' . $o->get_billing_city() ); ?><br><small><?php echo esc_html( 'desk' === $o->get_meta( '_dzcod_delivery_type' ) ? __( 'Stop desk', 'dz-cod-order-form' ) : $o->get_billing_address_1() ); ?></small></td>
						<td><?php echo esc_html( implode( ', ', $items ) ); ?></td>
						<td><?php echo wp_kses_post( $o->get_formatted_order_total() ); ?></td>
						<td class="dzcod-outcomes">
							<button type="button" class="button button-primary" data-outcome="confirm">✓ <?php esc_html_e( 'Confirmed', 'dz-cod-order-form' ); ?></button>
							<button type="button" class="button" data-outcome="no_answer">📵 <?php esc_html_e( 'No answer', 'dz-cod-order-form' ); ?></button>
							<button type="button" class="button" data-outcome="later">⏰ <?php esc_html_e( 'Later', 'dz-cod-order-form' ); ?></button>
							<button type="button" class="button-link-delete" data-outcome="cancel">✗ <?php esc_html_e( 'Cancelled', 'dz-cod-order-form' ); ?></button>
							<span class="dzcod-outcome-msg"></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Team — last 7 days', 'dz-cod-order-form' ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<thead><tr><th><?php esc_html_e( 'Agent', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'Confirmed', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'Cancelled', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'No answer', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'Confirmation rate', 'dz-cod-order-form' ); ?></th></tr></thead>
				<tbody>
				<?php
				$stats = self::stats();
				if ( ! $stats ) {
					echo '<tr><td colspan="5">' . esc_html__( 'No calls recorded yet.', 'dz-cod-order-form' ) . '</td></tr>';
				}
				foreach ( $stats as $uid => $c ) :
					$u    = get_userdata( (int) $uid );
					$ok   = isset( $c['confirm'] ) ? $c['confirm'] : 0;
					$ko   = isset( $c['cancel'] ) ? $c['cancel'] : 0;
					$na   = isset( $c['no_answer'] ) ? $c['no_answer'] : 0;
					$rate = ( $ok + $ko ) ? round( 100 * $ok / ( $ok + $ko ) ) . '%' : '–';
					?>
					<tr><td><?php echo esc_html( $u ? $u->display_name : '#' . $uid ); ?></td><td><?php echo esc_html( $ok ); ?></td><td><?php echo esc_html( $ko ); ?></td><td><?php echo esc_html( $na ); ?></td><td><?php echo esc_html( $rate ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
