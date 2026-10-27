<?php
/**
 * Quantity offers ("1 for 3,500 / 2 for 6,000 / 3 for 8,000"), set per product.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-product quantity offers.
 */
class DZCOD_Offers {

	const META = '_dzcod_offers';
	const MAX  = 6;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save' ) );
	}

	/**
	 * Clean list of offers for a product (parent id for variations), sorted by quantity.
	 * Each offer: qty, price (fixed pack total or null), percent (discount or null), label, badge, free_shipping.
	 *
	 * @param int $product_id Product id.
	 * @return array
	 */
	public static function get( $product_id ) {
		$raw = get_post_meta( $product_id, self::META, true );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $o ) {
			$qty = isset( $o['qty'] ) ? absint( $o['qty'] ) : 0;
			if ( $qty < 1 || $qty > 99 || isset( $out[ $qty ] ) ) {
				continue;
			}
			$price       = ( isset( $o['price'] ) && '' !== $o['price'] && is_numeric( $o['price'] ) ) ? max( 0, (float) $o['price'] ) : null;
			$percent     = ( isset( $o['percent'] ) && '' !== $o['percent'] && is_numeric( $o['percent'] ) ) ? min( 90, max( 0, (float) $o['percent'] ) ) : null;
			$out[ $qty ] = array(
				'qty'           => $qty,
				'price'         => $price,
				'percent'       => $percent,
				'label'         => isset( $o['label'] ) ? (string) $o['label'] : '',
				'badge'         => isset( $o['badge'] ) ? (string) $o['badge'] : '',
				'free_shipping' => ! empty( $o['free_shipping'] ),
			);
		}
		ksort( $out );
		return (array) apply_filters( 'dzcod_offers', array_values( array_slice( $out, 0, self::MAX ) ), $product_id );
	}

	/**
	 * Total price of an offer for a given unit price.
	 * A fixed pack price wins; otherwise the percentage discount; otherwise unit × qty.
	 *
	 * @param array $offer Offer.
	 * @param float $unit  Unit price.
	 * @return float
	 */
	public static function total( $offer, $unit ) {
		if ( null !== $offer['price'] ) {
			return (float) $offer['price'];
		}
		$total = (float) $unit * (int) $offer['qty'];
		if ( null !== $offer['percent'] ) {
			$total = $total * ( 1 - $offer['percent'] / 100 );
		}
		return round( $total, wc_get_price_decimals() );
	}

	/**
	 * Product edit screen meta box.
	 */
	public static function meta_box() {
		add_meta_box( 'dzcod-offers', __( 'COD form — quantity offers', 'dz-cod-order-form' ), array( __CLASS__, 'render' ), 'product', 'normal', 'default' );
	}

	/**
	 * Meta box content.
	 *
	 * @param WP_Post $post Product post.
	 */
	public static function render( $post ) {
		$saved = get_post_meta( $post->ID, self::META, true );
		$saved = is_array( $saved ) ? array_values( $saved ) : array();
		$cur   = get_woocommerce_currency_symbol();
		wp_nonce_field( 'dzcod_offers_save', 'dzcod_offers_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'Show clickable packs instead of the quantity selector, e.g. 1 for 3,500 · 2 for 6,000 · 3 for 8,000. Use a fixed pack price, or a % discount (works with every variation). Leave the quantity empty to remove a row.', 'dz-cod-order-form' ); ?></p>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr>
				<th><?php esc_html_e( 'Quantity', 'dz-cod-order-form' ); ?></th>
				<?php /* translators: %s: currency */ ?>
				<th><?php echo esc_html( sprintf( __( 'Pack price (%s)', 'dz-cod-order-form' ), $cur ) ); ?></th>
				<th><?php esc_html_e( 'or % off', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Title (optional)', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Badge (optional)', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Free delivery', 'dz-cod-order-form' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			for ( $i = 0; $i < self::MAX; $i++ ) :
				$o = wp_parse_args(
					isset( $saved[ $i ] ) ? (array) $saved[ $i ] : array(),
					array(
						'qty'           => '',
						'price'         => '',
						'percent'       => '',
						'label'         => '',
						'badge'         => '',
						'free_shipping' => 0,
					)
				);
				$b = 'dzcod_offers[' . $i . ']';
				?>
				<tr>
					<td><input type="number" min="1" max="99" step="1" style="width:70px" name="<?php echo esc_attr( $b ); ?>[qty]" value="<?php echo esc_attr( $o['qty'] ); ?>"></td>
					<td><input type="number" min="0" step="any" style="width:110px" name="<?php echo esc_attr( $b ); ?>[price]" value="<?php echo esc_attr( $o['price'] ); ?>"></td>
					<td><input type="number" min="0" max="90" step="any" style="width:70px" name="<?php echo esc_attr( $b ); ?>[percent]" value="<?php echo esc_attr( $o['percent'] ); ?>"></td>
					<td><input type="text" name="<?php echo esc_attr( $b ); ?>[label]" value="<?php echo esc_attr( $o['label'] ); ?>" placeholder="<?php esc_attr_e( 'Family pack', 'dz-cod-order-form' ); ?>"></td>
					<td><input type="text" name="<?php echo esc_attr( $b ); ?>[badge]" value="<?php echo esc_attr( $o['badge'] ); ?>" placeholder="<?php esc_attr_e( 'Best value', 'dz-cod-order-form' ); ?>"></td>
					<td><input type="checkbox" name="<?php echo esc_attr( $b ); ?>[free_shipping]" value="1" <?php checked( ! empty( $o['free_shipping'] ) ); ?>></td>
				</tr>
			<?php endfor; ?>
			</tbody>
		</table>
		<?php $up = self::upsell_settings( $post->ID ); ?>
		<?php $fd = self::free_delivery( $post->ID ); ?>
		<h4><?php esc_html_e( 'Free delivery for this product', 'dz-cod-order-form' ); ?></h4>
		<p>
			<select name="dzcod_free_delivery[mode]">
				<?php
				foreach ( array(
					''     => __( 'No — normal delivery prices', 'dz-cod-order-form' ),
					'all'  => __( 'Free home delivery and stop desk', 'dz-cod-order-form' ),
					'home' => __( 'Free home delivery only', 'dz-cod-order-form' ),
					'desk' => __( 'Free stop desk only', 'dz-cod-order-form' ),
				) as $val => $label ) :
					?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $fd['mode'], $val ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label style="margin-inline-start:12px"><?php esc_html_e( 'Only for these wilayas (codes, comma-separated, empty = all)', 'dz-cod-order-form' ); ?>
				<input type="text" class="regular-text" name="dzcod_free_delivery[wilayas]" value="<?php echo esc_attr( implode( ', ', $fd['wilayas'] ) ); ?>" placeholder="16, 09, 35, 42"></label>
		</p>
		<p class="description"><?php esc_html_e( 'The form shows a “Free delivery” badge and the delivery fee becomes 0 when the conditions match.', 'dz-cod-order-form' ); ?></p>
		<h4><?php esc_html_e( 'One-click upsell after the order', 'dz-cod-order-form' ); ?></h4>
		<p class="description"><?php esc_html_e( 'After ordering this product, offer one more product at a discount. The customer adds it to the same order with one tap — no new form.', 'dz-cod-order-form' ); ?></p>
		<p>
			<label><?php esc_html_e( 'Product ID to offer', 'dz-cod-order-form' ); ?> <input type="number" min="0" style="width:100px" name="dzcod_upsell[product]" value="<?php echo esc_attr( $up['product'] ? $up['product'] : '' ); ?>"></label>
			<label style="margin-inline-start:12px"><?php esc_html_e( 'Discount %', 'dz-cod-order-form' ); ?> <input type="number" min="0" max="90" step="any" style="width:70px" name="dzcod_upsell[discount]" value="<?php echo esc_attr( $up['discount'] ); ?>"></label>
			<label style="margin-inline-start:12px"><?php esc_html_e( 'Headline', 'dz-cod-order-form' ); ?> <input type="text" class="regular-text" name="dzcod_upsell[title]" value="<?php echo esc_attr( $up['title'] ); ?>" placeholder="<?php esc_attr_e( 'Add a second one at a special price?', 'dz-cod-order-form' ); ?>"></label>
		</p>
		<?php
	}

	/**
	 * Free delivery settings of a product.
	 *
	 * @param int $product_id Product (parent) id.
	 * @return array{mode:string,wilayas:string[]}
	 */
	public static function free_delivery( $product_id ) {
		$f    = get_post_meta( $product_id, '_dzcod_free_delivery', true );
		$f    = is_array( $f ) ? $f : array();
		$mode = isset( $f['mode'] ) && in_array( $f['mode'], array( 'all', 'home', 'desk' ), true ) ? $f['mode'] : '';
		$list = array();
		foreach ( isset( $f['wilayas'] ) ? (array) $f['wilayas'] : array() as $c ) {
			$c = str_pad( preg_replace( '/\D/', '', (string) $c ), 2, '0', STR_PAD_LEFT );
			if ( '00' !== $c && isset( DZCOD_Locations::all()[ $c ] ) ) {
				$list[] = $c;
			}
		}
		return (array) apply_filters(
			'dzcod_free_delivery',
			array(
				'mode'    => $mode,
				'wilayas' => array_values( array_unique( $list ) ),
			),
			$product_id
		);
	}

	/**
	 * Does free delivery apply to this wilaya and delivery type?
	 *
	 * @param array  $fd     free_delivery() result.
	 * @param string $wilaya Wilaya code.
	 * @param string $type   home|desk.
	 * @return bool
	 */
	public static function free_delivery_applies( $fd, $wilaya, $type ) {
		if ( '' === $fd['mode'] || ( 'all' !== $fd['mode'] && $fd['mode'] !== $type ) ) {
			return false;
		}
		return ! $fd['wilayas'] || in_array( str_pad( (string) $wilaya, 2, '0', STR_PAD_LEFT ), $fd['wilayas'], true );
	}

	/**
	 * Upsell settings of a product.
	 *
	 * @param int $product_id Product id.
	 * @return array{product:int,discount:float,title:string}
	 */
	public static function upsell_settings( $product_id ) {
		$u = get_post_meta( $product_id, '_dzcod_upsell', true );
		$u = is_array( $u ) ? $u : array();
		return array(
			'product'  => isset( $u['product'] ) ? absint( $u['product'] ) : 0,
			'discount' => isset( $u['discount'] ) ? min( 90, max( 0, (float) $u['discount'] ) ) : 0,
			'title'    => isset( $u['title'] ) ? (string) $u['title'] : '',
		);
	}

	/**
	 * Save offers.
	 *
	 * @param int $post_id Post id.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST['dzcod_offers_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['dzcod_offers_nonce'] ), 'dzcod_offers_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		$rows = isset( $_POST['dzcod_offers'] ) ? map_deep( (array) wp_unslash( $_POST['dzcod_offers'] ), 'sanitize_text_field' ) : array();
		$out  = array();
		foreach ( array_slice( $rows, 0, self::MAX ) as $r ) {
			$r   = (array) $r;
			$qty = isset( $r['qty'] ) ? absint( $r['qty'] ) : 0;
			if ( $qty < 1 ) {
				continue;
			}
			$out[] = array(
				'qty'           => min( 99, $qty ),
				'price'         => ( isset( $r['price'] ) && is_numeric( $r['price'] ) ) ? (string) max( 0, (float) $r['price'] ) : '',
				'percent'       => ( isset( $r['percent'] ) && is_numeric( $r['percent'] ) ) ? (string) min( 90, max( 0, (float) $r['percent'] ) ) : '',
				'label'         => isset( $r['label'] ) ? sanitize_text_field( $r['label'] ) : '',
				'badge'         => isset( $r['badge'] ) ? sanitize_text_field( $r['badge'] ) : '',
				'free_shipping' => empty( $r['free_shipping'] ) ? 0 : 1,
			);
		}
		if ( $out ) {
			update_post_meta( $post_id, self::META, $out );
		} else {
			delete_post_meta( $post_id, self::META );
		}

		$fd   = isset( $_POST['dzcod_free_delivery'] ) ? map_deep( (array) wp_unslash( $_POST['dzcod_free_delivery'] ), 'sanitize_text_field' ) : array();
		$mode = isset( $fd['mode'] ) ? sanitize_key( $fd['mode'] ) : '';
		if ( in_array( $mode, array( 'all', 'home', 'desk' ), true ) ) {
			$codes = array_filter( array_map( 'trim', explode( ',', isset( $fd['wilayas'] ) ? sanitize_text_field( $fd['wilayas'] ) : '' ) ), 'strlen' );
			update_post_meta(
				$post_id,
				'_dzcod_free_delivery',
				array(
					'mode'    => $mode,
					'wilayas' => array_values( $codes ),
				)
			);
		} else {
			delete_post_meta( $post_id, '_dzcod_free_delivery' );
		}

		$u  = isset( $_POST['dzcod_upsell'] ) ? map_deep( (array) wp_unslash( $_POST['dzcod_upsell'] ), 'sanitize_text_field' ) : array();
		$up = isset( $u['product'] ) ? absint( $u['product'] ) : 0;
		if ( $up && $up !== (int) $post_id ) {
			update_post_meta(
				$post_id,
				'_dzcod_upsell',
				array(
					'product'  => $up,
					'discount' => isset( $u['discount'] ) && is_numeric( $u['discount'] ) ? min( 90, max( 0, (float) $u['discount'] ) ) : 0,
					'title'    => isset( $u['title'] ) ? sanitize_text_field( $u['title'] ) : '',
				)
			);
		} else {
			delete_post_meta( $post_id, '_dzcod_upsell' );
		}
	}
}
