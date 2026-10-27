<?php
/**
 * Admin screens: settings tabs, CSV export, order details.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class DZCOD_Admin {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_dzcod_export_csv', array( __CLASS__, 'export_csv' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'order_details' ) );
	}

	/**
	 * Add submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'DZ COD Order Form', 'dz-cod-order-form' ),
			__( 'DZ COD Form', 'dz-cod-order-form' ),
			'manage_woocommerce',
			'dzcod',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Admin stylesheet on the plugin screen.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen check only.
		if ( isset( $_GET['page'] ) && 'dzcod' === $_GET['page'] ) {
			wp_enqueue_style( 'dzcod-admin', DZCOD_URL . 'assets/admin.css', array(), DZCOD_VERSION );
		}
	}

	/**
	 * Show wilaya, delivery type and duplicate warning on the order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_details( $order ) {
		if ( ! $order->get_meta( '_dzcod' ) ) {
			return;
		}
		$type = 'desk' === $order->get_meta( '_dzcod_delivery_type' ) ? __( 'Stop desk', 'dz-cod-order-form' ) : __( 'Home delivery', 'dz-cod-order-form' );
		echo '<p><strong>' . esc_html__( 'Wilaya:', 'dz-cod-order-form' ) . '</strong> ' . esc_html( $order->get_meta( '_dzcod_wilaya_label' ) ) . '<br>';
		echo '<strong>' . esc_html__( 'Delivery:', 'dz-cod-order-form' ) . '</strong> ' . esc_html( $type ) . '</p>';
		$p2 = (string) $order->get_meta( '_dzcod_phone2' );
		if ( '' !== $p2 ) {
			echo '<p><strong>' . esc_html__( 'Second phone:', 'dz-cod-order-form' ) . '</strong> <a href="tel:' . esc_attr( $p2 ) . '">' . esc_html( $p2 ) . '</a></p>';
		}
		foreach ( (array) $order->get_meta( '_dzcod_extra' ) as $label => $value ) {
			echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</p>';
		}
		$dup = (int) $order->get_meta( '_dzcod_duplicate_of' );
		if ( $dup ) {
			$link = get_edit_post_link( $dup );
			if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
				$link = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $dup );
			}
			/* translators: %s: link to earlier order */
			echo '<p style="color:#b32d2e"><strong>' . wp_kses_post( sprintf( __( 'Possible duplicate of %s', 'dz-cod-order-form' ), '<a href="' . esc_url( $link ) . '">#' . $dup . '</a>' ) ) . '</strong></p>';
		}
	}

	/**
	 * Settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tabs = array(
			'general'   => __( 'General', 'dz-cod-order-form' ),
			'fields'    => __( 'Form fields', 'dz-cod-order-form' ),
			'delivery'  => __( 'Delivery prices', 'dz-cod-order-form' ),
			'antifraud' => __( 'Anti-fraud', 'dz-cod-order-form' ),
			'couriers'  => __( 'Delivery company', 'dz-cod-order-form' ),
			'sheets'    => __( 'Google Sheets & export', 'dz-cod-order-form' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'general';
		$s   = DZCOD_Settings::get();
		$n   = DZCOD_Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DZ COD Order Form', 'dz-cod-order-form' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dzcod&tab=' . $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<?php if ( 'couriers' === $tab ) : ?>
				<?php DZCOD_Couriers::render_settings(); ?>
		</div>
				<?php
				return;
			endif;
			?>
			<form method="post" action="options.php">
				<?php settings_fields( 'dzcod' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>">
				<?php
				switch ( $tab ) {
					case 'fields':
						self::tab_fields( $s, $n );
						break;
					case 'delivery':
						self::tab_delivery( $s, $n );
						break;
					case 'antifraud':
						self::tab_antifraud( $s, $n );
						break;
					case 'sheets':
						self::tab_sheets( $s, $n );
						break;
					default:
						self::tab_general( $s, $n );
				}
				submit_button();
				?>
			</form>
			<?php if ( 'sheets' === $tab ) : ?>
				<?php self::tab_sheets_actions( $s ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Checkbox row helper.
	 *
	 * @param array  $s     Settings.
	 * @param string $n     Option name.
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private static function checkbox( $s, $n, $key, $label ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label><br>',
			esc_attr( $n ),
			esc_attr( $key ),
			checked( ! empty( $s[ $key ] ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * General tab.
	 *
	 * @param array  $s Settings.
	 * @param string $n Option name.
	 */
	private static function tab_general( $s, $n ) {
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Placement', 'dz-cod-order-form' ); ?></th><td>
				<?php
				self::checkbox( $s, $n, 'auto_insert', __( 'Show the form automatically on every product page', 'dz-cod-order-form' ) );
				self::checkbox( $s, $n, 'hide_add_to_cart', __( 'Hide the standard Add to cart button on those pages', 'dz-cod-order-form' ) );
				?>
				<p class="description"><?php echo wp_kses_post( __( 'Or place it anywhere with the shortcode <code>[dzcod_form]</code> (on a product page) or <code>[dzcod_form product_id="123"]</code>, or with the Elementor widget “DZ COD Order Form”.', 'dz-cod-order-form' ) ); ?></p>
			</td></tr>
			<tr><th><label for="dzcod-title"><?php esc_html_e( 'Form title', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-title" class="regular-text" name="<?php echo esc_attr( $n ); ?>[form_title]" value="<?php echo esc_attr( $s['form_title'] ); ?>"></td></tr>
			<tr><th><label for="dzcod-btn"><?php esc_html_e( 'Button text', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-btn" class="regular-text" name="<?php echo esc_attr( $n ); ?>[button_text]" value="<?php echo esc_attr( $s['button_text'] ); ?>"></td></tr>
			<tr><th><label for="dzcod-msg"><?php esc_html_e( 'Success message', 'dz-cod-order-form' ); ?></label></th><td><textarea id="dzcod-msg" class="large-text" rows="3" name="<?php echo esc_attr( $n ); ?>[success_message]"><?php echo esc_textarea( $s['success_message'] ); ?></textarea></td></tr>
			<tr><th><?php esc_html_e( 'Wilaya & commune names', 'dz-cod-order-form' ); ?></th><td>
				<select name="<?php echo esc_attr( $n ); ?>[name_lang]">
					<option value="ar" <?php selected( $s['name_lang'], 'ar' ); ?>><?php esc_html_e( 'Arabic (العربية)', 'dz-cod-order-form' ); ?></option>
					<option value="latin" <?php selected( $s['name_lang'], 'latin' ); ?>><?php esc_html_e( 'Latin (Français)', 'dz-cod-order-form' ); ?></option>
				</select>
			</td></tr>
			<tr><th><?php esc_html_e( 'Delivery options', 'dz-cod-order-form' ); ?></th><td>
				<?php self::checkbox( $s, $n, 'stopdesk_enabled', __( 'Offer stop desk (office pickup) delivery', 'dz-cod-order-form' ) ); ?>
				<p class="description"><a href="<?php echo esc_url( admin_url( 'admin.php?page=dzcod&tab=fields' ) ); ?>"><?php esc_html_e( 'Choose which fields the form shows in the Form fields tab →', 'dz-cod-order-form' ); ?></a></p>
			</td></tr>
			<tr><th><?php esc_html_e( 'New order status', 'dz-cod-order-form' ); ?></th><td>
				<select name="<?php echo esc_attr( $n ); ?>[order_status]">
					<?php foreach ( array( 'processing', 'pending', 'on-hold' ) as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $s['order_status'], $st ); ?>><?php echo esc_html( wc_get_order_status_name( $st ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Suspected duplicates are always set to On hold.', 'dz-cod-order-form' ); ?></p>
			</td></tr>
			<tr><th><?php esc_html_e( 'After ordering', 'dz-cod-order-form' ); ?></th><td><?php self::checkbox( $s, $n, 'redirect_thankyou', __( 'Redirect to the WooCommerce thank-you page (otherwise show the success message in place)', 'dz-cod-order-form' ) ); ?></td></tr>
			<tr><th><label for="dzcod-color"><?php esc_html_e( 'Main color', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-color" type="color" name="<?php echo esc_attr( $n ); ?>[primary_color]" value="<?php echo esc_attr( $s['primary_color'] ); ?>"></td></tr>
		</table>
		<?php
	}

	/**
	 * Form fields tab: built-in fields and custom fields.
	 *
	 * @param array  $s Settings.
	 * @param string $n Option name.
	 */
	private static function tab_fields( $s, $n ) {
		$names = array(
			'name'    => __( 'Full name', 'dz-cod-order-form' ),
			'phone'   => __( 'Phone', 'dz-cod-order-form' ),
			'phone2'  => __( 'Second phone (given to the courier)', 'dz-cod-order-form' ),
			'email'   => __( 'Email', 'dz-cod-order-form' ),
			'wilaya'  => __( 'Wilaya', 'dz-cod-order-form' ),
			'commune' => __( 'Commune', 'dz-cod-order-form' ),
			'address' => __( 'Address (hidden for stop desk)', 'dz-cod-order-form' ),
			'note'    => __( 'Order note', 'dz-cod-order-form' ),
			'qty'     => __( 'Quantity selector', 'dz-cod-order-form' ),
		);
		?>
		<h2><?php esc_html_e( 'Built-in fields', 'dz-cod-order-form' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Name, phone, wilaya and commune are always shown and required — orders and couriers need them.', 'dz-cod-order-form' ); ?></p>
		<table class="widefat striped dzcod-fields-table">
			<thead><tr>
				<th><?php esc_html_e( 'Field', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Show', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Required', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Label', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Placeholder', 'dz-cod-order-form' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $names as $key => $title ) :
				$f      = DZCOD_Settings::field( $key );
				$locked = in_array( $key, DZCOD_Settings::LOCKED_FIELDS, true );
				$base   = $n . '[fields][' . $key . ']';
				?>
				<tr>
					<td><strong><?php echo esc_html( $title ); ?></strong></td>
					<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[show]" value="1" <?php checked( (bool) $f['show'] ); ?> <?php disabled( $locked ); ?>></td>
					<td><?php if ( 'qty' !== $key ) : ?><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[required]" value="1" <?php checked( (bool) $f['required'] ); ?> <?php disabled( $locked ); ?>><?php endif; ?></td>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( $f['label'] ); ?>"></td>
					<td><?php if ( 'qty' !== $key ) : ?><input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[placeholder]" value="<?php echo esc_attr( $f['placeholder'] ); ?>"><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Custom fields', 'dz-cod-order-form' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Add your own questions (size, color, preferred call time…). Answers are saved on the order, shown on the order screen and sent to Google Sheets. Leave the label empty to remove a field.', 'dz-cod-order-form' ); ?></p>
		<table class="widefat striped dzcod-fields-table">
			<thead><tr>
				<th><?php esc_html_e( 'Label', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Type', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Choices (for dropdown, comma-separated)', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Placeholder', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Required', 'dz-cod-order-form' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			$saved = (array) $s['custom_fields'];
			$types = array(
				'text'     => __( 'Text', 'dz-cod-order-form' ),
				'textarea' => __( 'Long text', 'dz-cod-order-form' ),
				'select'   => __( 'Dropdown', 'dz-cod-order-form' ),
				'checkbox' => __( 'Checkbox', 'dz-cod-order-form' ),
			);
			for ( $i = 0; $i < 6; $i++ ) :
				$cf   = wp_parse_args( isset( $saved[ $i ] ) ? (array) $saved[ $i ] : array(), array( 'label' => '', 'type' => 'text', 'options' => '', 'placeholder' => '', 'required' => 0 ) );
				$base = $n . '[custom_fields][' . $i . ']';
				?>
				<tr>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( $cf['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Size', 'dz-cod-order-form' ); ?>"></td>
					<td><select name="<?php echo esc_attr( $base ); ?>[type]">
						<?php foreach ( $types as $t => $tl ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $cf['type'], $t ); ?>><?php echo esc_html( $tl ); ?></option>
						<?php endforeach; ?>
					</select></td>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[options]" value="<?php echo esc_attr( $cf['options'] ); ?>" placeholder="S, M, L, XL"></td>
					<td><input type="text" name="<?php echo esc_attr( $base ); ?>[placeholder]" value="<?php echo esc_attr( $cf['placeholder'] ); ?>"></td>
					<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[required]" value="1" <?php checked( ! empty( $cf['required'] ) ); ?>></td>
				</tr>
			<?php endfor; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Delivery prices tab.
	 *
	 * @param array  $s Settings.
	 * @param string $n Option name.
	 */
	private static function tab_delivery( $s, $n ) {
		$cur     = get_woocommerce_currency_symbol();
		$courier = DZCOD_Couriers::provider_title();
		?>
		<div class="dzcod-import">
			<h2><?php esc_html_e( 'Fill prices automatically', 'dz-cod-order-form' ); ?></h2>
			<div class="dzcod-import-grid">
				<div class="dzcod-import-card">
					<h3><span class="dashicons dashicons-cloud-saved"></span> <?php esc_html_e( 'From your delivery company', 'dz-cod-order-form' ); ?></h3>
					<?php if ( '' !== $courier ) : ?>
						<?php /* translators: %s: company */ ?>
						<p><?php echo esc_html( sprintf( __( 'Download your current %s rates (home and stop desk) for every wilaya.', 'dz-cod-order-form' ), $courier ) ); ?></p>
						<button type="button" class="button button-primary" data-dzcod-import="courier"><?php /* translators: %s: company */ echo esc_html( sprintf( __( 'Import from %s', 'dz-cod-order-form' ), $courier ) ); ?></button>
					<?php else : ?>
						<p><?php esc_html_e( 'Connect Yalidine, ZR Express or an Ecotrack courier first.', 'dz-cod-order-form' ); ?></p>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dzcod&tab=couriers' ) ); ?>"><?php esc_html_e( 'Connect a delivery company', 'dz-cod-order-form' ); ?></a>
					<?php endif; ?>
				</div>
				<div class="dzcod-import-card">
					<h3><span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'From a Google Sheet', 'dz-cod-order-form' ); ?></h3>
					<ol>
						<li><a href="<?php echo esc_url( DZCOD_URL . 'data/delivery-prices-template.csv' ); ?>" download><?php esc_html_e( 'Download the template', 'dz-cod-order-form' ); ?></a> <?php esc_html_e( '(or export your current prices below) and open it in Google Sheets (File → Import).', 'dz-cod-order-form' ); ?></li>
						<li><?php esc_html_e( 'Edit the home and desk columns. Set enabled to 0 for wilayas you do not deliver to.', 'dz-cod-order-form' ); ?></li>
						<li><?php esc_html_e( 'Share → “Anyone with the link can view”, then paste the link here.', 'dz-cod-order-form' ); ?></li>
					</ol>
					<input type="url" class="large-text" id="dzcod-sheet-url" name="<?php echo esc_attr( $n ); ?>[prices_sheet_url]" value="<?php echo esc_attr( $s['prices_sheet_url'] ); ?>" placeholder="https://docs.google.com/spreadsheets/d/…">
					<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[prices_sheet_auto]" value="1" <?php checked( ! empty( $s['prices_sheet_auto'] ) ); ?>> <?php esc_html_e( 'Sync from this sheet every day (save the settings to switch on)', 'dz-cod-order-form' ); ?></label></p>
					<button type="button" class="button button-primary" data-dzcod-import="sheet"><?php esc_html_e( 'Import from sheet now', 'dz-cod-order-form' ); ?></button>
					<a class="button-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dzcod_export_prices' ), 'dzcod_export_prices' ) ); ?>"><?php esc_html_e( 'Export current prices (CSV)', 'dz-cod-order-form' ); ?></a>
				</div>
			</div>
			<p class="dzcod-import-log" aria-live="polite"></p>
		</div>
		<script>
		(function(){
			var log=document.querySelector('.dzcod-import-log'),nonce=<?php echo wp_json_encode( wp_create_nonce( 'dzcod_import_prices' ) ); ?>,ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			function run(source,offset,btn){
				var fd=new FormData();fd.append('action','dzcod_import_prices');fd.append('nonce',nonce);fd.append('source',source);fd.append('offset',offset);
				if(source==='sheet'){fd.append('url',document.getElementById('dzcod-sheet-url').value);}
				fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
					var d=res.data||{};log.textContent=d.message||'';log.className='dzcod-import-log '+(d.ok?'is-ok':'is-error');
					if(d.ok&&!d.done){run(source,d.next,btn);return;}
					btn.disabled=false;
					if(d.ok){log.textContent+=' <?php echo esc_js( __( 'Reloading…', 'dz-cod-order-form' ) ); ?>';setTimeout(function(){location.reload();},1200);}
				}).catch(function(){btn.disabled=false;log.textContent='<?php echo esc_js( __( 'Request failed.', 'dz-cod-order-form' ) ); ?>';log.className='dzcod-import-log is-error';});
			}
			document.querySelectorAll('[data-dzcod-import]').forEach(function(b){b.addEventListener('click',function(){
				if(!confirm('<?php echo esc_js( __( 'This replaces the prices in the table below. Continue?', 'dz-cod-order-form' ) ); ?>')){return;}
				b.disabled=true;log.className='dzcod-import-log';log.textContent='<?php echo esc_js( __( 'Importing…', 'dz-cod-order-form' ) ); ?>';run(b.getAttribute('data-dzcod-import'),0,b);
			});});
		}());
		</script>
		<h2><?php esc_html_e( 'Prices per wilaya', 'dz-cod-order-form' ); ?></h2>
		<p><?php esc_html_e( 'Set the delivery price for each wilaya. Untick a wilaya to stop delivering there.', 'dz-cod-order-form' ); ?></p>
		<p>
			<?php esc_html_e( 'Apply to all:', 'dz-cod-order-form' ); ?>
			<input type="number" min="0" step="1" id="dzcod-bulk-home" placeholder="<?php esc_attr_e( 'Home', 'dz-cod-order-form' ); ?>" style="width:100px">
			<input type="number" min="0" step="1" id="dzcod-bulk-desk" placeholder="<?php esc_attr_e( 'Stop desk', 'dz-cod-order-form' ); ?>" style="width:100px">
			<button type="button" class="button" id="dzcod-bulk-apply"><?php esc_html_e( 'Apply', 'dz-cod-order-form' ); ?></button>
		</p>
		<table class="widefat striped" style="max-width:720px">
			<thead><tr>
				<th><?php esc_html_e( 'Deliver', 'dz-cod-order-form' ); ?></th>
				<th><?php esc_html_e( 'Wilaya', 'dz-cod-order-form' ); ?></th>
				<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Home', 'dz-cod-order-form' ), $cur ) ); ?></th>
				<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Stop desk', 'dz-cod-order-form' ), $cur ) ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( DZCOD_Locations::all() as $code => $w ) :
				$p    = $s['prices'][ $code ];
				$base = $n . '[prices][' . $code . ']';
				?>
				<tr>
					<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" <?php checked( ! empty( $p['enabled'] ) ); ?>></td>
					<td><?php echo esc_html( $code . ' - ' . $w['n'] . ' / ' . $w['ar'] ); ?></td>
					<td><input type="number" min="0" step="1" class="dzcod-home" name="<?php echo esc_attr( $base ); ?>[home]" value="<?php echo esc_attr( $p['home'] ); ?>" style="width:100px"></td>
					<td><input type="number" min="0" step="1" class="dzcod-desk" name="<?php echo esc_attr( $base ); ?>[desk]" value="<?php echo esc_attr( $p['desk'] ); ?>" style="width:100px"></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<table class="form-table" role="presentation">
			<tr><th><label for="dzcod-free"><?php esc_html_e( 'Free delivery from', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-free" type="number" min="0" step="1" name="<?php echo esc_attr( $n ); ?>[free_shipping_over]" value="<?php echo esc_attr( $s['free_shipping_over'] ); ?>"> <?php echo esc_html( $cur ); ?><p class="description"><?php esc_html_e( 'Order subtotal that makes delivery free. 0 = never.', 'dz-cod-order-form' ); ?></p></td></tr>
		</table>
		<script>
		document.getElementById('dzcod-bulk-apply').addEventListener('click',function(){
			var h=document.getElementById('dzcod-bulk-home').value,d=document.getElementById('dzcod-bulk-desk').value;
			if(h!==''){document.querySelectorAll('.dzcod-home').forEach(function(i){i.value=h;});}
			if(d!==''){document.querySelectorAll('.dzcod-desk').forEach(function(i){i.value=d;});}
		});
		</script>
		<?php
	}

	/**
	 * Anti-fraud tab.
	 *
	 * @param array  $s Settings.
	 * @param string $n Option name.
	 */
	private static function tab_antifraud( $s, $n ) {
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Always on', 'dz-cod-order-form' ); ?></th><td><?php esc_html_e( 'Algerian phone validation (05/06/07 mobiles and landlines, +213 accepted), hidden honeypot field against bots, commune must belong to the chosen wilaya.', 'dz-cod-order-form' ); ?></td></tr>
			<tr><th><label for="dzcod-dup"><?php esc_html_e( 'Duplicate window', 'dz-cod-order-form' ); ?></label></th><td>
				<input id="dzcod-dup" type="number" min="0" name="<?php echo esc_attr( $n ); ?>[dup_hours]" value="<?php echo esc_attr( $s['dup_hours'] ); ?>" style="width:80px"> <?php esc_html_e( 'hours (0 = off)', 'dz-cod-order-form' ); ?>
				<p class="description"><?php esc_html_e( 'A second order from the same phone within this window is a duplicate.', 'dz-cod-order-form' ); ?></p>
			</td></tr>
			<tr><th><?php esc_html_e( 'When a duplicate is detected', 'dz-cod-order-form' ); ?></th><td>
				<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[dup_action]" value="block" <?php checked( $s['dup_action'], 'block' ); ?>> <?php esc_html_e( 'Block it and tell the customer their first order is already received', 'dz-cod-order-form' ); ?></label><br>
				<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[dup_action]" value="flag" <?php checked( $s['dup_action'], 'flag' ); ?>> <?php esc_html_e( 'Accept it but put it On hold and flag it', 'dz-cod-order-form' ); ?></label>
			</td></tr>
			<tr><th><label for="dzcod-rl"><?php esc_html_e( 'Rate limit', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-rl" type="number" min="0" name="<?php echo esc_attr( $n ); ?>[rate_limit]" value="<?php echo esc_attr( $s['rate_limit'] ); ?>" style="width:80px"> <?php esc_html_e( 'orders per hour per IP address (0 = off)', 'dz-cod-order-form' ); ?></td></tr>
			<tr><th><label for="dzcod-ip"><?php esc_html_e( 'Visitor IP from', 'dz-cod-order-form' ); ?></label></th><td>
				<select id="dzcod-ip" name="<?php echo esc_attr( $n ); ?>[ip_source]">
					<option value="remote" <?php selected( $s['ip_source'], 'remote' ); ?>><?php esc_html_e( 'Direct connection (default)', 'dz-cod-order-form' ); ?></option>
					<option value="cloudflare" <?php selected( $s['ip_source'], 'cloudflare' ); ?>><?php esc_html_e( 'Cloudflare (CF-Connecting-IP)', 'dz-cod-order-form' ); ?></option>
					<option value="proxy" <?php selected( $s['ip_source'], 'proxy' ); ?>><?php esc_html_e( 'Reverse proxy / load balancer (X-Forwarded-For)', 'dz-cod-order-form' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Only change this if your site really is behind Cloudflare or a proxy. Otherwise all visitors share one IP and the rate limit blocks everyone; choosing it without a proxy lets attackers fake their IP.', 'dz-cod-order-form' ); ?></p>
			</td></tr>
			<tr><th><label for="dzcod-bl"><?php esc_html_e( 'Blocked phones', 'dz-cod-order-form' ); ?></label></th><td><textarea id="dzcod-bl" class="large-text code" rows="6" name="<?php echo esc_attr( $n ); ?>[blocked_phones]" placeholder="0555123456"><?php echo esc_textarea( $s['blocked_phones'] ); ?></textarea><p class="description"><?php esc_html_e( 'One number per line, e.g. customers who repeatedly refuse deliveries.', 'dz-cod-order-form' ); ?></p></td></tr>
		</table>
		<?php
	}

	/**
	 * Google Sheets tab.
	 *
	 * @param array  $s Settings.
	 * @param string $n Option name.
	 */
	private static function tab_sheets( $s, $n ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$test = isset( $_GET['dzcod_test'] ) ? sanitize_key( $_GET['dzcod_test'] ) : '';
		if ( 'ok' === $test ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Test row sent. Check your sheet.', 'dz-cod-order-form' ) . '</p></div>';
		} elseif ( 'fail' === $test ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The test failed. Check the web app URL, the secret, and that the Apps Script is deployed with access “Anyone”.', 'dz-cod-order-form' ) . '</p></div>';
		}
		?>
		<ol>
			<li><?php esc_html_e( 'Create a Google Sheet, then open Extensions → Apps Script.', 'dz-cod-order-form' ); ?></li>
			<li><?php echo wp_kses_post( __( 'Paste the script from the plugin file <code>google-apps-script/Code.gs</code> and set the same secret as below.', 'dz-cod-order-form' ) ); ?></li>
			<li><?php esc_html_e( 'Deploy → New deployment → Web app, execute as Me, access Anyone. Copy the URL here.', 'dz-cod-order-form' ); ?></li>
		</ol>
		<table class="form-table" role="presentation">
			<tr><th><label for="dzcod-url"><?php esc_html_e( 'Web app URL', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-url" type="url" class="large-text" name="<?php echo esc_attr( $n ); ?>[sheets_url]" value="<?php echo esc_attr( $s['sheets_url'] ); ?>" placeholder="https://script.google.com/macros/s/.../exec"></td></tr>
			<tr><th><label for="dzcod-secret"><?php esc_html_e( 'Secret', 'dz-cod-order-form' ); ?></label></th><td><input id="dzcod-secret" class="regular-text" name="<?php echo esc_attr( $n ); ?>[sheets_secret]" value="<?php echo esc_attr( $s['sheets_secret'] ); ?>"><p class="description"><?php esc_html_e( 'Any random text; it stops strangers from writing to your sheet.', 'dz-cod-order-form' ); ?></p></td></tr>
		</table>
		<?php
	}

	/**
	 * Buttons outside the settings form (test + CSV export).
	 *
	 * @param array $s Settings.
	 */
	private static function tab_sheets_actions( $s ) {
		?>
		<hr>
		<?php if ( '' !== $s['sheets_url'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px">
				<input type="hidden" name="action" value="dzcod_sheets_test">
				<?php wp_nonce_field( 'dzcod_sheets_test' ); ?>
				<?php submit_button( __( 'Send a test row', 'dz-cod-order-form' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="dzcod_export_csv">
			<?php wp_nonce_field( 'dzcod_export_csv' ); ?>
			<label><?php esc_html_e( 'Last', 'dz-cod-order-form' ); ?> <input type="number" name="days" value="30" min="1" style="width:70px"> <?php esc_html_e( 'days', 'dz-cod-order-form' ); ?></label>
			<?php submit_button( __( 'Export COD orders to CSV', 'dz-cod-order-form' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Stream a CSV of form orders (UTF-8 with BOM so Excel shows Arabic correctly).
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'dz-cod-order-form' ) );
		}
		check_admin_referer( 'dzcod_export_csv' );
		$days = isset( $_POST['days'] ) ? max( 1, absint( $_POST['days'] ) ) : 30;

		$ids = wc_get_orders(
			array(
				'limit'        => -1,
				'return'       => 'ids',
				'created_via'  => 'dzcod',
				'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=dz-cod-orders-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$header = false;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$row = DZCOD_Sheets::order_row( $order );
			if ( ! $header ) {
				fputcsv( $out, array_keys( $row ) );
				$header = true;
			}
			// Neutralise spreadsheet formula injection from customer input.
			$values = array_map(
				function ( $v ) {
					return ( is_string( $v ) && preg_match( '/^[=+\-@\t\r]/', $v ) ) ? "'" . $v : $v;
				},
				array_values( $row )
			);
			fputcsv( $out, $values );
		}
		if ( ! $header ) {
			fputcsv( $out, array( 'order_id', 'date', 'name', 'phone', 'wilaya', 'commune', 'address', 'delivery_type', 'products', 'shipping', 'total', 'status', 'duplicate_of', 'tracking', 'phone2', 'note', 'extra' ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
