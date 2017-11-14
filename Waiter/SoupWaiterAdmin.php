<?php
namespace Waiter;
require_once (SOUP_PATH . "soup-pixabay-images.php");

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
	 * @var string|null $tab_message For messages at the top of a tab, such as why we are here
	 */
	protected $tab_message;
	/**
	 * @var string|null $requested_tab Only set if the tab is overridden in sanitise_tab
	 */
	protected $requested_tab;
	/**
	 * Called from the 'init' Wordpress action
	 *
	 * Retrieve Session variables
	 * add action and filter hooks
	 *
	 */

	public function init(){
		$this->pixabay_images_gallery_languages = array('cs' => 'Čeština', 'da' => 'Dansk', 'de' => 'Deutsch', 'en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'id' => 'Indonesia', 'it' => 'Italiano', 'hu' => 'Magyar', 'nl' => 'Nederlands', 'no' => 'Norsk', 'pl' => 'Polski', 'pt' => 'Português', 'ro' => 'Română', 'sk' => 'Slovenčina', 'fi' => 'Suomi', 'sv' => 'Svenska', 'tr' => 'Türkçe', 'vi' => 'Việt', 'th' => 'ไทย', 'bg' => 'Български', 'ru' => 'Русский', 'el' => 'Ελληνική', 'ja' => '日本語', 'ko' => '한국어', 'zh' => '简体中文');

		Timber::$locations = untrailingslashit(SOUP_PATH );

		// enables auto featuring image on post create
		$this->hasFeaturedImages = get_theme_support( 'post-thumbnails' );

		// Tried attaching this to send_headers hook but it didn't fire
		$this->add_header_cors();

		add_action( 'admin_init', [$this,'process_post_data']);
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
		wp_register_style( 'vs-bootstrap', SOUP_URL . 'css/bootstrap-grid.min.css' );
		wp_register_style( 'vacation-soup', SOUP_URL . 'css/vs-admin.css' );
	}


	/**
	 * Process AJAX data
	 * If there is a do_{tab}_ajax function, call it, otherwise do the default
	 * which is update name with value, where name is Class.property for Singleton, or Class[id].property
	 * for Multiton
	 *
	 * Set the property to NewValue, on the Singleton or Multiton class Class
	 * It is up to the class to decide if to persist
	 *
	 * @internal Singleton::class $single
	 */
	public function ajax_controller(){
		$response=[];

		if (!empty($_REQUEST) &&
		    !empty($_REQUEST['_vs_nonce'])) {
			$tab = $this->sanitisedTab();
			check_admin_referer( 'vacation-soup','_vs_nonce' );

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
					    $id = intval($id[1]); // extract the numeric id
                    } else {
						$id = null;
					}
					$class = substr($class,0,strcspn($class,"[]"));
					$class = __NAMESPACE__ . '\\' . $class;
					if (class_exists($class)){
						if (null===$id){
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
						try {
							$response[$_REQUEST['name']] = $obj->$attr;
						} catch (\Exception $e){
							$response[$_REQUEST['name']] = null; // Allowed for write-only attributes (like password)
						}
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
                        "message"=> "Vacation Soup user or password needs setting",
                        "prop"=>    [SoupWaiter::single(),'authorised']
                    ],
                    "post-kitchen" => [
                        "type" =>   "prop",
                        "title"=>   "Soup Syndication",
                        "prop"=>    [SoupWaiter::single(),'connected']
                    ],
                    "post-social" => [
                        "type" =>   "func",
                        "title"=>   "Social Posting",
                        "message"=> "Service is not live",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "vacation-soup" => [
                        "type" =>   "func",
                        "title"=>   "Vacation Soup",
                        "message"=> "Service is not live",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "community" => [
                        "type" =>   "func",
                        "title" =>   "Community",
                        "url" => "https://community.vacationsoup.com",
                        "message"=> "Service is not live",
                        "call"=>    [$this,'fail_service_stub']
                    ]
                 ],
               "premium"=> [
                    "soup-trade" => [
                        "type" =>   "func",
                        "title" =>   "Soup Sending Bookers",
                        "message"=> "Premium Service not subscribed",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "learn" => [
                        "type" =>   "func",
                        "title" =>   "Learning Centre",
                        "url" => "https://learn.vacationsoup.com",
                        "message"=> "Premium Service not subscribed",
                        "call"=>    [$this,'fail_service_stub']
                    ],
                    "full-publication" => [
                        "type" =>   "func",
                        "title" =>   "Soup Advertising",
                        "url" => "https://community.vacationsoup.com",
                        "message"=> "Premium Service not subscribed",
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
                'status' => 'nok',   // default to failed service test
	            'message' => 'Unknown Error'
			];
            $service = $this->get_service($_REQUEST["service"]);
            if (!$service){
            	$message = esc_html($_REQUEST["service"]);
                throw new \Exception("Service unknown: {$message}");
            }
			if (isset($service["message"])){
				$result['message'] = $service["message"]; // Default failure message
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
	                    $result["message"] = 'Service connected & up';
                    }
                    break;
                case "prop":
                    $prop = $service["prop"][1];
                    $object = $service["prop"][0];
                    if ($object->$prop){
                        $result["status"] = 'ok';
	                    $result["message"] = 'Service connected & up';
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

			check_admin_referer( 'vacation-soup','_vs_nonce' );

			$tab = $this->sanitisedSrcTab();
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
		$tabs = ['create','owner','property','connect'];
		$this->tab_message = '';

		if (!$tab) {
			if (isset($_REQUEST['requested-tab'])){
				$tab = $_REQUEST['requested-tab'];
			} elseif (isset($_REQUEST['tab'])){
				$tab = $_REQUEST['tab'];
			} else {
				$tab = $default;
			}
		}
		if (!in_array($tab,$tabs)){
			$tab = $default;
		}
		// Now lets see if that tab is allowed
		if ('create'==$tab){ // create needs everything else to be completed
			$w = SoupWaiter::single();
			if (empty($w->owner_name)) $tab = 'owner';
			else {
				if (0==$w->property_count) $tab = 'property';
				elseif (!$w->connected) $tab = 'connect';
			}
			if ('create'!=$tab) {
				$this->requested_tab = 'create';
				$this->tab_message = "You have been redirected here, more information is needed.";
			}
		}
		return $tab;
	}
	public function sanitisedSrcTab(){
		$tab = $this->sanitisedTab();
		if (isset($_REQUEST['requested-tab']) && isset($_REQUEST['tab'])){
			$tab = esc_html($_REQUEST['tab']); // Use the one the data came from
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
		if (!isset($_POST['post_status']) || ('publish'!=$_POST['post_status'] && 'draft'!=$_POST['post_status'])){
			return null;
		}
		$error_obj = false;

		$newpost = $_POST;
		$newpost['post_content'] .= "<p>Created by ".SoupWaiter::single()->owner_name."</p>";

		$postId = wp_insert_post($newpost,$error_obj);
		if (!$error_obj){
			wp_set_post_tags($postId, $_POST['tags']);
			set_post_thumbnail($postId,$_POST['featured_image']);
			update_post_meta($postId,'topic',$_POST['topic']);
		}
		SoupWaiter::single()->wp_async_save_post($postId,get_post($postId));
		// $this->persist(); // Don't know why we were persisting SoupWaiterAdmin, not needed, not here anyway
		unset($_POST['post_status']);
		return null; // Causes a fall-through to create the page anyway, as we are not redirecting after persistence
	}
	public function process_owner_data(){}
	public function process_connect_data(){}
	public function process_property_data(){}
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

		$context['permTags'][] = 'VacationSoup';
        foreach (SoupWaiter::single()->destinations_for_property as $destination) {
	        $render = '';
	        foreach (explode(' ',preg_replace('/[^a-z0-9]+/i', ' ', $destination['rendered'])) as $word){
		        $render .= ucfirst($word);
	        }
	        $dest = '';
	        foreach (explode(' ',preg_replace('/[^a-z0-9]+/i', ' ', $destination['destination'])) as $word){
		        $dest .= ucfirst($word);
	        }

	        $context['permTags'][] = $dest;
	        foreach (['Holiday','Vacation'] as $holiday){
		        $context['permTags'][] = $holiday.$render;
	        }
        }
		add_filter('tiny_mce_before_init', [$this,'mce_autosave_mod']);

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
			if (isset($post->topic) && $post->topic > 0 && !is_array($post->topic)){
				$context['recent_topics'][] = $post->topic;
			}
		}

		return $context;
	}
	public function mce_autosave_mod( $init ) {
		$init['setup'] = "function(ed){ ed.on( 'NodeChange', function(e){ setFeaturedImage(ed) } ) }";
		/* $init['setup'] = "function(ed){
			ed.on('NodeChange', function(e){
				console.log('the content ' + ed.getContent());
			});
		}"; */
		return $init;
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
	public function get_properties(){
        $propertyCount = SoupWaiter::single()->property_count;
        $properties = [];
        $destinations = []; // Might as well set these up too
        $dCount = 0;

        for ($i=0;$i<($propertyCount);$i++){
            $property = Property::findOne($i);
            $properties[] = $property;

	        if (!empty($property->destination)){
		        $destinations[$dCount] = ['id'=>$dCount,'property'=>$i,'rendered'=>"{$property->join} {$property->destination}",'destination'=>"{$property->destination}"];
		        $dCount++;
	        }
	        if (!empty($property->destination2)){
		        $destinations[$dCount] = ['id'=>$dCount,'property'=>$i,'rendered'=>"{$property->join2} {$property->destination2}",'destination'=>"{$property->destination2}"];
		        $dCount++;
	        }
	        if (!empty($property->destination3)){
		        $destinations[$dCount] = ['id'=>$dCount,'property'=>$i,'rendered'=>"{$property->join3} {$property->destination3}",'destination'=>"{$property->destination3}"];
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
