<?php
namespace Waiter;
require_once (plugin_dir_path( __FILE__ )."../pixabay-images.php");

use Timber\Timber;

require_once( ABSPATH . 'wp-includes/pluggable.php' );

/**
 * Created by PhpStorm.
 * User: francisw
 * Date: 03/07/2017
 * Time: 21:02
 */
class SoupWaiterAdmin extends SitePersistedSingleton {
	/**
	 * @var boolean $hasFeaturedImages
	 */
	protected $hasFeaturedImages;
	/**
	 * @var string[] $pixabay_images_gallery_languages left this in when porting code from pixabay just in case
	 */
	protected $pixabay_images_gallery_languages;
	/**
	 * Called from the 'init' Wordpress action
	 *
	 * Retrieve Session variables
	 * add action and filter hooks
	 *
	 */

	public function init(){
		$this->pixabay_images_gallery_languages = array('cs' => 'Čeština', 'da' => 'Dansk', 'de' => 'Deutsch', 'en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'id' => 'Indonesia', 'it' => 'Italiano', 'hu' => 'Magyar', 'nl' => 'Nederlands', 'no' => 'Norsk', 'pl' => 'Polski', 'pt' => 'Português', 'ro' => 'Română', 'sk' => 'Slovenčina', 'fi' => 'Suomi', 'sv' => 'Svenska', 'tr' => 'Türkçe', 'vi' => 'Việt', 'th' => 'ไทย', 'bg' => 'Български', 'ru' => 'Русский', 'el' => 'Ελληνική', 'ja' => '日本語', 'ko' => '한국어', 'zh' => '简体中文');

		Timber::$locations = ABSPATH.'/wp-content/plugins/soup-waiter';

		// enables auto featuring image on post create
		$this->hasFeaturedImages = get_theme_support( 'post-thumbnails' );

		// Tried attaching this to send_headers hook but it didn't fire
		$this->add_header_cors();
		if (!defined( 'DOING_AJAX' )) $this->process_post_data();

		add_action( 'wp_ajax_soup', [ $this, 'ajax_controller' ] );
		add_action( 'wp_ajax_servicecheck', [ $this, 'do_servicecheck' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_styles' ] );
    }
	public function add_header_cors() {
		header( 'Access-Control-Allow-Origin: *' );
	}


	/**
	 * Register our stylesheets.
	 */
	public function register_styles() {
		wp_register_style( 'vs-bootstrap', plugins_url( '../css/bootstrap-grid.min.css',__FILE__) );
		wp_register_style( 'vacation-soup', plugins_url( '../css/vs-admin.css',__FILE__) );
	}


	/**
	 * Process AJAX data
	 * If there is a do_{tab}_ajax function, call it, otherwise do the default
	 * which is, assuming an input of name=Class.Member, value=NewValue
	 *
	 * Set the Member to NewValue, on the Singleton class Class
	 * It is up to the class to decide if to persist
	 *
	 * @internal Singleton::class $single
	 */
	public function ajax_controller(){
		$response=[];

		if (!empty($_REQUEST) &&
		    !empty($_REQUEST['_vs_nonce'])) {
			$tab = $this->sanitisedTab();
			check_admin_referer( 'vacation-soup-'.$tab,'_vs_nonce' );

			$controller = "do_{$tab}_ajax";
			try {
				if (method_exists($this,$controller)){
					$response = $this->$controller();
				} else {
					// Default behaviour
					if (!isset($_REQUEST['name']) || !isset($_REQUEST['value'])) {
						throw new \Exception('missing parameters: name or value');
					}
					$attr = $_REQUEST['name'];
					$class = 'SoupWaiter'; // Default
					$check = explode('.',$attr);
					if (2 == count($check)){    // Then it's class.member
							$class = $check[0];
							$attr = $check[1];
					}
                    preg_match('#\[(.*?)\]#', $class, $id);
					if ($id){
					    $id = $id[1]; // extract the id
                    }
					$class = substr($class,0,strcspn($class,"[]"));
					$class = __NAMESPACE__ . '\\' . $class;
					if (class_exists($class)){
						if (null==$id){
						    if (method_exists($class,'single')){
                                $obj = $class::single();
                            } else throw new \Exception("Class '{$class}' must be a Singleton, as no ID provided");
                        } else {
                            if (method_exists($class,'findOne')){
                                $obj = $class::findOne($id);
                            } else throw new \Exception("Class '{$class}' must be a Multiton, as ID provided");
                        }
						$obj->$attr = $_REQUEST['value'];  // Objects can throw, e.g. if $key or value invalid
						$response['success'] = true;
						$response[$_REQUEST['name']] = $obj->$attr;
					} else throw new \Exception("Class '{$class}' not found'");
				}
			} catch (\Exception $e){
				$response = [
					'success' => false,
					'error' => [
						'message' => $e->getMessage(),
						'code' => $e->getCode()
					]
				];
			}
		} else {
			$response['error'] = ['message'=>'not for me','code'=>0];
		}
		header("Content-type: application/json");
		echo json_encode($response);
		wp_die();
	}

	protected function fail_service_stub(){
	    return false;
    }

    protected function get_service($handle){
	    $groupedServices = $this->get_services();
	    foreach ($groupedServices as $group => $services){
	        if (isset($services[$handle])){
	            return $services[$handle];
            }
        }
        return null;
    }
	protected function get_services(){
	    return
            [
                "basic"=>[
                    "auth" => [
                        "type" =>   "prop",
                        "title"=>   "Authorisation",
                        "prop"=>    [SoupWaiter::single(),'connected']
                    ],
                    "post-kitchen" => [
                        "type" =>   "func",
                        "title"=>   "Soup Syndication",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "post-social" => [
                        "type" =>   "func",
                        "title"=>   "Social Posting",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "vacation-soup" => [
                        "type" =>   "func",
                        "title"=>   "Vacation Soup",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "community" => [
                        "type" =>   "func",
                        "title" =>   "Community",
                        "url" => "https://community.vacationsoup.com",
                        "call"=>    [$this,'fail_service_stub']
                    ]
                ],
                "premium"=> [
                    "soup-trade" => [
                        "type" =>   "func",
                        "title" =>   "Soup Sending Bookers",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "learn" => [
                        "type" =>   "func",
                        "title" =>   "Learning Centre",
                        "url" => "https://learn.vacationsoup.com",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "full-publication" => [
                        "type" =>   "func",
                        "title" =>   "Soup Advertising",
                        "url" => "https://community.vacationsoup.com",
                        "call"=>    [$this,'fail_service_stub']
                    ]
                ]
            ];

    }

    /**
     * Ajax call to check a particular service
     *
     * Initially designed as a framework to support replacing the values here with Kitchen supplied values
     * if needed, so the kitchen can re-direct some services if needed.
     *
     */
    public function do_servicecheck(){
		header("Content-type: application/json");
		try {
            $result = [
                'success' => true,  // Default to ajax call success
                'status' => 'nok'   // default to failed service test
			];
            $service = $this->get_service($_REQUEST["service"]);
            if (!$service){
                throw new \Exception("Service unknown: {$_REQUEST["service"]}");
            }
            switch($service["type"]){
                case "group":
                    $result["group"] = $service["group"];
                    break;
                case "func":
                    $method = $service["call"][1];
                    $object = $service["call"][0];
                    if (!method_exists($object,$method)){
                        throw new \Exception("Service check error: {$object}->{$method} not found");
                    }
                    if ($object->$method()){
                        $result["status"] = 'ok';
                    }
                    break;
                case "prop":
                    $prop = $service["prop"][1];
                    $object = $service["prop"][0];
                    if ($object->$prop){
                        $result["status"] = 'ok';
                    }
                    break;
            }
            if (isset($service["url"])){
                $result["url"] = $service["url"];
            }
			echo json_encode($result);
		} catch (\Exception $e){
			echo json_encode([
				'success' => false,
				'error' => [
					'message' => $e->getMessage(),
					'code' => $e->getCode()
				]
			]);
		}
		wp_die();
	}
	/**
	 * Process POST data
	 * If the POST array is populated form Vacation Soup, process it
	 */
	public function process_post_data(){
		if (is_admin() &&
		    !empty($_POST) &&
		    !empty($_POST['_vs_nonce'])) {
			$tab = $this->sanitisedTab();
			check_admin_referer( 'vacation-soup-'.$tab,'_vs_nonce' );

			$controller = "process_{$tab}_data";
			return ($this->$controller());
		}
	}

	/**
	 * First add us to the screens and menus
	 */
	public function admin_menu()
	{
		// This page will be under "Settings"
		$page = add_menu_page(
			'Vacation Soup',
			'Vacation Soup',
			'manage_options',
			'vacation-soup-admin',
			array( $this, 'create_admin_page' ),
			'dashicons-admin-site',
			4
		);
		add_action( "admin_print_styles-{$page}", [ $this, 'admin_enqueue_styles' ] );
	}
	public function admin_enqueue_styles(){
		wp_enqueue_style( 'vs-bootstrap' );
		wp_enqueue_style( 'vacation-soup' );
		wp_enqueue_script('jquery-ui-datepicker');
		wp_enqueue_style('vs-admin-ui-css','https://ajax.googleapis.com/ajax/libs/jqueryui/1.9.0/themes/base/jquery-ui.css',false,"1.9.0",false);
		wp_enqueue_style('vs-font-awesome', '//maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css');
	}

	/**
	 * @param $tab
	 *
	 * Validate (and correct) the paramater (or $_POST['tab']) to be a valid tab name
	 *
	 * @return string $tab sanitised
	 */
	private function sanitisedTab($tab=null){
		$default =  'create';

		if (!$tab) {
			if (isset($_REQUEST['tab'])){
				$tab = $_REQUEST['tab'];
			} else {
				$tab = $default;
			}
		}
		if (!in_array($tab,['create','dash','list','owner','property','connect'])){
			$tab = $default;
		}
		return $tab;
	}
	/**
	 * Admin page callback
	 * Set default tab and pass to twig with appropriate context
	 */
	public function create_admin_page() {
		$tab = $this->sanitisedTab();
		$fn_context = "get_{$tab}_context";

		// ...and go do That Voodoo that You Do ... so well!
		Timber::render( array( "admin/{$tab}.twig" ), $this->$fn_context() );
	}

	/**
	 * Create a post using the admin/VS/create page
	 */
	public function process_create_data( ){
		$error_obj = false;

		$postId = wp_insert_post($_POST,$error_obj);
		if (!$error_obj){
			wp_set_post_tags($postId, $_POST['tags']);
			set_post_thumbnail($postId,$_POST['featured_image']);
			update_post_meta($postId,'topic',$_POST['topic']);
		}
		// $this->persist(); // Don't know why we were persisting SoupWaiterAdmin, not needed, not here anyway
		return null; // Causes a fall-through to create the page anyway, as we are not redirecting after persistence
	}
	/**
	 * get base context
	 *
	 * @param string $tab
	 *
	 * @return array
	 */
	private function get_context($tab){
		$context = Timber::get_context();
		$context['tab'] = $tab;     // Used to decide current and next actions
		$context['soup'] = SoupWaiter::single();   // Exposing the waiter and soup.admin (in twig) is the SoupWaiterAdmin
		$context['admin'] = $this;
		return $context;
	}

	/**
	 * @returns mixed[] The context for this tab
	 */
	private function get_create_context(){
		// Grab the basics
		$context = $this->get_context('create');

		// Available Tags for all posts
        // In case the destination is multiple words
        $destination = SoupWaiter::single()->destination;
        $render = '';
        foreach (explode(' ',preg_replace('/[^a-z0-9]+/i', ' ', $destination['rendered'])) as $word){
            $render .= ucfirst($word);
        }
        $dest = '';
        foreach (explode(' ',preg_replace('/[^a-z0-9]+/i', ' ', $destination['destination'])) as $word){
            $dest .= ucfirst($word);
        }

        $context['permTags'] = ['VacationSoup',$dest];
        foreach (['Holiday','Vacation'] as $holiday){
            $context['permTags'][] = $holiday.$render;
        }

		$args = array(
			'numberposts' => 8,
			'offset' => 0,
			'category' => 0,
			'orderby' => 'post_date',
			'order' => 'DESC',
			'include' => '',
			'exclude' => '',
			'meta_key' => '',
			'meta_value' =>'',
			'post_type' => 'post',
			'post_status' => 'draft, publish, future',
			'suppress_filters' => true
		);

		$context['posts'] = Timber::get_posts( $args );
		$context['recent_topics']=[];
		foreach ($context['posts'] as $post){
			if (isset($post->topic) && $post->topic > 0){
				$context['recent_topics'][] = $post->topic;
			}
		}

		return $context;
	}

	/**
	 * @returns mixed[] The context for this tab
	 */
	private function get_dash_context(){
		// Grab the basics
		$context = $this->get_context('dash');

		return $context;
	}

	/**
	 * @returns mixed[] The context for this tab
	 */
	private function get_list_context(){
		// Grab the basics
		$context = $this->get_context('list');

		return $context;
	}

	/**
	 * @returns mixed[] The context for this tab
	 */
	private function get_owner_context(){
		// Grab the basics
		$context = $this->get_context('owner');

		return $context;
	}

    /**
     * @return array of Properties
     * Also populates the SoupWaiter::single()->properties
     */
	private function get_properties(){
        $propertyCount = SoupWaiter::single()->property_count;
        $properties = [];
        $destinations = []; // Might as well set these up too
        $dCount = 0;

        for ($i=0;$i<($propertyCount);$i++){
            $property = Property::findOne($i);
            $properties[] = $property;
            if (isset($property->destination)){
                $destinations[$dCount] = ['id'=>$dCount,'rendered'=>"{$property->join} {$property->destination}",'destination'=>"{$property->destination}"];
                $dCount++;
            }

        }
        sort(array_unique($destinations));
        SoupWaiter::single()->destinations = $destinations;
        return $properties;
    }

	/**
	 * @returns mixed[] The context for this tab
	 */
	private function get_property_context(){
		// Grab the basics
		$context = $this->get_context('property');
        $properties = $this->get_properties();
		$newAllowed = 1;
		if (count($properties) >= 10){
		    $newAllowed = 0;
        }
        for ($i=0;$i<(count($properties));$i++){
            $property = $properties[$i];
            if (empty($property->title)){ // Then this will be the 'New' property
                $newAllowed = 0;
            }
        }
        $context['properties'] = $properties;
        if ($newAllowed){
            $context['properties'][] = Property::findOne($i);
        }

		return $context;
	}
	/**
	 * @returns mixed[] The context for this tab
	 */
	private function get_connect_context(){
		// Grab the basics
		$context = $this->get_context('connect');

		$context['social']['FB'] = FB::single();
		$context['social']['TW'] = TW::single();
		$context['social']['PI'] = PI::single();
		$context['social']['GP'] = GP::single();
		$context['social']['LI'] = LI::single();
		$context['social']['RD'] = RD::single();
		$context['social']['SU'] = SU::single();
		$context['social']['IG'] = IG::single();
		/*
		 * Still to add
		 * vk.com
		 * Weibo
		 * Xing
		 * renren
		 * weixin (wechat)
		 */
		return $context;
	}
}
