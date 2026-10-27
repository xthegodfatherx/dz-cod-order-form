<?php
/**
 * COD dashboard: orders, confirmation, delivery and return rates, by product and wilaya.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sales dashboard.
 */
class DZCOD_Dashboard {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 50 );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'COD dashboard', 'dz-cod-order-form' ), __( 'COD dashboard', 'dz-cod-order-form' ), 'view_woocommerce_reports', 'dzcod-dashboard', array( __CLASS__, 'render' ) );
	}

	/**
	 * Outcome of an order: delivered, returned, cancelled, shipped (on its way), confirmed, new.
	 *
	 * @param WC_Order $o Order.
	 * @return string
	 */
	public static function state( $o ) {
		$ps = (string) $o->get_meta( '_dzcod_parcel_status' );
		$st = $o->get_status();
		if ( 'delivered' === $ps || 'completed' === $st ) {
			return 'delivered';
		}
		if ( 'returned' === $ps || DZCOD_Tracking::RETURNED === $st ) {
			return 'returned';
		}
		if ( in_array( $st, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			return 'cancelled';
		}
		if ( '' !== (string) $o->get_meta( '_dzcod_tracking' ) ) {
			return 'shipped';
		}
		if ( $o->get_meta( '_dzcod_confirmed' ) ) {
			return 'confirmed';
		}
		return 'new';
	}

	/**
	 * Compute all numbers for a period (cached 10 minutes).
	 *
	 * @param int $days Days back.
	 * @return array
	 */
	public static function data( $days ) {
		$key  = 'dzcod_dash_' . $days;
		$data = get_transient( $key );
		if ( is_array( $data ) ) {
			return $data;
		}
		$orders = wc_get_orders(
			array(
				'limit'        => 5000,
				'created_via'  => 'dzcod',
				'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
				'status'       => array_keys( wc_get_order_statuses() ),
			)
		);
		$all   = DZCOD_Locations::all();
		$t     = array_fill_keys( array( 'orders', 'new', 'confirmed', 'shipped', 'delivered', 'returned', 'cancelled', 'recovered', 'upsells' ), 0 );
		$money = array( 'delivered' => 0.0, 'pipeline' => 0.0, 'returned_shipping' => 0.0 );
		$prod  = array();
		$wil   = array();
		$daily = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$daily[ gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $i . ' days' ) ) ] = 0;
		}
		foreach ( $orders as $o ) {
			$state = self::state( $o );
			++$t['orders'];
			++$t[ $state ];
			if ( $o->get_meta( '_dzcod_recovered' ) ) {
				++$t['recovered'];
			}
			if ( $o->get_meta( '_dzcod_upsold' ) ) {
				++$t['upsells'];
			}
			$total = (float) $o->get_total();
			if ( 'delivered' === $state ) {
				$money['delivered'] += $total;
			} elseif ( in_array( $state, array( 'shipped', 'confirmed' ), true ) ) {
				$money['pipeline'] += $total;
			} elseif ( 'returned' === $state ) {
				$money['returned_shipping'] += (float) $o->get_shipping_total();
			}
			$day = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d' ) : '';
			if ( isset( $daily[ $day ] ) ) {
				++$daily[ $day ];
			}
			foreach ( $o->get_items() as $item ) {
				$pid = $item->get_product_id();
				if ( ! isset( $prod[ $pid ] ) ) {
					$prod[ $pid ] = array( 'name' => $item->get_name(), 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'revenue' => 0.0 );
				}
				++$prod[ $pid ]['orders'];
				if ( 'delivered' === $state ) {
					++$prod[ $pid ]['delivered'];
					$prod[ $pid ]['revenue'] += (float) $item->get_total();
				} elseif ( 'returned' === $state ) {
					++$prod[ $pid ]['returned'];
				}
			}
			$code = (string) $o->get_meta( '_dzcod_wilaya' );
			if ( isset( $all[ $code ] ) ) {
				$label = $code . ' - ' . $all[ $code ]['n'];
				if ( ! isset( $wil[ $label ] ) ) {
					$wil[ $label ] = array( 'orders' => 0, 'delivered' => 0, 'returned' => 0 );
				}
				++$wil[ $label ]['orders'];
				if ( 'delivered' === $state || 'returned' === $state ) {
					++$wil[ $label ][ $state ];
				}
			}
		}
		uasort(
			$prod,
			function ( $a, $b ) {
				return $b['orders'] - $a['orders'];
			}
		);
		uasort(
			$wil,
			function ( $a, $b ) {
				return $b['orders'] - $a['orders'];
			}
		);
		$data = array(
			'totals'    => $t,
			'money'     => $money,
			'products'  => array_slice( $prod, 0, 15, true ),
			'wilayas'   => array_slice( $wil, 0, 20, true ),
			'daily'     => $daily,
			'truncated' => count( $orders ) >= 5000,
		);
		set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Percentage helper.
	 *
	 * @param int $a Part.
	 * @param int $b Whole.
	 * @return string
	 */
	private static function pct( $a, $b ) {
		return $b ? round( 100 * $a / $b ) . '%' : '–';
	}

	/**
	 * Dashboard screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'view_woocommerce_reports' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- period switch only.
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		$d    = self::data( $days );
		$t    = $d['totals'];
		$done = $t['delivered'] + $t['returned'];
		$conf = $t['confirmed'] + $t['shipped'] + $done;
		$max  = max( 1, max( $d['daily'] ) );
		$cards = array(
			array( __( 'Orders', 'dz-cod-order-form' ), number_format_i18n( $t['orders'] ), '' ),
			/* translators: %s: count */
			array( __( 'Confirmed', 'dz-cod-order-form' ), self::pct( $conf, $t['orders'] ), sprintf( __( '%s orders', 'dz-cod-order-form' ), number_format_i18n( $conf ) ) ),
			/* translators: 1: delivered, 2: returned */
			array( __( 'Delivery rate', 'dz-cod-order-form' ), self::pct( $t['delivered'], $done ), sprintf( __( '%1$s delivered · %2$s returned', 'dz-cod-order-form' ), number_format_i18n( $t['delivered'] ), number_format_i18n( $t['returned'] ) ) ),
			array( __( 'Cash collected', 'dz-cod-order-form' ), wp_strip_all_tags( wc_price( $d['money']['delivered'] ) ), __( 'delivered orders', 'dz-cod-order-form' ) ),
			array( __( 'On the way', 'dz-cod-order-form' ), wp_strip_all_tags( wc_price( $d['money']['pipeline'] ) ), __( 'confirmed + shipped', 'dz-cod-order-form' ) ),
			array( __( 'Lost on returns', 'dz-cod-order-form' ), wp_strip_all_tags( wc_price( $d['money']['returned_shipping'] ) ), __( 'delivery fees of refused parcels', 'dz-cod-order-form' ) ),
			array( __( 'Recovered', 'dz-cod-order-form' ), number_format_i18n( $t['recovered'] ), __( 'from abandoned forms', 'dz-cod-order-form' ) ),
			array( __( 'Upsells', 'dz-cod-order-form' ), number_format_i18n( $t['upsells'] ), self::pct( $t['upsells'], $t['orders'] ) . ' ' . __( 'of orders', 'dz-cod-order-form' ) ),
		);
		?>
		<div class="wrap dzcod-dash">
			<h1><?php esc_html_e( 'COD dashboard', 'dz-cod-order-form' ); ?></h1>
			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( array( 7, 30, 90 ) as $p ) {
					/* translators: %d: days */
					$links[] = '<li><a href="' . esc_url( admin_url( 'admin.php?page=dzcod-dashboard&days=' . $p ) ) . '"' . ( $p === $days ? ' class="current"' : '' ) . '>' . esc_html( sprintf( __( 'Last %d days', 'dz-cod-order-form' ), $p ) ) . '</a></li>';
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				?>
			</ul>
			<div class="dzcod-cards">
				<?php foreach ( $cards as $c ) : ?>
					<div class="dzcod-card"><span><?php echo esc_html( $c[0] ); ?></span><strong><?php echo esc_html( $c[1] ); ?></strong><small><?php echo esc_html( $c[2] ); ?></small></div>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Orders per day', 'dz-cod-order-form' ); ?></h2>
			<div class="dzcod-bars" role="img" aria-label="<?php esc_attr_e( 'Orders per day', 'dz-cod-order-form' ); ?>">
				<?php foreach ( $d['daily'] as $day => $n ) : ?>
					<div class="dzcod-bar" title="<?php echo esc_attr( $day . ': ' . $n ); ?>"><i style="height:<?php echo esc_attr( round( 100 * $n / $max ) ); ?>%"></i></div>
				<?php endforeach; ?>
			</div>

			<div class="dzcod-dash-grid">
				<div>
					<h2><?php esc_html_e( 'Products', 'dz-cod-order-form' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Product', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'Orders', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'Delivery rate', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'Collected', 'dz-cod-order-form' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $d['products'] as $p ) : ?>
							<tr><td><?php echo esc_html( $p['name'] ); ?></td><td><?php echo esc_html( $p['orders'] ); ?></td><td><?php echo esc_html( self::pct( $p['delivered'], $p['delivered'] + $p['returned'] ) ); ?></td><td><?php echo esc_html( wp_strip_all_tags( wc_price( $p['revenue'] ) ) ); ?></td></tr>
						<?php endforeach; ?>
						<?php if ( ! $d['products'] ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No orders in this period.', 'dz-cod-order-form' ); ?></td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<div>
					<h2><?php esc_html_e( 'Wilayas', 'dz-cod-order-form' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Wilaya', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'Orders', 'dz-cod-order-form' ); ?></th><th><?php esc_html_e( 'Delivery rate', 'dz-cod-order-form' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $d['wilayas'] as $label => $w ) : ?>
							<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( $w['orders'] ); ?></td><td><?php echo esc_html( self::pct( $w['delivered'], $w['delivered'] + $w['returned'] ) ); ?></td></tr>
						<?php endforeach; ?>
						<?php if ( ! $d['wilayas'] ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No orders in this period.', 'dz-cod-order-form' ); ?></td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
			<p class="description">
				<?php esc_html_e( 'Form orders only. Delivered / returned come from your delivery company (parcel tracking) and from Completed / Returned order statuses. Figures refresh every 10 minutes.', 'dz-cod-order-form' ); ?>
				<?php if ( $d['truncated'] ) : ?>
					<?php esc_html_e( 'Only the latest 5,000 orders are counted.', 'dz-cod-order-form' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
