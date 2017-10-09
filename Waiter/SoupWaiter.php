<?php
namespace Waiter;

use WP_Post;
use Exception;

/**
 * Created by PhpStorm.
 * User: francisw
 * Date: 23/06/2017
 * Time: 16:41
 */
class SoupWaiter extends SitePersistedSingleton {
	const REGISTRY_USER = 'soup-kitchen-registry';
	const REGISTRY_PASS = 'OpenDoor';
	const APIUSER_PASS = 'OpenDoor';

	/**
	 * @var string $kitchen_host Base URL of host providing SoupKitchen
	 */
	protected $kitchen_host;
	/**
	 * @var string $kitchen_api Kitchen API partial URL
	 */
	protected $kitchen_api;
	/**
	 * @var string $kitchen_api Kitchen API partial URL
	 */
	protected $social_api;
	/**
	 * @var string $kitchen_jwt_api Kitchen AUTH API partial URL
	 */
	protected $kitchen_jwt_api;
	/**
	 * @var number $kitchen_user The User we are known as in the Kitchen
	 */
	protected $kitchen_user;
	/**
	 * @var string $kitchen_token The Token to identify us in the Kitchen
	 */
	protected $kitchen_token;
    /**
     * @var number $nextMOTD the next MOTD page to request
     */
    protected $nextMOTD;
    /**
     * @var number $next_topic the next topic item (offset from 0)
     */
    protected $next_topic;
    /**
     * @var number $property_count The number of properties
     */
    protected $property_count;
    /**
     * @var string[] $joins the Joining words to use in topics (first is default), e.g. Best beaches ON Bornholm
     */
    protected $joins;
    /**
     * @var string[] $destinations the Joining words to use in topics (first is default), e.g. Best beaches on BORNHOLM
     */
    protected $destinations;

    protected function get_current_destination(){
        $id=0; // Default
        if (isset($_REQUEST['destination_id'])){
            $id = $_REQUEST['destination_id'];
        }
        return $id;
    }
    protected function get_destination(){
        return $this->destinations[$this->get_current_destination()];
    }

    /**
	 * Expose whether the Kitchen is up
	 *
	 * use as SoupWaiter::single()->connected?
	 * @return bool
	 */
	protected function is_connected(){
		return ($this->getSoupKitchenToken(TRUE)?true:false);
	}
	/**
	 * @param $folder
	 * @return string
	 */
	protected function get_base_url($folder=''){
		$folder .= '/';
		$image_url=null; // This was static, but with persistence will fail on hostname/schema change
		if (!$image_url){
			$mydir = explode('/',__DIR__);
			$uplevels = count(explode('\\',__NAMESPACE__));
			$dir = array_slice($mydir,0,0-$uplevels);
			$dir[] = "{$folder}FakeFile";
			$image_url = implode('/',$dir);
		}
		return plugin_dir_url($image_url);
	}
	/**
	 * @return string
	 */
	protected function get_image_url(){
		return $this->get_base_url('img');
	}
	/**
	 * SoupWaiter constructor.
	 */
	public function __construct(){
		$this->set_kitchen_host('https://core.vacationsoup.com'); # 'https://staging1.privy2.com';#
		$this->kitchen_api = 'wp-json/wp/v2';
		$this->kitchen_jwt_api = 'wp-json/jwt-auth/v1';
        $this->nextMOTD = 0;
        $this->next_topic = 0; // This is an offset, not a page number
        $this->property_count = 0;
	}

	/**
	 * @param $host string The base url, including scheme, which must be https
	 *
	 * @throws \Exception if not SSL
	 */
	public function set_kitchen_host($host){
		if (0==strncasecmp($host,'https://',8)){
			$this->kitchen_host = $host;
		} else {
			throw new \Exception("SoupKitchen location must begin https://, but got ".$host);
		}
	}

	/**
	 * @param $level string error, warning, success, info
	 * @param $notice string Message
	 * @param $detail string|null Further detail on alt-text
	 */
	public function addNotice($level,$notice,$detail=null) {
		$_SESSION['soup-kitchen-notices'][] = [$level,$notice,htmlentities($detail)];
	}

	/**
	 * Called from the 'init' Wordpress action
	 *
	 * Retrieve Session variables
	 * add action and filter hooks
	 *
	 */
	public function init(){
		if(!session_id()) session_start();

        if (!$this->kitchen_user){
            $user = wp_get_current_user();
            $this->kitchen_user = $user->user_login.".api@".$_SERVER['SERVER_NAME'];
        }
		if (!isset($_SESSION['soup-kitchen-notices'])){
			$_SESSION['soup-kitchen-notices'] = [];
		}
		if (isset($_SESSION['soup-kitchen-token'])){
			// Override the following until we create the code to catch an expired token
			$this->kitchen_token = $_SESSION['soup-kitchen-token'];
		}
		add_action( 'transition_post_status', [$this, 'transition_post_status'],10,3 );

		// Now install the admin screens if needed
		if (is_admin()) {
			add_action('admin_notices', [$this, 'general_admin_notice']);
			if (defined('TIMBER_LOADED')) {
				SoupWaiterAdmin::single()->init();
			}
		}
	}


	/**
	 * Get the access token connecting us to the SoupKitchen
	 *
	 * Return the $_SESSION token if set, otherwise request it
	 * from the SoupKitchen, creating the user if needed, and
	 * caching the token in $_SESSION.
	 *
	 * Admin NOTICE on Creating the account (should be once only)
	 * @param $refresh boolean get a new token anyway
	 * @return string the Token
	 * @throws \Exception On Requesting Token from Kitchen
	 */
	private function getSoupKitchenToken($refresh=FALSE){
		if (null==$this->kitchen_token || $refresh) {
			# Identify ourselves to our Chef du Jour
			$request = [
				'body' => [
					'username' => $this->kitchen_user,
					'password' => self::APIUSER_PASS // TODO secure this OpenDoor
				],
				'timeout'=>500,
				'sslverify'   => false
			];
			$auth = $this->kitchen_host.'/'.$this->kitchen_jwt_api.'/token';
			$response = wp_remote_post($auth, $request);

			// If that failed, try creating user firstr
			if (!$this->api_success($response)) {
				$response = $this->newRegistration(); // throws any errors

				// Tell admin we have connected first time
				$registerResponse = json_decode( wp_remote_retrieve_body( $response ) );
				$this->addNotice('success','Created VacationSoup account',$registerResponse->user_name);

				// And re-request the token from the Kitchen
				$response = wp_remote_post($auth, $request);
				if (!$this->api_success($response)) {
					throw new \Exception ('Sign-in '.$this->api_error_message($response));
				}
			}
			$tokenResponse = json_decode( wp_remote_retrieve_body( $response ) );
			$this->kitchen_token = $_SESSION['soup-kitchen-token'] = $tokenResponse->token;
		}
		return $this->kitchen_token;
	}
	/**
	 * To provide external access to it from __GET
	 * @return string
	 */
	protected function get_kitchen_token(){
		return $this->getSoupKitchenToken();
	}

	/**
	 *
	 * Request a new registration for the current user
	 * Always returns a valid new user, throws on any failure
	 *
	 * @return array|\WP_Error
	 */
	public function newRegistration(){

		return $this->despatchToKitchen('/users',
			[
				'username' => $this->kitchen_user,
				'email' => wp_get_current_user()->user_email,
				'url' => get_site_url(),
				'roles' =>['author'],
				'password' => self::APIUSER_PASS
			],
			$this->getSoupKitchenRegistryToken()
		);
	}

	/**
	 * Get the access token connecting us to the SoupKitchen's Registry account
	 * Always returns a valid key, throws on error
	 *
	 * @return string The token
	 * @throws \Exception On Requesting the token
	 */
	private function getSoupKitchenRegistryToken(){
		$response = wp_remote_post($this->kitchen_host.'/'.$this->kitchen_jwt_api.'/token', [
			'body' => [
				'username' => self::REGISTRY_USER,
				'password' => self::REGISTRY_PASS
			],
			'timeout'=>500,
			'sslverify'   => false
		]);

		if ($this->api_success($response)){
			$tokenResponse = json_decode( wp_remote_retrieve_body( $response ) );
			$token = $tokenResponse->token;
		} else {
			throw new \Exception ("Soup Kitchen Registration: ".$this->api_error_message($response));
		}
		return $token;
	}

	/**
	 *
	 * Return whether the wp_remote_* $response is successful, treating
	 * hard errors (bad params, 500+) and negative response (403) all as errors
	 * allowing
	 *      if ($this->api_success($response)) {
	 *         ... you know you have a valid, positive $response here ...
	 *
	 * @param $response
	 *
	 * @return bool
	 */
	private function api_success($response){
		if (is_wp_error( $response )) return FALSE;
		if (wp_remote_retrieve_response_code($response) > 399) return FALSE;
		return TRUE;
	}

	/**
	 *
	 * Return the error message from a wp_remote_* $response
	 * Usually called after $this->api_success() has returned an error
	 *
	 * @param \WP_Error|array $response
	 *
	 * @return mixed
	 */
	private function api_error_message($response){
		if (is_wp_error($response)) {
			return $response->get_error_message();
		} else {
			$error = json_decode(wp_remote_retrieve_body($response));
			return $error->message;
		}
	}

	/**
	 * Send an API request to the SoupKitchen, always returns a valid response
	 * and throws an exception on an invalid HTTP response (e.g. 403) or error response
	 *
	 * @param string $rri Relative Resource Indicator
	 * @param null|array $body Body to be sent, in array format
	 * @param null|string $token if identifying as other (e.g. registrar) user
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function despatchToKitchen($rri,$body=null,$token=null){

		if (null===$token){
			$token = $this->getSoupKitchenToken();
		}

		if ($body){
			$response = wp_remote_post($this->kitchen_host.'/'.$this->kitchen_api.$rri,[
				'headers' => [
					'Authorization' => 'Bearer '.$token,
					'Content-Type' => 'application/json'
					],
				'body' => json_encode($body),
				'timeout'=>500,
				'sslverify'   => false

			]);
		} else {
			$response = wp_remote_get($this->kitchen_host.'/'.$this->kitchen_api.$rri,[
				'headers' => [
					'Authorization' => 'Bearer '.$token
				],
				'timeout'=>500,
				'sslverify'   => false

			]);
		}
		if (!$this->api_success($response)) {
			throw new \Exception ("Kitchen: ".$this->api_error_message($response));
		}

		return $response;
	}
	/*
	 * EXTERNAL ENTRY  POINTS FROM HERE
	 */

	/**
	 *
	 * Print in HTML any notices in $_SESSION storage, and clear them from $_SESSION.
	 * $_SESSION['soup-kitchen-notices'] is an array of notices created by addNotice,
	 * each notice of the form
	 *      array( severity, notice [, notice_detail] )
	 * For example:
	 *      array( 'success', "User Created" )
	 *      [notice appears] User Created
	 *
	 * and:
	 *      array( 'warning', "Out of date plugin", "ver 1.23 can cause spontaneous combustion" )
	 *      [notice appears] Warning: Out of date plugin
	 *                       ^^^^^^^: title attr, on hover displays "ver 1.23 can ..."
	 *
	 * Action admin-notices entry point
	 */
	public function general_admin_notice(){
		while ($notice = array_shift($_SESSION['soup-kitchen-notices'])) {
			$detail = '';
			if (isset($notice[2])) {

				$detail = " <span title='$notice[2]'><b>".ucfirst($notice[0])."</b>:</span>";
			}
			echo "<div class='notice notice-{$notice[0]} is-dismissible'>
             <p>{$detail} {$notice[1]}</p>
             
         	</div>";
		}
	}

	/**
	 *
	 * Syndicate Post on publish and update
	 *
	 * Action transition_post_status entry point
	 *
	 * @param $newStatus string
	 * @param $oldStatus string
	 * @param $post \WP_Post The post
	 *
	 */
	public function transition_post_status( $newStatus, $oldStatus, \WP_Post $post ) {
		if ($post->type == 'post'){
			try {
				if ( 'publish' == $newStatus ){
					if ($newStatus != $oldStatus){
						// It's a new one
						$this->syndicate_post($post);
						$this->addNotice('info','Syndicated Post to VacationSoup');
					} else {
						// We need to update it
						$this->syndicate_post( $post ); // TODO This needs to update instead giving the foreign ID of it
						$this->addNotice('info','Updated Post on VacationSoup');
					}
				}
			} catch (Exception $e) {
				$this->addNotice('error','Failed to syndicate Post', $e->getMessage());
			}
		}
	}

	/**
	 *
	 * Send post to SoupKitchen
	 *
	 * @param WP_Post $post
	 * @param null|string $id
	 *
	 * @return array
	 */
	private function syndicate_post(WP_Post $post, $id=null){
		$rri = '/posts';
		if ($id) {
			$rri .= "/{$id}";
		}

		return $this->despatchToKitchen( $rri,
			[
				'date'     => $post->post_date,
				'date_gmt' => $post->post_date_gmt,
				'slug'     => $post->post_name,
				'status'   => 'publish', // By definition as we are a publisher
				'title'    => $post->post_title,
				'content'  => $post->post_content,
				'excerpt'  => $post->post_excerpt,
				//'featured_media' => get_the_post_thumbnail $post->post // TODO Implement featured media
			]
		);
	}

}

