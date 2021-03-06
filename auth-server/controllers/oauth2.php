<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');
 
/**
 * OAuth 2.0 authorization server (draft 13 spec)
 *
 * It is highly recommended you use the latest CodeIgniter Reactor and enable XSS filtering and CSRF protection
 *
 * @package             CodeIgniter
 * @author              Alex Bilbie | www.alexbilbie.com | alex@alexbilbie.com
 * @copyright   		Copyright (c) 2010, Alex Bilbie.
 * @license             http://codeigniter.com/user_guide/license.html
 * @link                http://alexbilbie.com
 * @version             Version 0.1
 */
 
class Oauth2 extends CI_Controller {
		
	function __construct()
	{
		parent::__construct();
		
		$this->load->helper('url');
		$this->load->library('oauth_server');
	}
	
		
	/**
	 * This is the function that users are sent to when they first enter the flow
	 */
	function index()
	{
		// Get query string parameters
		// ?response_type=code&client_id=XXX&redirect_uri=YYY&scope=ZZZ&state=123
		$params = $this->oauth_server->params(array('response_type'=>array('code'), 'client_id'=>TRUE, 'redirect_uri'=>TRUE, 'scope'=>FALSE, 'state'=>FALSE)); // returns array or FALSE
		
		// If missing/bad parameter
		if ($params == FALSE)
		{
			$this->_fail('[OAuth client error: invalid_request] The request is missing a required parameter, includes an unsupported parameter or parameter value, or is otherwise malformed.', TRUE);
			return;
		}
				
		// Validate client_id and redirect_uri
		$client_details = $this->oauth_server->validate_client($params['client_id'], NULL, $params['redirect_uri']); // returns object or FALSE
		if ($client_details === FALSE )
		{
			$this->_fail("[OAuth client error: unauthorized_client] The client is not authorised to request an authorization code using this method.", TRUE);
			return;
		}
		$this->session->set_userdata('client_details', $client_details);
		
		// Get the scope
		if (isset($params['scope']) && count($params['scope']) > 0)
		{
			$params['scope'] = explode(',', $params['scope']);
			if ( ! in_array('basic', $params['scope']))
			{
				// Add basic scope regardless
				$params['scope'][] = 'basic';
			}
		}
		else
		{
			// Add basic scope regardless
			$params['scope'] = array(
				'basic'
			);
		}
		
		// Save the params in the session
		$this->session->set_userdata(array('params'=>$params));

		// Check if user is signed in already
		$user_id = $this->session->userdata('user_id'); // returns string or FALSE
				
		// If the user is already signed in and the app has the flag 'auto_approve'
		// Then generate a new auth code and redirect the user back to the application
		if ($user_id && $client_details->auto_approve == 1)
		{		
			$this->fast_code_redirect($client_details->client_id, $user_id, $params['redirect_uri'], $params['scope'], $params['state']);
		}
		
		// Has the user authorised the application already?
		if ($user_id)
		{
			$authorised = $this->oauth_server->has_access_token($user_id, $params['client_id']); // return TRUE or FALSE
			
			// If there is already an access token then the user has authorised the application
			// Generate a new auth code and redirect the user back to the application
			if ($authorised)
			{
				$this->fast_code_redirect($client_details->client_id, $user_id, $params['redirect_uri'], $params['scope'], $params['state']);
			}
						
			// The user hasn't authorised the application. Send them to the authorise page.
			else
			{
				redirect(site_url(array('oauth2', 'authorise')), 'location');
			}
		}
		
		// The user is not signed in, send them to sign in
		else
		{
			$this->session->set_userdata('sign_in_redirect', array('oauth2', 'authorise'));
			redirect(site_url(array('oauth2', 'sign_in')), 'location');
		}
	}
	
	
	/**
	 * If the user isn't signed in they will be redirect here
	 */
	function sign_in()
	{
		// Check if user is signed in, if so redirect them on to /authorise
		$user_id = $this->session->userdata('user_id');
		if ($user_id)
		{
			redirect(site_url($this->session->userdata('sign_in_redirect')), 'location');
		}
		
		// Check there is are client parameters are stored
		$client = $this->session->userdata('client_details'); // returns object or FALSE
		if ($client == FALSE)
		{
			$this->_fail('[OAuth user error: invalid_request] No client details have been saved. Have you deleted your cookies?', TRUE);
			return;
		}
		
		// Errors
		$vars = array(
			'error' => FALSE,
			'error_messages' => array()
		);
		
		// If the form has been posted
		if ($this->input->post('signin'))
		{
			$u = trim($this->input->post('username', TRUE));
			$p = trim($this->input->post('password', TRUE));
			
			// Validate
			if ($u == FALSE || empty($u))
			{
				$vars['error_messages'][] = 'The username field should not be empty';
				$vars['error'] = TRUE;
			}
			
			if ($p == FALSE || empty($p))
			{
				$vars['error_messages'][] = 'The password field should not be empty';
				$vars['error'] = TRUE;
			}
			
			// Check login and get credentials
			if ($vars['error'] == FALSE)
			{
				$user_id = $this->oauth_server->signin($u, $p);
				
				if ($user_id == FALSE)
				{
					$vars['error_messages'][] = 'Invalid username and/or password';
					$vars['error'] = TRUE;
				}
				
				else
				{
					$this->session->set_userdata(array('user_id' => $user_id));
				}
			}
			
			// If there is no error
			if ($vars['error'] == FALSE)
			{
				redirect(site_url($this->session->userdata('sign_in_redirect')), 'location');
			}
		}
		
		$this->load->view('oauth2/sign_in', $vars);
		
	}
	
	
	/**
	 * Sign the user out of the SSO service
	 * 
	 * @access public
	 * @return void
	 */
	function sign_out()
	{
		$this->session->sess_destroy();
		
		if ($redirect_uri = $this->input->get('redirect_uri'))
		{
			redirect($redirect_uri);
		}
		
		else
		{
			$this->load->view('oauth2/sign_out');
		}
		
	}
	
	
	/**
	 * When the user has signed in they will be redirected here to approve the application
	 */
	function authorise()
	{
		// Check if the user is signed in
		$user_id = $this->session->userdata('user_id');
		if ($user_id == FALSE)
		{
			$this->session->set_userdata('sign_in_redirect', array('oauth2', 'authorise'));
			redirect(site_url(array('oauth2', 'sign_in')), 'location');
		}
		
		// Check the client params are stored
		$client = $this->session->userdata('client_details');
		if ($client == FALSE)
		{
			$this->_fail('[OAuth user error: invalid_request] No client details have been saved. Have you deleted your cookies?', TRUE);
			return;
		}
		
		// The GET parameters
		$params = $this->session->userdata('params');
		if ($params == FALSE)
		{
			$this->_fail('[OAuth user error: invalid_request] No OAuth parameters have been saved. Have you deleted your cookies?', TRUE);
			return;
		}
		
		// If the user is signed in and the they have approved the application
		// Then generate a new auth code and redirect the user back to the application
		$authorised = $this->oauth_server->has_access_token($user_id, $client->client_id);
		if ($authorised)
		{
			$this->fast_code_redirect($client->client_id, $user_id, $params['redirect_uri'], $params['scope'], $params['state']);	
		}
		
		// If the user is already signed in and the app has the flag 'auto_approve'
		// Then generate a new auth code and redirect the user back to the application
		elseif ($user_id && $client->auto_approve == 1)
		{			
			$this->fast_code_redirect($client->client_id, $user_id, $params['redirect_uri'], $params['scope'], $params['state']);
		}
		
		// If we've not redirected already we need to show the user the approval form
		
		// Has the user clicked the authorise button
		$doauth = $this->input->post('doauth');
		
		if ($doauth)
		{		
			switch($doauth)
			{
				// The user has approved the application.
				// Generate a new auth code and redirect the user back to the application
				case "Approve":
								
					$code = $this->oauth_server->generate_new_authorise_code($client->client_id, $user_id, $params['redirect_uri'], $params['scope']);
					$redirect_uri = $this->oauth_server->build_redirect($params['redirect_uri'], array('code='.$code.'&state='.$params['state']));									
				break;
				
				// The user has denied the application
				// Do a low redirect back to the application with the error
				case "Deny":
				
					// Append the error code
					$redirect_uri = $this->oauth_server->build_redirect($params['redirect_uri'], array('error=access_denied&error_description=The+authorization+server+does+not+support+obtaining+an+authorization+code+using+this+method&state='.$params['state']));									
				break;
				
			}
			
			// Redirect back to app
			$this->session->unset_userdata(array('params'=>'','client_details'=>'', 'sign_in_redirect'=>''));
			$this->load->view('oauth2/redirect', array('redirect_uri'=>$redirect_uri, 'client_name'=>$client->name));
		}
		
		// The user hasn't approved the application before and it's not an internal application
		else
		{
			$vars = array(
				'client_name' => $client->name
			);
			
			$this->load->view('oauth2/authorise', $vars);
		}
	}
	
	
	/**
	 * Generate a new access token
	 */
	function access_token()
	{
		// Get query string parameters
		// ?grant_type=authorization_code&client_id=XXX&client_secret=YYY&redirect_uri=ZZZ&code=123
		$params = $this->oauth_server->params(array('code'=>TRUE, 'client_id'=>TRUE, 'client_secret' => TRUE, 'grant_type' => array('authorization_code') ,'redirect_uri'=>TRUE));
		
		// If missing/bad param
		if ($params == FALSE)
		{
			$this->_fail($this->oauth_server->param_error);
			return;
		}
				
		// Validate client_id and redirect_uri
		$client_details = $this->oauth_server->validate_client($params['client_id'], NULL, $params['redirect_uri']); // returns object or FALSE
		if ($client_details === FALSE )
		{
			$this->_fail("[OAuth client error: unauthorized_client] The client is not authorised to request an authorization code using this method.", TRUE);
			return;
		}
		
		// Respond to the grant type
		switch($params['grant_type'])
		{
			case "authorization_code":
			
				// Validate the auth code
				$session_id = $this->oauth_server->validate_authorise_code($params['code'], $params['client_id'], $params['redirect_uri']);
				if ($session_id === FALSE)
				{
					$this->_fail("[OAuth client error: invalid_request] Invalid authorization code");
					return;
				}
				
				// Generate a new access_token (and remove the authorise code from the session)
				$access_token = $this->oauth_server->new_access_token($session_id);
				
				// Send the response back to the application
				$this->_response(array('access_token' => $access_token, 'token_type' => '', 'expires_in' => NULL, 'refresh_token' => NULL));
				return;
			
			break;

			// When refresh tokens are implemented the logic would go here
		}	
	}
		
	
	/**
	 * Resource servers will make use of this URL to validate an access token
	 */
	function verify_access_token()
	{
		// Get query string parameters
		// ?grant_type=access_token=XXX&scope=YYY
		$params = $this->oauth_server->params(array('access_token'=>TRUE, 'scope'=>FALSE));
		
		// If missing/bad param
		if ($params == FALSE)
		{
			$this->_fail($this->oauth_server->param_error);
			return;
		}
						
		// Get the scope
		$scopes = array('basic');
		if (isset($params['scope']))
		{
			$scopes = explode(',', $params['scope']);
		}
		
		// Test scope
		$result = $this->oauth_server->validate_access_token($params['access_token'], $scopes);
		
		if ($result)
		{		
			$resp = array(
				'access_token'=>$params['access_token'],
			);
			
			$this->_response($resp);
		}
		
		else
		{
			$this->_fail('Invalid `access_token`', FALSE);
			return;
		}
		

	}
	
	
	/**
	 * Generates a new auth code and redirects the user
	 * 
	 * @access private
	 * @param string $client_id
	 * @param string $user_id
	 * @param string $redirect_uri
	 * @param array $scope
	 * @param string $state
	 * @return void
	 */
	private function fast_code_redirect($client_id = "", $user_id = "", $redirect_uri = "", $scopes = array(), $state = "")
	{
		$code = $this->oauth_server->generate_new_authorise_code($client_id, $user_id, $redirect_uri, $scopes);
		$redirect_uri = $this->oauth_server->build_redirect($redirect_uri, array('code='.$code."&state=".$state));
		
		$this->session->unset_userdata(array('params'=>'','client_details'=>'', 'sign_in_redirect'=>''));
		redirect($redirect_uri, 'location');	
	}
	
	
	/**
	 * Show an error message
	 * 
	 * @access private
	 * @param mixed $msg
	 * @return string
	 */
	private function _fail($msg, $friendly=FALSE)
	{
		if ($friendly)
		{
			show_error($msg, 500);
		}
		
		else
		{
			$this->output->set_status_header('500');
			$this->output->set_header('Content-type: text/plain');
			$this->output->set_output(json_encode(array('error'=>1, 'error_message'=>$msg)));
		}
	}
	
	
	/**
	 * JSON response
	 * 
	 * @access private
	 * @param mixed $msg
	 * @return string
	 */
	private function _response($msg)
	{
		$msg['error'] = 0;
		$msg['error_message'] = '';
		$this->output->set_status_header('200');
		$this->output->set_header('Content-type: text/plain');
		$this->output->set_output(json_encode($msg));	
	}

}