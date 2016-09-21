<?php
// Don't load directly
defined( 'WPINC' ) or die;

class Tribe__Events__Aggregator {
	/**
	 * @var Tribe__Events__Aggregator Event Aggregator bootstrap class
	 */
	protected static $instance;

	/**
	 * @var Tribe__Events__Aggregator__Meta_Box Event Aggregator Meta Box object
	 */
	public $meta_box;

	/**
	 * @var Tribe__Events__Aggregator__Page Event Aggregator page root object
	 */
	public $page;

	/**
	 * @var Tribe__Events__Aggregator__Service Event Aggregator service object
	 */
	public $service;

	/**
	 * @var Tribe__Events__Aggregator__Record__Queue_Processor Event Aggregator record queue processor
	 */
	public $queue_processor;

	/**
	 * @var Tribe__Events__Aggregator__Record__Queue_Realtime Event Aggregator record queue processor in realtime
	 */
	public $queue_realtime;

	/**
	 * @var Tribe__Events__Aggregator__Settings Event Aggregator settings object
	 */
	public $settings;

	/**
	 * @var Tribe__PUE__Checker PUE Checker object
	 */
	public $pue_checker;

	/**
	 * @var array Collection of API objects
	 */
	protected $api;

	/**
	 * People who modify this value are not nice people.
	 *
	 * @var int Maximum number of import requests per day
	 */
	private $daily_limit = 100;

	/**
	 * A variable holder if Aggregator is loaded
	 * @var boolean
	 */
	private $is_loaded = false;

	/**
	 * Static Singleton Factory Method
	 *
	 * @return Tribe__Events__Aggregator
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * A private method to prevent it to be created twice.
	 * It will add the methods and setup any dependencies
	 *
	 * Note: This should load on `plugins_loaded@P10`
	 */
	private function __construct() {
		/**
		 * As previously seen by other major features some users would rather have it not active
		 * @var bool
		 */
		$should_load = (bool) apply_filters( 'tribe_aggregator_should_load', true );

		// You shall not Load!
		if ( false === $should_load ) {
			return;
		}

		// Loads the Required Classes and saves them as proprieties
		$this->meta_box        = Tribe__Events__Aggregator__Meta_Box::instance();
		$this->migrate         = Tribe__Events__Aggregator__Migrate::instance();
		$this->page            = Tribe__Events__Aggregator__Page::instance();
		$this->service         = Tribe__Events__Aggregator__Service::instance();
		$this->settings        = Tribe__Events__Aggregator__Settings::instance();
		$this->records         = Tribe__Events__Aggregator__Records::instance();
		$this->cron            = Tribe__Events__Aggregator__Cron::instance();
		$this->queue_processor = new Tribe__Events__Aggregator__Record__Queue_Processor;
		$this->queue_realtime  = new Tribe__Events__Aggregator__Record__Queue_Realtime( null, null, $this->queue_processor );
		$this->errors          = Tribe__Events__Aggregator__Errors::instance();
		$this->pue_checker     = new Tribe__PUE__Checker(
			'http://tri.be/',
			'event-aggregator',
			array( 'context' => 'service' )
		);

		// Initializes the Classes related to the API
		$this->api();

		// Flags that the Aggregator has been fully loaded
		$this->is_loaded = true;

		// Register the Aggregator Endpoint
		add_action( 'tribe_events_pre_rewrite', array( $this, 'action_endpoint_configuration' ) );

		// Intercept the Endpoint and trigger actions
		add_action( 'parse_request', array( $this, 'action_endpoint_parse_request' ) );

		// Add endpoint query vars
		add_filter( 'query_vars', array( $this, 'filter_endpoint_query_vars' ) );

		// Filter the "plugin name" for Event Aggregator
		add_filter( 'pue_get_plugin_name', array( $this, 'filter_pue_plugin_name' ), 10, 2 );

		// To make sure that meaningful cache is purged when settings are changed
		add_action( 'updated_option', array( $this, 'action_purge_transients' ) );

		// Remove aggregator records from ET
		add_filter( 'tribe_tickets_settings_post_types', array( $this, 'filter_remove_record_post_type' ) );

		// Notify users about expiring Facebook Token if oauth is enabled
		add_action( 'plugins_loaded', array( $this, 'setup_notices' ), 11 );
	}

	/**
	 * Set up any necessary notices
	 */
	public function setup_notices() {
		if ( ! $this->api( 'origins' )->is_oauth_enabled( 'facebook' ) ) {
			return;
		}

		tribe_notice( 'tribe-aggregator-facebook-token-expired', array( $this, 'notice_facebook_token_expired' ), 'type=error' );
		tribe_notice( 'tribe-aggregator-facebook-oauth-feedback', array( $this, 'notice_facebook_oauth_feedback' ), 'type=success' );
	}

	/**
	 * Initializes and provides the API objects
	 *
	 * @param string $api Which API to provide
	 *
	 * @return Tribe__Events__Aggregator__API__Abstract|stdClass|null
	 */
	public function api( $api = null ) {
		if ( ! $this->api ) {
			$this->api = (object) array(
				'origins' => new Tribe__Events__Aggregator__API__Origins,
				'import'  => new Tribe__Events__Aggregator__API__Import,
				'image'   => new Tribe__Events__Aggregator__API__Image,
			);
		}

		if ( ! $api ) {
			return $this->api;
		}

		if ( empty( $this->api->$api ) ) {
			return null;
		}

		return $this->api->$api;
	}

	/**
	 * Creates the Required Endpoint for the Aggregator Service to Query
	 *
	 * @param array $query_vars
	 *
	 * @return void
	 */
	public function action_endpoint_configuration( $rewrite ) {
		$rewrite->add(
			array( 'event-aggregator', '(insert)' ),
			array( 'tribe-aggregator' => 1, 'tribe-action' => '%1' )
		);
	}

	/**
	 * Adds the required Query Vars for the Aggregator Endpoint to work
	 *
	 * @param array $query_vars
	 *
	 * @return array
	 */
	public function filter_endpoint_query_vars( $query_vars = array() ) {
		$query_vars[] = 'tribe-aggregator';
		$query_vars[] = 'tribe-action';

		return $query_vars;
	}

	/**
	 * Allows the API to call the website
	 *
	 * @param  WP    $wp
	 *
	 * @return void
	 */
	public function action_endpoint_parse_request( $wp ) {
		// If we don't have both of these we bail
		if ( ! isset( $wp->query_vars['tribe-aggregator'] ) || empty( $wp->query_vars['tribe-action'] ) ) {
			return;
		}

		// Fetches which action we are talking about `/event-aggregator/{$action}`
		$action = $wp->query_vars['tribe-action'];

		// Bail if we don't have an action
		if ( ! $action ) {
			return;
		}

		/**
		 * Allow developers to hook on Event Aggregator endpoint
		 * We will always exit with a JSON answer error
		 *
		 * @param string  $action  Which action was requested
		 * @param WP      $wp      The WordPress Request object
		 */
		do_action( 'tribe_aggregator_endpoint', $action, $wp );

		/**
		 * Allow developers to hook to a specific Event Aggregator endpoint
		 * We will always exit with a JSON answer error
		 *
		 * @param WP      $wp      The WordPress Request object
		 */
		do_action( "tribe_aggregator_endpoint_{$action}", $wp );

		// If we reached this point this endpoint call was invalid
		return wp_send_json_error();
	}

	/**
	 * Handles the filtering of the PUE "plugin name" for event aggregator which...isn't a plugin
	 *
	 * @param string $plugin_name Plugin name to filter
	 * @param string $plugin_slug Plugin slug
	 *
	 * @return string
	 */
	public function filter_pue_plugin_name( $plugin_name, $plugin_slug ) {
		if ( 'event-aggregator' !== $plugin_slug ) {
			return $plugin_name;
		}

		return __( 'Event Aggregator', 'the-events-calendar' );
	}

	/**
	 * Filters the list of post types for Event Tickets to remove Import Records
	 *
	 * @param array $post_types Post Types
	 *
	 * @return array
	 */
	public function filter_remove_record_post_type( $post_types ) {
		if ( isset( $post_types[ Tribe__Events__Aggregator__Records::$post_type ] ) ) {
			unset( $post_types[ Tribe__Events__Aggregator__Records::$post_type ] );
		}

		return $post_types;
	}

	/**
	 * Purges the aggregator transients that are tied to the event-aggregator license
	 *
	 * @param string $option Option key
	 *
	 * @return boolean
	 */
	public function action_purge_transients( $option ) {
		if ( 'pue_install_key_event_aggregator' !== $option ) {
			return false;
		}

		$cache_group = $this->api( 'origins' )->cache_group;

		return delete_transient( "{$cache_group}_origins" );
	}

	/**
	 * Verify if Aggregator was fully loaded and is active
	 *
	 * @param  boolean $service  Should compare if the service is also active
	 *
	 * @return boolean
	 */
	public function is_active( $service = false ) {
		// If it's not loaded just bail false
		if ( false === (bool) $this->is_loaded ) {
			return false;
		}

		if ( true === $service ) {
			return self::is_service_active();
		}

		return true;
	}

	/**
	 * Verifies if the service is active
	 *
	 * @return boolean
	 */
	public static function is_service_active() {
		return ! is_wp_error( Tribe__Events__Aggregator__Service::instance()->api() );
	}

	/**
	 * Returns the daily import limit
	 *
	 * @return int
	 */
	public function get_daily_limit() {
		$import_daily_limit = $this->api( 'origins' )->get_limit( 'import' );
		return $import_daily_limit ? $import_daily_limit : $this->daily_limit;
	}

	/**
	 * Returns the available daily limit of import requests
	 *
	 * @return int
	 */
	public function get_daily_limit_available() {
		$available = get_transient( $this->daily_limit_transient_key() );

		$daily_limit = $this->get_daily_limit();

		if ( false === $available ) {
			return $daily_limit;
		}

		return (int) $available < $daily_limit ? $available : $daily_limit;
	}

	/**
	 * Reduces the daily limit by the provided amount
	 *
	 * @param int $amount Amount to reduce the daily limit by
	 *
	 * @return bool
	 */
	public function reduce_daily_limit( $amount = 1 ) {
		if ( ! is_numeric( $amount ) ) {
			return new WP_Error( 'tribe-invalid-integer', esc_html__( 'The daily limits reduction amount must be an integer', 'the-events-calendar' ) );
		}

		if ( $amount < 0 ) {
			return true;
		}

		$available = $this->get_daily_limit_available();

		$available -= $amount;

		if ( $available < 0 ) {
			$available = 0;
		}

		return set_transient( $this->daily_limit_transient_key(), $available, DAY_IN_SECONDS );
	}

	/**
	 * Generates the current daily transient key
	 */
	private function daily_limit_transient_key() {
		return 'tribe-aggregator-limit-used_' . date( 'Y-m-d' );
	}

	public function notice_facebook_oauth_feedback() {
		if ( empty( $_GET['ea-auth'] ) || 'facebook' !== $_GET['ea-auth'] ) {
			return false;
		}

		$html = '<p>' . esc_html__( 'Successfuly saved Event Aggregator Facebook Token', 'the-events-calendar' ) . '</p>';

		return Tribe__Admin__Notices::instance()->render( 'tribe-aggregator-facebook-oauth-feedback', $html );
	}

	public function notice_facebook_token_expired() {
		if ( ! Tribe__Admin__Helpers::instance()->is_screen() ) {
			return false;
		}

		$expires = tribe_get_option( 'fb_token_expires' );

		// Empty Token
		if ( empty( $expires ) ) {
			return false;
		}

		/**
		 * Allow developers to filter how many seconds they want to be warned about FB token expiring
		 * @param int
		 */
		$boundary = apply_filters( 'tribe_aggregator_facebook_token_expire_notice_boundary', 4 * DAY_IN_SECONDS );

		// Creates a Boundary for expire warning to appear, before the actual expiring of the token
		$boundary = $expires - $boundary;

		if ( time() < $boundary ) {
			return false;
		}

		$diff = human_time_diff( time(), $boundary );
		$passed = ( time() - $expires );
		$original = date( 'Y-m-d H:i:s', $expires );

		$time[] = '<span title="' . esc_attr( $original ) . '">';
		if ( $passed > 0 ) {
			$time[] = sprintf( esc_html_x( 'about %s ago', 'human readable time ago', 'the-events-calendar' ), $diff );
		} else {
			$time[] = sprintf( esc_html_x( 'in about %s', 'in human readable time', 'the-events-calendar' ), $diff );
		}
		$time[] = '</span>';
		$time = implode( '', $time );

		ob_start();
		?>
		<p>
			<?php
			if ( $passed > 0 ) {
				echo sprintf( __( 'Your Event Aggregator Facebook token has expired %s.', 'the-events-calendar' ), $time );
			} else {
				echo sprintf( __( 'Your Event Aggregator Facebook token will expire %s.', 'the-events-calendar' ), $time );
			}
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( Tribe__Settings::instance()->get_url( array( 'tab' => 'addons' ) ) ); ?>" class="tribe-license-link"><?php esc_html_e( 'Renew your Event Aggregator Facebook token', 'the-events-calendar' ); ?></a>
		</p>
		<?php

		$html = ob_get_clean();

		return Tribe__Admin__Notices::instance()->render( 'tribe-aggregator-facebook-token-expired', $html );
	}

	/**
	 * Tells whether the legacy ical plugin is active
	 *
	 * @return boolean
	 */
	public function is_legacy_ical_active() {
		return class_exists( 'Tribe__Events__Ical_Importer__Main' );
	}

	/**
	 * Tells whether the legacy facebook plugin is active
	 *
	 * @return boolean
	 */
	public function is_legacy_facebook_active() {
		return class_exists( 'Tribe__Events__Facebook__Importer' );
	}
}
