<?php
/*
Plugin Name: Unpublish
Version: 1.0
Description: Unpublish your content
Author: Daniel Bachhuber, Human Made
Author URI: http://hmn.md/
Plugin URI: http://hmn.md/
Contributors: mindctrl
Text Domain: unpublish
Domain Path: /languages
*/

class Unpublish {

	public static $supports_key = 'unpublish';
	public static $cron_key = 'unpublish_cron';
	public static $post_meta_key = 'unpublish_timestamp';

	protected static $instance;

	public static function get_instance() {

		if ( empty( self::$instance ) ) {
			self::$instance = new Unpublish;
			// Standard setup methods
			foreach( array( 'setup_variables', 'includes', 'setup_actions' ) as $method ) {
				if ( method_exists( self::$instance, $method ) )
					self::$instance->$method();
			}
		}
		return self::$instance;
	}

	private function __construct() {
		/** Prevent the class from being loaded more than once **/
	}

	/**
	 * Set up variables associated with the plugin
	 */
	private function setup_variables() {
		$this->file           = __FILE__;
		$this->basename       = plugin_basename( $this->file );
		$this->plugin_dir     = plugin_dir_path( $this->file );
		$this->plugin_url     = plugin_dir_url( $this->file );

		$this->date_format    = get_option( 'date_format' );
		$this->time_format    = get_option( 'time_format' );

		$this->cron_frequency = 'hourly';
	}

	/**
	 * Set up action associated with the plugin
	 */
	private function setup_actions() {

		add_action( 'load-post.php', array( self::$instance, 'action_load_customizations' ) );
		add_action( 'load-post-new.php', array( self::$instance, 'action_load_customizations' ) );

		if ( ! wp_next_scheduled( self::$cron_key ) ) {
			wp_schedule_event( time(), $this->cron_frequency, self::$cron_key );
		}

		add_action( self::$cron_key, array( self::$instance, 'unpublish_content' ) );

	}

	/**
	 * Load any / all customizations to the admin
	 */
	public function action_load_customizations() {

		$post_type = get_current_screen()->post_type;
		if ( post_type_supports( $post_type, self::$supports_key ) ) {
			add_action( 'post_submitbox_misc_actions', array( self::$instance, 'render_unpublish_ui' ) );
			add_action( 'save_post_' . $post_type, array( self::$instance, 'action_save_unpublish_timestamp' ) );
			add_action( 'admin_enqueue_scripts', array( self::$instance, 'load_datepicker' ) );
		}

	}

	/**
	 * Render the UI for changing the unpublish time of a post
	 */
	public function render_unpublish_ui() {

		$unpublish_timestamp = get_post_meta( get_the_ID(), self::$post_meta_key, true );
		if ( ! empty( $unpublish_timestamp ) )
			$unpublish_date = date( $this->date_format . ' ' . $this->time_format, $unpublish_timestamp );
		else
			$unpublish_date = '';

		$vars = array(
			'unpublish_date' => $unpublish_date,
			'date_format'    => $this->date_format,
			'time_format'    => $this->time_format,
			);
		echo $this->get_view( 'unpublish-ui', $vars );
	}

	/**
	 * Save the unpublish time for a given post
	 */
	public function action_save_unpublish_timestamp( $post_id ) {

		if ( ! post_type_supports( get_post_type( $post_id ), self::$supports_key ) )
			return;

		if ( ! current_user_can( 'edit_post', $post_id ) )
			return;

		if ( isset( $_POST[self::$supports_key] ) ) {
			$timestamp = strtotime( $_POST[self::$supports_key] );
			if ( ! empty( $timestamp ) )
				update_post_meta( $post_id, self::$post_meta_key, $timestamp );
			else
				delete_post_meta( $post_id, self::$post_meta_key );
		}

	}

	/**
	 * Load the datepicker script
	 */
	public function load_datepicker() {
		wp_enqueue_script( 'jquery-ui-datetimepicker', plugin_dir_url( __FILE__ ) . 'assets/js/jquery.datetimepicker.js', array( 'jquery') );
		wp_enqueue_style( 'jquery-ui-datetimepicker', plugin_dir_url( __FILE__ ) . 'assets/css/jquery.datetimepicker.css' );
		wp_enqueue_script( 'unpublish', plugin_dir_url( __FILE__ ) . 'assets/js/unpublish.js', array( 'jquery-ui-datetimepicker' ) );
	}

	/**
	 * Unpublish any content that needs unpublishing
	 */
	public function unpublish_content() {
		global $_wp_post_type_features;

		$post_types = array();
		foreach( $_wp_post_type_features as $post_type => $features ) {
			if ( ! empty( $features[self::$supports_key] ) )
				$post_types[]= $post_type;
		}

		$args = array(
			'post_type'       => $post_types,
			'post_status'     => 'publish',
			'posts_per_page'  => 40,
			'meta_query'      => array(
				'relation' => 'AND',
				array(
					'key'     => self::$post_meta_key,
					'value'   => current_time( 'timestamp' ),
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => self::$post_meta_key,
					'compare' => 'EXISTS',
				)
			)
		);
		$query = new WP_Query( $args );

		foreach( $query->posts as $post_id ) {
			$post['ID'] = $post_id->ID;
			$post['post_status'] = 'draft';
			wp_update_post( $post );
		}

	}

	/**
	 * Get a given view (if it exists)
	 *
	 * @param string     $view      The slug of the view
	 * @return string
	 */
	public function get_view( $view, $vars = array() ) {

		if ( isset( $this->template_dir ) )
			$template_dir = $this->template_dir;
		else
			$template_dir = $this->plugin_dir . '/inc/templates/';

		$view_file = $template_dir . $view . '.tpl.php';
		if ( ! file_exists( $view_file ) )
			return '';

		extract( $vars, EXTR_SKIP );
		ob_start();
		include $view_file;
		return ob_get_clean();
	}

}

/**
 * Load the plugin
 */
function Unpublish() {
	return Unpublish::get_instance();
}
add_action( 'plugins_loaded', 'Unpublish' );
