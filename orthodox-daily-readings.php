<?php
/*
Plugin Name: Orthodox Daily Readings
Plugin URI: http://paulschlueter.com
Description: This plugin allows you to post the current day's readings and fasting rule from antiochian.org on your own website.
Author: Paul Schlueter
Version: 1.0.0
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author URI: http://paulschlueter.com
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 */
define( 'ODR_VERSION_NUMBER', '1.0.0' );

/**
 * Represents a single reading
 */
class ODR_Reading {
	private $title;
	private $shortText;
	private $fullText;

	/**
	 * Constructor
	 * @return ODR_Reading
	 */
	public function __construct() {
	}

	public function get_full_text() {
		return $this->fullText;
	}

	public function get_title() {
		return $this->title;
	}

	public function get_short_text() {
		return $this->shortText;
	}

	public function set_full_text(string $value) {
		$this->fullText = $value;
	}

	public function set_short_text(string $value) {
		$this->shortText = $value;
	}

	public function set_title(string $value) {
		$this->title = $value;
	}
}

/**
 * Class to hold all of the readings data
 */
class ODR_ReadingsDataModel {
	private $date;
	private $readings;
	private $fastingText;

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	public function get_date() {
		return $this->date;
	}

	public function set_date(string $value) {
		$this->date = $value;
	}

	public function get_readings() {
		return $this->readings;
	}

	public function set_readings(array $value) {
		$this->readings = $value;
	}

	public function get_fasting_text() {
		return $this->fastingText;
	}

	public function set_fasting_text(string $value) {
		$this->fastingText = $value;
	}
}

/**
 * Handles activation and deactivation of the plugin
 */
class ODR_ActivationHandler {
	const CRON_NAME = 'odr_sync_data';
	const SCRIPT_NAME = 'my_javascript';
	const READMORE_JS_LIB = 'readmore_lib';

	/**
	 * Constructor
	 * @return ODR_ActivationHandler
	 */
	public function __construct() {
		register_activation_hook(__FILE__, array(__CLASS__, 'on_activate'));
		register_deactivation_hook(__FILE__, array(__CLASS__, 'on_deactivate'));

		// Add the hook for the cron job callback
		add_action(ODR_ActivationHandler::CRON_NAME, 'ODR_LocalDataStoreInterface::sync_data');

		// Add the hook for javascript for dynamic expanding/contracting of reading text
		add_action('wp_enqueue_scripts', array(__CLASS__, 'setup_javascript'));

	}

	public static function setup_javascript() {
		// Register javascript scripts for dynamic expanding/contracting of reading text
		wp_register_script(ODR_ActivationHandler::SCRIPT_NAME, plugins_url('js/scripts.js', __FILE__), array('jquery'), ODR_VERSION_NUMBER);
		wp_enqueue_script(ODR_ActivationHandler::SCRIPT_NAME);

		wp_register_script(ODR_ActivationHandler::READMORE_JS_LIB, plugins_url('js/readmore_v2.2.0.min.js', __FILE__), array(), ODR_VERSION_NUMBER);
		wp_enqueue_script(ODR_ActivationHandler::READMORE_JS_LIB);
	}

	public static function on_activate() {
		// Schedule the cron job for repeatedly retrieving data from antiochian.org
		if (!wp_next_scheduled(ODR_ActivationHandler::CRON_NAME)) {
    		wp_schedule_event(time(), 'hourly', ODR_ActivationHandler::CRON_NAME);
		}
	}

	public static function on_deactivate() {
		// Unschedule the cron job
		$timestamp = wp_next_scheduled(ODR_ActivationHandler::CRON_NAME);
		wp_unschedule_event($timestamp, ODR_ActivationHandler::CRON_NAME);
	}
}

/**
 * Handles display of the readings via shortlink
 */
class ODR_View {
	/**
	 * Constructor
	 * @return ODR_View the view
	 */
	public function __construct() {

		// Register shortcodes
		add_shortcode('daily_readings_date', array($this, 'get_date_display'));
		add_shortcode('daily_readings_fast_rule', array($this, 'get_fast_rule_display'));
		add_shortcode('daily_readings_text', array($this, 'get_readings_text_display'));
	}

	public function get_date_display() {
		$data = ODR_LocalDataStoreInterface::get_data();
		$dateText = ucwords(strtolower($data->get_date()));

		// Strip out the year
		$tokens = explode(',', $dateText);
		return '<h2 class="odr_date">' . $tokens[0] . $tokens[1] . '</h2>';
	}

	public function get_fast_rule_display() {
		$data = ODR_LocalDataStoreInterface::get_data();
		return '<div class="odr_fast_rule">' . ucwords(strtolower($data->get_fasting_text())) . '</div>';
	}

	public function get_readings_text_display() {
		$data = ODR_LocalDataStoreInterface::get_data();
		$out = '';
		foreach ($data->get_readings() as $reading) {
			$out .= '<h3 class="odr_reading_title">' . ucwords(strtolower($reading->get_title())) . '</h3>' .
			     '<p class="odr_reading_text">' . $reading->get_full_text() . '</p>';
		}
		return $out;
	}
}

/** 
 * Interfaces with the Wordpress database
 */
class ODR_LocalDataStoreInterface {
	const DATA_KEY = "odr_daily_readings_data";

	/**
	 * Get the data from antiochian.org and store it in the Wordpress database
	 */
	public static function sync_data() {
		// Get data from antiochian.org
		$data = ODR_DataSourceInterface::get_data();

		// Store it in our database
		update_option(ODR_LocalDataStoreInterface::DATA_KEY, $data);
	}

	/**
	 * Retrieve the readings data from the database
	 *
	 * @return ODR_ReadingsDataModel the reading data
	 */
	public static function get_data() {
		return get_option(ODR_LocalDataStoreInterface::DATA_KEY);
	}
}

/** 
 * Interfaces with antiochian.org to get the reading data
 */
class ODR_DataSourceInterface {
	const DATA_SOURCE_URL = "http://antiochian-api-prod-wa.azurewebsites.net/api/data/RetrieveLiturgicalDaysRss";

	public static function get_data() {

		$out = new ODR_ReadingsDataModel();

		// Grab the content from antiochian.org
		$xml = new SimpleXMLElement(ODR_DataSourceInterface::get_data_from_source());
		$item = $xml->channel->item;

		$out->set_date($item->title);
		$out->set_fasting_text($item->FastDesignation);

		// Parse the readings tags to account for multiple readings
		$out->set_readings(ODR_DataSourceInterface::parse_readings($item));

		return $out;
	}

	/**
	 * Get all of the readings from the XML
	 * @param SimpleXMLElement $xml the XML "item" tag data from antiochian.org
	 * @return array of ODR_Reading objects
	 */
	private static function parse_readings(SimpleXMLElement $item) {
		$out = array();
		$reading = new ODR_Reading();

		foreach ($item->children() as $tag) {
			$tagName = $tag->getName();
			if (strpos($tagName,'Reading') !== false) {
				if (strpos($tagName,'Title') !== false) {
					$reading->set_title($item->$tagName);
				}
				elseif (strpos($tagName,'Teaser') !== false) {
					$reading->set_short_text($item->$tagName);
				}
				elseif (strpos($tagName,'FullText') !== false) {
					$reading->set_full_text($item->$tagName);
					$out[] = clone $reading;
				}
			}
		}

		return $out;
	}

	/**
	 * Get the data from antiochian.org
	 * 
	 * @return string the XML data from antiochian.org
	 */
	private static function get_data_from_source() {
		$curl = curl_init();
    	curl_setopt($curl, CURLOPT_URL, ODR_DataSourceInterface::DATA_SOURCE_URL);
    	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    	$data = curl_exec($curl);
    	curl_close($curl);
    	return $data;
	}
}

// Instantiate the activation/deactivation handler
$activator = new ODR_ActivationHandler();

// Instantiate the view
$view = new ODR_View();
?>
