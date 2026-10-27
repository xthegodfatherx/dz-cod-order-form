<?php
/**
 * "Request a feature": lets store owners ask for features, couriers or report bugs on GitHub.
 * Nothing is sent from the server: the browser opens a pre-filled GitHub issue the user can review first.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feedback screen.
 */
class DZCOD_Feedback {

	const REPO = 'https://github.com/xthegodfatherx/dz-cod-order-form';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
	}

	/**
	 * Submenu.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Request a feature', 'dz-cod-order-form' ), __( 'Request a feature', 'dz-cod-order-form' ), 'manage_woocommerce', 'dzcod-feedback', array( __CLASS__, 'render' ) );
	}

	/**
	 * Versions to attach to a request (no personal or store data).
	 *
	 * @return string
	 */
	private static function environment() {
		global $wp_version;
		return sprintf(
			'DZ COD Order Form %1$s · WordPress %2$s · WooCommerce %3$s · PHP %4$s',
			DZCOD_VERSION,
			$wp_version,
			defined( 'WC_VERSION' ) ? WC_VERSION : '?',
			PHP_VERSION
		);
	}

	/**
	 * Screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$types = array(
			'feature' => array( __( 'A new feature or improvement', 'dz-cod-order-form' ), 'feature_request.yml' ),
			'courier' => array( __( 'Add a delivery company', 'dz-cod-order-form' ), 'courier_request.yml' ),
			'bug'     => array( __( 'Something does not work', 'dz-cod-order-form' ), 'bug_report.yml' ),
		);
		?>
		<div class="wrap dzcod-feedback">
			<h1><?php esc_html_e( 'Request a feature', 'dz-cod-order-form' ); ?></h1>
			<p><?php esc_html_e( 'DZ COD Order Form is free and open source. Tell us what your store needs — new features, another delivery company, or a problem — and vote for other requests on GitHub.', 'dz-cod-order-form' ); ?></p>

			<form id="dzcod-feedback" class="dzcod-import-card" style="max-width:760px">
				<p><strong><?php esc_html_e( 'What is it about?', 'dz-cod-order-form' ); ?></strong></p>
				<?php foreach ( $types as $key => $t ) : ?>
					<label style="display:block;margin:4px 0"><input type="radio" name="type" value="<?php echo esc_attr( $key ); ?>" data-template="<?php echo esc_attr( $t[1] ); ?>" <?php checked( 'feature', $key ); ?>> <?php echo esc_html( $t[0] ); ?></label>
				<?php endforeach; ?>
				<p><label for="dzcod-fb-title"><strong><?php esc_html_e( 'Title', 'dz-cod-order-form' ); ?></strong></label><br>
					<input id="dzcod-fb-title" type="text" class="large-text" maxlength="120" required placeholder="<?php esc_attr_e( 'e.g. Send an SMS when the parcel is out for delivery', 'dz-cod-order-form' ); ?>"></p>
				<p><label for="dzcod-fb-body"><strong><?php esc_html_e( 'Details', 'dz-cod-order-form' ); ?></strong></label><br>
					<textarea id="dzcod-fb-body" class="large-text" rows="7" maxlength="3000" required placeholder="<?php esc_attr_e( 'What do you want to do, and why does it matter for your store?', 'dz-cod-order-form' ); ?>"></textarea></p>
				<p class="description"><?php esc_html_e( 'Do not include API tokens, passwords, customer names or phone numbers: GitHub issues are public.', 'dz-cod-order-form' ); ?></p>
				<p><label><input type="checkbox" id="dzcod-fb-env" checked> <?php echo esc_html( __( 'Add version information:', 'dz-cod-order-form' ) . ' ' . self::environment() ); ?></label></p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue on GitHub', 'dz-cod-order-form' ); ?></button>
					<span class="description"><?php esc_html_e( 'Opens GitHub in a new tab with your request filled in. You can review it before posting (a free GitHub account is needed).', 'dz-cod-order-form' ); ?></span>
				</p>
			</form>

			<h2><?php esc_html_e( 'Other ways to help', 'dz-cod-order-form' ); ?></h2>
			<ul class="ul-disc">
				<li><a href="<?php echo esc_url( self::REPO . '/issues?q=is%3Aissue+is%3Aopen+sort%3Areactions-%2B1-desc' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Vote 👍 on the most wanted requests', 'dz-cod-order-form' ); ?></a></li>
				<li><a href="<?php echo esc_url( self::REPO . '#roadmap--contributions-welcome' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See the roadmap', 'dz-cod-order-form' ); ?></a></li>
				<li><a href="<?php echo esc_url( self::REPO . '/blob/main/CONTRIBUTING.md' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Contribute code or translations', 'dz-cod-order-form' ); ?></a></li>
				<li><a href="<?php echo esc_url( self::REPO . '/security/advisories/new' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Report a security problem privately', 'dz-cod-order-form' ); ?></a></li>
			</ul>
		</div>
		<script>
		(function(){
			var repo=<?php echo wp_json_encode( self::REPO ); ?>,env=<?php echo wp_json_encode( self::environment() ); ?>;
			document.getElementById('dzcod-feedback').addEventListener('submit',function(e){
				e.preventDefault();
				var type=document.querySelector('#dzcod-feedback [name=type]:checked'),title=document.getElementById('dzcod-fb-title').value.trim(),body=document.getElementById('dzcod-fb-body').value.trim();
				if(!title||!body){return;}
				if(document.getElementById('dzcod-fb-env').checked){body+='\n\n---\n'+env;}
				var field=type.value==='bug'?'what':(type.value==='courier'?'api':'details');
				var url=repo+'/issues/new?template='+encodeURIComponent(type.getAttribute('data-template'))+'&title='+encodeURIComponent(title)+'&'+field+'='+encodeURIComponent(body);
				window.open(url,'_blank','noopener');
			});
		}());
		</script>
		<?php
	}
}
