<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OFP_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'ofp_upscaler';
	}

	public function get_title() {
		return 'שדרוג גרפיקה 4K';
	}

	public function get_icon() {
		return 'eicon-image-rollover';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'upscale', '4k', 'upload', 'הגדלה', 'גרפיקה', 'לוגו' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => 'תוכן' ) );
		$this->add_control(
			'title',
			array(
				'label'   => 'כותרת',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'שדרוג גרפיקה ל-4K',
			)
		);
		$this->add_control(
			'subtitle',
			array(
				'label'   => 'תיאור',
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => 'העלו לוגו או גרפיקה – מנוע ה-AI שלנו יחדד אותה לרזולוציית 4K בלי לשנות אף פרט, והצוות יעבור עליה לפני ההדפסה.',
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		echo do_shortcode( '[ofnoa_upscaler title="' . esc_attr( $s['title'] ) . '" subtitle="' . esc_attr( $s['subtitle'] ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
