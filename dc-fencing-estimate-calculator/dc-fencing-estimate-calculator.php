<?php
/**
 * Plugin Name: DC Fencing Estimate Calculator
 * Description: Adds an instant preliminary fence estimate calculator shortcode for DC Fencing LLC.
 * Version: 1.0.0
 * Author: DC Fencing LLC
 * Text Domain: dc-fencing-estimator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DC_Fencing_Estimate_Calculator {
	const VERSION = '1.0.0';
	const OPTION_KEY = 'dcfec_settings';
	const NONCE_ACTION = 'dcfec_submit_estimate';
	const NONCE_NAME = 'dcfec_nonce';
	const POST_TYPE = 'dc_fence_lead';

	private static $instance = null;
	private $latest_result = null;
	private $latest_errors = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_dcfec_save_settings', array( $this, 'save_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_lead_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_lead_status' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'lead_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'lead_column_content' ), 10, 2 );
		add_shortcode( 'dc_fencing_estimator', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public static function activate() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, self::default_settings() );
		}

		self::instance()->register_post_type();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public function register_assets() {
		wp_register_style(
			'dcfec-estimator',
			plugins_url( 'assets/estimator.css', __FILE__ ),
			array(),
			self::VERSION
		);
	}

	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Fence Leads', 'dc-fencing-estimator' ),
					'singular_name' => __( 'Fence Lead', 'dc-fencing-estimator' ),
					'add_new_item'  => __( 'Add New Fence Lead', 'dc-fencing-estimator' ),
					'edit_item'     => __( 'Edit Fence Lead', 'dc-fencing-estimator' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-clipboard',
				'supports'     => array( 'title' ),
				'capabilities' => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
				),
				'map_meta_cap' => false,
			)
		);
	}

	public static function default_settings() {
		return array(
			'fence_types'     => array(
				'wood_privacy' => array( 'label' => 'Wood Privacy', 'price' => 58 ),
				'chain_link'   => array( 'label' => 'Chain Link', 'price' => 32 ),
				'vinyl'        => array( 'label' => 'Vinyl', 'price' => 72 ),
				'aluminum'     => array( 'label' => 'Aluminum', 'price' => 68 ),
				'wrought_iron' => array( 'label' => 'Wrought Iron', 'price' => 95 ),
			),
			'height_adjustments' => array(
				'4' => 0,
				'5' => 8,
				'6' => 14,
				'8' => 28,
			),
			'gate_prices'    => array(
				'wood_privacy' => 350,
				'chain_link'   => 275,
				'vinyl'        => 425,
				'aluminum'     => 425,
				'wrought_iron' => 550,
			),
			'tear_out'       => 10,
			'ground_addons'  => array(
				'normal'   => 0,
				'rocky'    => 12,
				'concrete' => 24,
				'unknown'  => 6,
			),
			'job_minimum'    => 1500,
			'rounding_rule'  => 'nearest_100',
			'notification_email' => get_option( 'admin_email' ),
			'customer_subject' => 'Your preliminary DC Fencing estimate',
			'customer_body'  => "Hi {name},\n\nThank you for requesting a preliminary fence estimate from DC Fencing LLC. Your preliminary estimate is {estimate}.\n\nWe will review your information and contact you to confirm details, measurements, site conditions, and final pricing.\n\nDC Fencing LLC",
			'disclaimer'     => 'This is a preliminary online estimate only. Final pricing may change after DC Fencing LLC reviews measurements, materials, access, site conditions, permits, tear-out needs, gate details, and other project requirements.',
		);
	}

	private function settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	private function allowed_statuses() {
		return array( 'New', 'Contacted', 'Quoted', 'Won', 'Lost' );
	}

	private function allowed_ground_conditions() {
		return array(
			'normal'   => __( 'Normal', 'dc-fencing-estimator' ),
			'rocky'    => __( 'Rocky', 'dc-fencing-estimator' ),
			'concrete' => __( 'Concrete', 'dc-fencing-estimator' ),
			'unknown'  => __( 'Unknown', 'dc-fencing-estimator' ),
		);
	}

	public function render_shortcode() {
		wp_enqueue_style( 'dcfec-estimator' );

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dcfec_form_submitted'] ) ) {
			$this->handle_submission();
		}

		ob_start();
		?>
		<div class="dcfec-wrap">
			<div class="dcfec-card">
				<h2><?php esc_html_e( 'Get Your Preliminary Fence Estimate', 'dc-fencing-estimator' ); ?></h2>
				<p class="dcfec-intro"><?php esc_html_e( 'Tell DC Fencing LLC about your project and receive one instant preliminary estimate.', 'dc-fencing-estimator' ); ?></p>
				<?php $this->render_notices(); ?>
				<?php if ( $this->latest_result ) : ?>
					<div class="dcfec-result" role="status" aria-live="polite">
						<p><?php esc_html_e( 'Preliminary estimate', 'dc-fencing-estimator' ); ?></p>
						<strong><?php echo esc_html( $this->format_money( $this->latest_result['estimate'] ) ); ?></strong>
						<small><?php echo esc_html( $this->settings()['disclaimer'] ); ?></small>
					</div>
				<?php endif; ?>
				<?php $this->render_form(); ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_notices() {
		if ( empty( $this->latest_errors ) ) {
			return;
		}
		?>
		<div class="dcfec-errors" role="alert">
			<p><?php esc_html_e( 'Please correct the following:', 'dc-fencing-estimator' ); ?></p>
			<ul>
				<?php foreach ( $this->latest_errors as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	private function render_form() {
		$settings = $this->settings();
		$posted   = wp_unslash( $_POST );
		foreach ( $posted as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				unset( $posted[ $key ] );
			}
		}
		?>
		<form class="dcfec-form" method="post" enctype="multipart/form-data" novalidate>
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="dcfec_form_submitted" value="1" />

			<label><?php esc_html_e( 'Name', 'dc-fencing-estimator' ); ?> <span>*</span>
				<input type="text" name="name" required value="<?php echo esc_attr( $posted['name'] ?? '' ); ?>" autocomplete="name" />
			</label>

			<label><?php esc_html_e( 'Phone', 'dc-fencing-estimator' ); ?> <span>*</span>
				<input type="tel" name="phone" required value="<?php echo esc_attr( $posted['phone'] ?? '' ); ?>" autocomplete="tel" />
			</label>

			<label><?php esc_html_e( 'Email', 'dc-fencing-estimator' ); ?> <span>*</span>
				<input type="email" name="email" required value="<?php echo esc_attr( $posted['email'] ?? '' ); ?>" autocomplete="email" />
			</label>

			<label><?php esc_html_e( 'Address or city', 'dc-fencing-estimator' ); ?> <span>*</span>
				<input type="text" name="address" required value="<?php echo esc_attr( $posted['address'] ?? '' ); ?>" autocomplete="street-address" />
			</label>

			<label><?php esc_html_e( 'Fence type', 'dc-fencing-estimator' ); ?> <span>*</span>
				<select name="fence_type" required>
					<option value=""><?php esc_html_e( 'Select fence type', 'dc-fencing-estimator' ); ?></option>
					<?php foreach ( $settings['fence_types'] as $key => $type ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $posted['fence_type'] ?? '', $key ); ?>><?php echo esc_html( $type['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<div class="dcfec-grid">
				<label><?php esc_html_e( 'Approximate linear feet', 'dc-fencing-estimator' ); ?> <span>*</span>
					<input type="number" min="1" step="1" name="linear_feet" required value="<?php echo esc_attr( $posted['linear_feet'] ?? '' ); ?>" />
				</label>

				<label><?php esc_html_e( 'Fence height', 'dc-fencing-estimator' ); ?> <span>*</span>
					<select name="height" required>
						<option value=""><?php esc_html_e( 'Select height', 'dc-fencing-estimator' ); ?></option>
						<?php foreach ( $settings['height_adjustments'] as $height => $amount ) : ?>
							<option value="<?php echo esc_attr( $height ); ?>" <?php selected( $posted['height'] ?? '', $height ); ?>><?php echo esc_html( $height . ' ft' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>

			<label><?php esc_html_e( 'Number of gates', 'dc-fencing-estimator' ); ?> <span>*</span>
				<input type="number" min="0" step="1" name="gates" required value="<?php echo esc_attr( $posted['gates'] ?? '0' ); ?>" />
			</label>

			<label><?php esc_html_e( 'Gate width or gate notes', 'dc-fencing-estimator' ); ?>
				<input type="text" name="gate_notes" value="<?php echo esc_attr( $posted['gate_notes'] ?? '' ); ?>" />
			</label>

			<label><?php esc_html_e( 'Tear-out needed?', 'dc-fencing-estimator' ); ?> <span>*</span>
				<select name="tear_out" required>
					<option value=""><?php esc_html_e( 'Select one', 'dc-fencing-estimator' ); ?></option>
					<option value="yes" <?php selected( $posted['tear_out'] ?? '', 'yes' ); ?>><?php esc_html_e( 'Yes', 'dc-fencing-estimator' ); ?></option>
					<option value="no" <?php selected( $posted['tear_out'] ?? '', 'no' ); ?>><?php esc_html_e( 'No', 'dc-fencing-estimator' ); ?></option>
				</select>
			</label>

			<label><?php esc_html_e( 'Ground condition', 'dc-fencing-estimator' ); ?> <span>*</span>
				<select name="ground_condition" required>
					<option value=""><?php esc_html_e( 'Select condition', 'dc-fencing-estimator' ); ?></option>
					<?php foreach ( $this->allowed_ground_conditions() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $posted['ground_condition'] ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label><?php esc_html_e( 'Upload photos', 'dc-fencing-estimator' ); ?>
				<input type="file" name="photos[]" accept="image/*" multiple />
			</label>

			<label><?php esc_html_e( 'Notes', 'dc-fencing-estimator' ); ?>
				<textarea name="notes" rows="4"><?php echo esc_textarea( $posted['notes'] ?? '' ); ?></textarea>
			</label>

			<button type="submit"><?php esc_html_e( 'Get My Estimate', 'dc-fencing-estimator' ); ?></button>
			<p class="dcfec-required">* <?php esc_html_e( 'Required fields', 'dc-fencing-estimator' ); ?></p>
		</form>
		<?php
	}

	private function handle_submission() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			$this->latest_errors[] = __( 'Security check failed. Please refresh and try again.', 'dc-fencing-estimator' );
			return;
		}

		$data = $this->sanitize_submission();
		$this->latest_errors = $this->validate_submission( $data );
		if ( ! empty( $this->latest_errors ) ) {
			return;
		}

		$estimate = $this->calculate_estimate( $data );
		$data['estimate'] = $estimate;
		$data['photo_ids'] = $this->handle_uploads();
		$lead_id = $this->save_lead( $data );
		$data['lead_id'] = $lead_id;
		$this->send_emails( $data );

		$this->latest_result = array( 'estimate' => $estimate, 'lead_id' => $lead_id );
		$_POST = array();
	}

	private function sanitize_submission() {
		$posted = wp_unslash( $_POST );
		return array(
			'name'             => sanitize_text_field( $this->posted_scalar( $posted, 'name' ) ),
			'phone'            => sanitize_text_field( $this->posted_scalar( $posted, 'phone' ) ),
			'email'            => sanitize_email( $this->posted_scalar( $posted, 'email' ) ),
			'address'          => sanitize_text_field( $this->posted_scalar( $posted, 'address' ) ),
			'fence_type'       => sanitize_key( $this->posted_scalar( $posted, 'fence_type' ) ),
			'linear_feet'      => absint( $this->posted_scalar( $posted, 'linear_feet', 0 ) ),
			'height'           => sanitize_key( $this->posted_scalar( $posted, 'height' ) ),
			'gates'            => absint( $this->posted_scalar( $posted, 'gates', 0 ) ),
			'gate_notes'       => sanitize_text_field( $this->posted_scalar( $posted, 'gate_notes' ) ),
			'tear_out'         => sanitize_key( $this->posted_scalar( $posted, 'tear_out' ) ),
			'ground_condition' => sanitize_key( $this->posted_scalar( $posted, 'ground_condition' ) ),
			'notes'            => sanitize_textarea_field( $this->posted_scalar( $posted, 'notes' ) ),
		);
	}

	private function posted_scalar( $posted, $key, $default = '' ) {
		if ( ! isset( $posted[ $key ] ) || ! is_scalar( $posted[ $key ] ) ) {
			return $default;
		}

		return (string) $posted[ $key ];
	}

	private function validate_submission( $data ) {
		$errors = array();
		$settings = $this->settings();

		foreach ( array( 'name', 'phone', 'email', 'address', 'fence_type', 'linear_feet', 'height', 'tear_out', 'ground_condition' ) as $field ) {
			if ( empty( $data[ $field ] ) ) {
				$errors[] = __( 'All required fields must be completed.', 'dc-fencing-estimator' );
				break;
			}
		}

		if ( ! is_email( $data['email'] ) ) {
			$errors[] = __( 'Please enter a valid email address.', 'dc-fencing-estimator' );
		}
		if ( $data['linear_feet'] < 1 ) {
			$errors[] = __( 'Linear feet must be at least 1.', 'dc-fencing-estimator' );
		}
		if ( ! array_key_exists( $data['fence_type'], $settings['fence_types'] ) ) {
			$errors[] = __( 'Please select a valid fence type.', 'dc-fencing-estimator' );
		}
		if ( ! array_key_exists( $data['height'], $settings['height_adjustments'] ) ) {
			$errors[] = __( 'Please select a valid fence height.', 'dc-fencing-estimator' );
		}
		if ( ! in_array( $data['tear_out'], array( 'yes', 'no' ), true ) ) {
			$errors[] = __( 'Please select whether tear-out is needed.', 'dc-fencing-estimator' );
		}
		if ( ! array_key_exists( $data['ground_condition'], $this->allowed_ground_conditions() ) ) {
			$errors[] = __( 'Please select a valid ground condition.', 'dc-fencing-estimator' );
		}

		return $errors;
	}

	private function calculate_estimate( $data ) {
		$settings = $this->settings();
		$fence_type = $data['fence_type'];
		$linear_feet = $data['linear_feet'];
		$total = 0;
		$total += $linear_feet * (float) $settings['fence_types'][ $fence_type ]['price'];
		$total += $linear_feet * (float) $settings['height_adjustments'][ $data['height'] ];
		$total += $data['gates'] * (float) ( $settings['gate_prices'][ $fence_type ] ?? 0 );

		if ( 'yes' === $data['tear_out'] ) {
			$total += $linear_feet * (float) $settings['tear_out'];
		}

		$total += $linear_feet * (float) ( $settings['ground_addons'][ $data['ground_condition'] ] ?? 0 );
		$total = max( $total, (float) $settings['job_minimum'] );

		return $this->round_estimate( $total, $settings['rounding_rule'] );
	}

	private function round_estimate( $amount, $rule ) {
		switch ( $rule ) {
			case 'nearest_10':
				$increment = 10;
				break;
			case 'nearest_50':
				$increment = 50;
				break;
			case 'whole':
				$increment = 1;
				break;
			case 'nearest_100':
			default:
				$increment = 100;
				break;
		}

		return (int) ( ceil( $amount / $increment ) * $increment );
	}

	private function handle_uploads() {
		if ( empty( $_FILES['photos']['name'][0] ) ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = array();
		$file_count = count( $_FILES['photos']['name'] );
		for ( $i = 0; $i < $file_count; $i++ ) {
			if ( UPLOAD_ERR_OK !== (int) $_FILES['photos']['error'][ $i ] ) {
				continue;
			}

			$file = array(
				'name'     => sanitize_file_name( $_FILES['photos']['name'][ $i ] ),
				'type'     => sanitize_mime_type( $_FILES['photos']['type'][ $i ] ),
				'tmp_name' => $_FILES['photos']['tmp_name'][ $i ],
				'error'    => (int) $_FILES['photos']['error'][ $i ],
				'size'     => (int) $_FILES['photos']['size'][ $i ],
			);
			$allowed = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
			if ( empty( $allowed['type'] ) || 0 !== strpos( $allowed['type'], 'image/' ) ) {
				continue;
			}

			$_FILES['dcfec_single_photo'] = $file;
			$attachment_id = media_handle_upload( 'dcfec_single_photo', 0 );
			unset( $_FILES['dcfec_single_photo'] );
			if ( ! is_wp_error( $attachment_id ) ) {
				$attachment_ids[] = (int) $attachment_id;
			}
		}

		return $attachment_ids;
	}

	private function save_lead( $data ) {
		$title = sprintf(
			/* translators: 1: customer name, 2: date */
			__( '%1$s - Fence Estimate - %2$s', 'dc-fencing-estimator' ),
			$data['name'],
			current_time( 'Y-m-d H:i' )
		);
		$lead_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( $lead_id && ! is_wp_error( $lead_id ) ) {
			update_post_meta( $lead_id, '_dcfec_status', 'New' );
			update_post_meta( $lead_id, '_dcfec_lead_data', $data );
		}

		return (int) $lead_id;
	}

	private function send_emails( $data ) {
		$settings = $this->settings();
		$admin_to = sanitize_email( $settings['notification_email'] );
		if ( ! $admin_to ) {
			$admin_to = get_option( 'admin_email' );
		}

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		wp_mail( $admin_to, 'New DC Fencing online estimate lead', $this->admin_email_body( $data ), $headers );

		$customer_body = $this->replace_email_tokens( $settings['customer_body'], $data );
		$customer_subject = $this->replace_email_tokens( $settings['customer_subject'], $data );
		wp_mail( $data['email'], $customer_subject, $customer_body . "\n\n" . $settings['disclaimer'], $headers );
	}

	private function admin_email_body( $data ) {
		$settings = $this->settings();
		$type_label = $settings['fence_types'][ $data['fence_type'] ]['label'] ?? $data['fence_type'];
		$ground_label = $this->allowed_ground_conditions()[ $data['ground_condition'] ] ?? $data['ground_condition'];
		$lines = array(
			'New online estimate lead for DC Fencing LLC',
			'',
			'Estimate: ' . $this->format_money( $data['estimate'] ),
			'Lead ID: ' . (int) $data['lead_id'],
			'Name: ' . $data['name'],
			'Phone: ' . $data['phone'],
			'Email: ' . $data['email'],
			'Address or city: ' . $data['address'],
			'Fence type: ' . $type_label,
			'Approximate linear feet: ' . (int) $data['linear_feet'],
			'Fence height: ' . $data['height'] . ' ft',
			'Number of gates: ' . (int) $data['gates'],
			'Gate width or notes: ' . ( $data['gate_notes'] ? $data['gate_notes'] : 'None provided' ),
			'Tear-out: ' . ucfirst( $data['tear_out'] ),
			'Ground condition: ' . $ground_label,
			'Notes: ' . ( $data['notes'] ? $data['notes'] : 'None provided' ),
		);

		if ( ! empty( $data['photo_ids'] ) ) {
			$lines[] = '';
			$lines[] = 'Uploaded photos:';
			foreach ( $data['photo_ids'] as $photo_id ) {
				$lines[] = wp_get_attachment_url( $photo_id );
			}
		}

		return implode( "\n", $lines );
	}

	private function replace_email_tokens( $text, $data ) {
		return strtr(
			$text,
			array(
				'{name}'     => $data['name'],
				'{estimate}' => $this->format_money( $data['estimate'] ),
				'{phone}'    => $data['phone'],
				'{email}'    => $data['email'],
			)
		);
	}

	private function format_money( $amount ) {
		return '$' . number_format_i18n( (float) $amount, 0 );
	}

	public function register_settings_page() {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Estimator Settings', 'dc-fencing-estimator' ),
			__( 'Estimator Settings', 'dc-fencing-estimator' ),
			'manage_options',
			'dcfec-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'dcfec_settings_group', self::OPTION_KEY );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dc-fencing-estimator' ) );
		}
		$settings = $this->settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DC Fencing Estimate Calculator Settings', 'dc-fencing-estimator' ); ?></h1>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dc-fencing-estimator' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'dcfec_save_settings', 'dcfec_settings_nonce' ); ?>
				<input type="hidden" name="action" value="dcfec_save_settings" />

				<h2><?php esc_html_e( 'Fence Type Pricing', 'dc-fencing-estimator' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Fence Type', 'dc-fencing-estimator' ); ?></th><th><?php esc_html_e( 'Price Per Linear Foot', 'dc-fencing-estimator' ); ?></th><th><?php esc_html_e( 'Gate Add-on Price', 'dc-fencing-estimator' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $settings['fence_types'] as $key => $type ) : ?>
						<tr>
							<td><input type="text" name="fence_types[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $type['label'] ); ?>" /></td>
							<td><input type="number" min="0" step="0.01" name="fence_types[<?php echo esc_attr( $key ); ?>][price]" value="<?php echo esc_attr( $type['price'] ); ?>" /></td>
							<td><input type="number" min="0" step="0.01" name="gate_prices[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings['gate_prices'][ $key ] ?? 0 ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Adjustments', 'dc-fencing-estimator' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( $settings['height_adjustments'] as $height => $amount ) : ?>
						<tr><th scope="row"><?php echo esc_html( $height . ' ft height add-on per foot' ); ?></th><td><input type="number" min="0" step="0.01" name="height_adjustments[<?php echo esc_attr( $height ); ?>]" value="<?php echo esc_attr( $amount ); ?>" /></td></tr>
					<?php endforeach; ?>
					<tr><th scope="row"><?php esc_html_e( 'Tear-out add-on per foot', 'dc-fencing-estimator' ); ?></th><td><input type="number" min="0" step="0.01" name="tear_out" value="<?php echo esc_attr( $settings['tear_out'] ); ?>" /></td></tr>
					<?php foreach ( $settings['ground_addons'] as $condition => $amount ) : ?>
						<tr><th scope="row"><?php echo esc_html( ucfirst( $condition ) . ' ground add-on per foot' ); ?></th><td><input type="number" min="0" step="0.01" name="ground_addons[<?php echo esc_attr( $condition ); ?>]" value="<?php echo esc_attr( $amount ); ?>" /></td></tr>
					<?php endforeach; ?>
					<tr><th scope="row"><?php esc_html_e( 'Job minimum', 'dc-fencing-estimator' ); ?></th><td><input type="number" min="0" step="1" name="job_minimum" value="<?php echo esc_attr( $settings['job_minimum'] ); ?>" /></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Rounding rule', 'dc-fencing-estimator' ); ?></th><td><?php $this->render_rounding_select( $settings['rounding_rule'] ); ?></td></tr>
				</table>

				<h2><?php esc_html_e( 'Emails and Disclaimer', 'dc-fencing-estimator' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Notification email address', 'dc-fencing-estimator' ); ?></th><td><input type="email" class="regular-text" name="notification_email" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" /></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Customer email subject', 'dc-fencing-estimator' ); ?></th><td><input type="text" class="large-text" name="customer_subject" value="<?php echo esc_attr( $settings['customer_subject'] ); ?>" /></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Customer email body', 'dc-fencing-estimator' ); ?></th><td><textarea class="large-text" rows="8" name="customer_body"><?php echo esc_textarea( $settings['customer_body'] ); ?></textarea><p class="description"><?php esc_html_e( 'Available tokens: {name}, {estimate}, {phone}, {email}', 'dc-fencing-estimator' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Disclaimer text', 'dc-fencing-estimator' ); ?></th><td><textarea class="large-text" rows="5" name="disclaimer"><?php echo esc_textarea( $settings['disclaimer'] ); ?></textarea></td></tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'dc-fencing-estimator' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_rounding_select( $selected ) {
		$options = array(
			'nearest_100' => __( 'Round up to nearest $100', 'dc-fencing-estimator' ),
			'nearest_50'  => __( 'Round up to nearest $50', 'dc-fencing-estimator' ),
			'nearest_10'  => __( 'Round up to nearest $10', 'dc-fencing-estimator' ),
			'whole'       => __( 'Whole dollars', 'dc-fencing-estimator' ),
		);
		?>
		<select name="rounding_rule">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save settings.', 'dc-fencing-estimator' ) );
		}
		check_admin_referer( 'dcfec_save_settings', 'dcfec_settings_nonce' );

		$defaults = self::default_settings();
		$posted = wp_unslash( $_POST );
		$settings = $defaults;

		foreach ( $defaults['fence_types'] as $key => $type ) {
			$settings['fence_types'][ $key ]['label'] = sanitize_text_field( $posted['fence_types'][ $key ]['label'] ?? $type['label'] );
			$settings['fence_types'][ $key ]['price'] = max( 0, (float) ( $posted['fence_types'][ $key ]['price'] ?? $type['price'] ) );
			$settings['gate_prices'][ $key ] = max( 0, (float) ( $posted['gate_prices'][ $key ] ?? $defaults['gate_prices'][ $key ] ) );
		}
		foreach ( $defaults['height_adjustments'] as $height => $amount ) {
			$settings['height_adjustments'][ $height ] = max( 0, (float) ( $posted['height_adjustments'][ $height ] ?? $amount ) );
		}
		foreach ( $defaults['ground_addons'] as $condition => $amount ) {
			$settings['ground_addons'][ $condition ] = max( 0, (float) ( $posted['ground_addons'][ $condition ] ?? $amount ) );
		}

		$settings['tear_out'] = max( 0, (float) ( $posted['tear_out'] ?? $defaults['tear_out'] ) );
		$settings['job_minimum'] = max( 0, (float) ( $posted['job_minimum'] ?? $defaults['job_minimum'] ) );
		$rounding = sanitize_key( $posted['rounding_rule'] ?? $defaults['rounding_rule'] );
		$settings['rounding_rule'] = in_array( $rounding, array( 'nearest_100', 'nearest_50', 'nearest_10', 'whole' ), true ) ? $rounding : $defaults['rounding_rule'];
		$settings['notification_email'] = sanitize_email( $posted['notification_email'] ?? $defaults['notification_email'] );
		$settings['customer_subject'] = sanitize_text_field( $posted['customer_subject'] ?? $defaults['customer_subject'] );
		$settings['customer_body'] = sanitize_textarea_field( $posted['customer_body'] ?? $defaults['customer_body'] );
		$settings['disclaimer'] = sanitize_textarea_field( $posted['disclaimer'] ?? $defaults['disclaimer'] );

		update_option( self::OPTION_KEY, $settings );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=dcfec-settings' ) ) );
		exit;
	}

	public function add_lead_meta_boxes() {
		add_meta_box( 'dcfec_lead_details', __( 'Lead Details', 'dc-fencing-estimator' ), array( $this, 'render_lead_details_meta_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'dcfec_lead_status', __( 'Lead Status', 'dc-fencing-estimator' ), array( $this, 'render_lead_status_meta_box' ), self::POST_TYPE, 'side', 'high' );
	}

	public function render_lead_status_meta_box( $post ) {
		wp_nonce_field( 'dcfec_save_lead_status', 'dcfec_lead_status_nonce' );
		$status = get_post_meta( $post->ID, '_dcfec_status', true );
		$status = $status ? $status : 'New';
		?>
		<select name="dcfec_status" class="widefat">
			<?php foreach ( $this->allowed_statuses() as $allowed_status ) : ?>
				<option value="<?php echo esc_attr( $allowed_status ); ?>" <?php selected( $status, $allowed_status ); ?>><?php echo esc_html( $allowed_status ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_lead_details_meta_box( $post ) {
		$data = get_post_meta( $post->ID, '_dcfec_lead_data', true );
		if ( ! is_array( $data ) ) {
			esc_html_e( 'No lead details available.', 'dc-fencing-estimator' );
			return;
		}
		$settings = $this->settings();
		$rows = array(
			'Estimate' => $this->format_money( $data['estimate'] ?? 0 ),
			'Name' => $data['name'] ?? '',
			'Phone' => $data['phone'] ?? '',
			'Email' => $data['email'] ?? '',
			'Address or city' => $data['address'] ?? '',
			'Fence type' => $settings['fence_types'][ $data['fence_type'] ]['label'] ?? ( $data['fence_type'] ?? '' ),
			'Approximate linear feet' => $data['linear_feet'] ?? '',
			'Fence height' => isset( $data['height'] ) ? $data['height'] . ' ft' : '',
			'Number of gates' => $data['gates'] ?? '',
			'Gate width or notes' => $data['gate_notes'] ?? '',
			'Tear-out' => $data['tear_out'] ?? '',
			'Ground condition' => $this->allowed_ground_conditions()[ $data['ground_condition'] ?? '' ] ?? '',
			'Notes' => $data['notes'] ?? '',
		);
		?>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo nl2br( esc_html( (string) $value ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! empty( $data['photo_ids'] ) ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Uploaded photos', 'dc-fencing-estimator' ); ?></th><td>
					<?php foreach ( $data['photo_ids'] as $photo_id ) : ?>
						<a href="<?php echo esc_url( wp_get_attachment_url( $photo_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( get_attached_file( $photo_id ) ) ); ?></a><br />
					<?php endforeach; ?>
				</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public function save_lead_status( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['dcfec_lead_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dcfec_lead_status_nonce'] ) ), 'dcfec_save_lead_status' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = sanitize_text_field( wp_unslash( $_POST['dcfec_status'] ?? 'New' ) );
		if ( in_array( $status, $this->allowed_statuses(), true ) ) {
			update_post_meta( $post_id, '_dcfec_status', $status );
		}
	}

	public function lead_columns( $columns ) {
		$columns['dcfec_status'] = __( 'Status', 'dc-fencing-estimator' );
		$columns['dcfec_estimate'] = __( 'Estimate', 'dc-fencing-estimator' );
		$columns['dcfec_customer'] = __( 'Customer', 'dc-fencing-estimator' );
		return $columns;
	}

	public function lead_column_content( $column, $post_id ) {
		$data = get_post_meta( $post_id, '_dcfec_lead_data', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		switch ( $column ) {
			case 'dcfec_status':
				echo esc_html( get_post_meta( $post_id, '_dcfec_status', true ) ?: 'New' );
				break;
			case 'dcfec_estimate':
				echo esc_html( isset( $data['estimate'] ) ? $this->format_money( $data['estimate'] ) : '' );
				break;
			case 'dcfec_customer':
				echo esc_html( $data['name'] ?? '' );
				if ( ! empty( $data['phone'] ) ) {
					echo '<br />' . esc_html( $data['phone'] );
				}
				break;
		}
	}
}

register_activation_hook( __FILE__, array( 'DC_Fencing_Estimate_Calculator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DC_Fencing_Estimate_Calculator', 'deactivate' ) );
DC_Fencing_Estimate_Calculator::instance();
