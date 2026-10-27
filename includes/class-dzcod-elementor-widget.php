<?php
/**
 * Elementor widget wrapping the shortcode.
 *
 * @package DZCOD
 */

defined( 'ABSPATH' ) || exit;

/**
 * "DZ COD Order Form" Elementor widget.
 */
class DZCOD_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'dzcod_form';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'DZ COD Order Form', 'dz-cod-order-form' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Panel categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'woocommerce-elements-single', 'woocommerce-elements', 'general' );
	}

	/**
	 * Search keywords.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'cod', 'order', 'form', 'algeria', 'wilaya', 'checkout' );
	}

	/**
	 * Styles.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( 'dzcod-form' );
	}

	/**
	 * Scripts.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		return array( 'dzcod-form' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => __( 'Product', 'dz-cod-order-form' ) ) );
		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Product ID', 'dz-cod-order-form' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => '',
				'description' => __( 'Leave empty on a single product template to use the current product.', 'dz-cod-order-form' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$id   = absint( $this->get_settings_for_display( 'product_id' ) );
		$html = DZCOD_Form::render( $id ? $id : get_the_ID() );
		if ( '' === $html && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			$html = '<p>' . esc_html__( 'DZ COD Order Form: set a product ID, or use this widget in a single product template.', 'dz-cod-order-form' ) . '</p>';
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in DZCOD_Form::render().
	}
}
