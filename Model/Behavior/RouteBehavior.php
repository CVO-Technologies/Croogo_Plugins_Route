<?php
/**
 * Route Behavior
 *
 * PHP version 5
 *
 * @category Behavior
 * @package  Croogo
 * @version  1.4
 * @author   Damian Grant <codebogan@gmail.com>
 * @license  http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link     http://www.croogo.org
 */
class RouteBehavior extends ModelBehavior {

	/**
	 * Components used by this Behavior
	 *
	 * @var array
	 * @access public
	 */	
	public $components = array('CRoute');
	
	/**
	 * Default filename to store custom routes in
	 *
	 * @var string
	 * @access public
	 */		
	public $customRoutesFilenameWithoutPath = 'routes.php';

	/**
	 * Setup
	 *
	 * @param object $model
	 * @param array  $config
	 * @return void
	 */
    public function setup(&$model, $config = array()) {
		if (is_string($config)) {
			$config = array($config);
		}
		$this->settings[$model->alias] = $config;
    }

	/**
	 * afterFind callback
	 *
	 * @param object  $model
	 * @param array   $results
	 * @param boolean $primary
	 * @return array
	 */
	public function afterFind(&$model, $results = array(), $primary = false) {
		if ($model->alias == 'Node') {
			foreach ($results AS $i => $result) {
				$results[$i]['Route'] = array();
			}
		}
		return $results;
	}
		
	/**
	 * beforeValidate callback
	 * Invoked after a Node is saved/edited
	 *
	 * @param object  $model
	 * @return boolean
	 */
	public function beforeValidate(&$model) {
		/* these validation rules may cause validation to fail on node submit */
		$model->validate['route_alias'] = array(
			'aliasDoesNotExist' => array(
				'rule' => array('doesAliasExist'),
				'message' => 'This alias is already in use by another route',
			),
			'aliasValid' => array(
				'rule' => array('isAliasValid'),
				'message' => 'The alias must not begin with a slash or backslash character. Only alphanumeric characters, underscores, hyphens and slashes or backslashes are acceptable.',
			),
		);
		
		return true; //we have added the validation checks we want the system to invoke
	}
	
	/*public function beforeSave(&$model) {

		return false;
	}*/
	
	/**
	 * afterDelete callback
	 * Invoked after a Node is deleted
	 *
	 * @param object  $model
	 */	
	public function afterDelete(&$model) {
		if ($model->name == 'Node') {
			//see if a route exists for this node
			//lets look for the node_id in the Routes table
			$node_id = $model->data['Node']['id'];
			$params = array('conditions' => array('Route.node_id' => $node_id));
			$this->Route = ClassRegistry::init('Route.Route');;		
			$matchingRoute = $this->Route->find('first', $params);
			if ($matchingRoute != null) { //let's delete the matching route
				$routeID = $matchingRoute['Route']['id'];
				$this->Route->delete($routeID);
				clearCache();
				$this->write_custom_routes_file();
			}
		}
	}
	
	/**
	 * afterSave callback
	 * Invoked after a Node is saved/edited
	 *
	 * @param object  $model
	 * @param boolean   $created
	 */		
	public function afterSave(&$model, $created) {

		if ($model->alias == 'Node') {
			$data = $model->data['Node'];
			
			//this statement prevents code from executing when node is deleted
			if (isset($data['route_alias'])) { 
				$route_alias = $data['route_alias'];
				//disabled route_status checkboxes don't submit any data'
				//assume they are disabled and checked if not defined
				if (!isset($data['route_status'])) {
					$route_status = 0;
				}
				else {
					$route_status = $data['route_status'];
				}
		
				if (isset($data['id'])) {
					$node_id = $data['id'];
				}
				else {
					$node_id = $model->id;
				}

				//lets look for the node_id in the Routes table
				$params = array('conditions' => array('Route.node_id' => $node_id));
				$this->Route = ClassRegistry::init('Route.Route');;		
				$matchingRoute = $this->Route->find('first', $params);
				
				if ($matchingRoute != null) { //let's update our route with the new path 
					if ((trim($route_alias)) == '') {
						//empty alias - delete the route id
						$route_id = $matchingRoute['Route']['id'];
						$this->Route->delete($route_id, false);
					}
					else { 
						//non-empty alias
						$route_id = $matchingRoute['Route']['id'];
						$this->Route->id = $route_id;

						$this->Route->saveField('alias', $route_alias);
						$this->Route->saveField('status', $route_status);
						$this->Route->saveField('body', "array('plugin' => 'nodes', 'controller' => 'nodes', 'action' => 'view', 'type' => '".$data['type']."', 'slug' => '".$data['slug']."')");
					}
				}
				else {
					//create a new route that points to the node 
					$this->Route->create();
					$this->data = array();
					$this->data['Route'] = array();
					$this->data['Route']['alias'] = $route_alias;
					$this->data['Route']['node_id'] = $node_id;
					$this->data['Route']['body'] = "array('plugin' => 'nodes', 'controller' => 'nodes', 'action' => 'view', 'type' => '".$data['type']."', 'slug' => '".$data['slug']."')";
					$this->data['Route']['status'] = $route_status;
					if ($this->Route->save($this->data)) {
						//Saved	
					}
					else {
						//Not Saved
					}
				}
				
				clearCache();
				$this->write_custom_routes_file();
			}
		}
	}
		
	/**
	 * Determine path of customRoutesFile
	 *
	 * @return string
	 */
	function get_custom_routes_filepath() {
		$path = APP . 'Plugin' . DS . 'Route' . DS . 'Config' . DS . $this->customRoutesFilenameWithoutPath;
		return $path;
	}
	
	/**
	 * Retrieve Routes from Database
	 *
	 * @return array
	 */
	function get_custom_routes_from_db() {
		$params = array('conditions' => array('Route.status' => 1));
		$routes = $this->Route->find('all', $params);
		return $routes;
	}
	
	/**
	 * Generate CroogoRouter::connect PHP code to save in the customRoutesFile (e.g. routes.php)
	 *
	 * @return string
	 */
	function get_custom_routes_code() {
		$routes = $this->get_custom_routes_from_db();
		$newline = "\n";
		$code = '';
		$code .= '<?php' . $newline;
		$code .= '#DO NOT EDIT THIS FILE DIRECTLY!' . $newline;
		$code .= '#IT IS UPDATED BY THE ROUTE PLUGIN WHENEVER YOU ADD, DELETE, ENABLE OR DISABLE A ROUTE.' . $newline;		
		foreach($routes as $key=>$route) {
			$testing = eval('return '.$route['Route']['body'].';');
			if (is_array($testing)) {
				$code .= 'CroogoRouter::connect(\'/'.$route['Route']['alias'].'\', '.$route['Route']['body'].');' . $newline;
			}
		}
		$code .= '?>';
		return $code;
	}
	
	/**
	 * Convert UNIX File Permissions Umask into human-readable string
	 *
	 * @param integer $perms
	 * @return string
	 */
	function _resolveperms($perms) {
		$oct = str_split( strrev( decoct( $perms ) ), 1 );
		//               0      1      2      3      4      5      6      7
		$masks = array( '---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx' );
		return(
			sprintf( 
				'%s %s %s',
				array_key_exists( $oct[ 2 ], $masks ) ? $masks[ $oct[ 2 ] ] : '###',
				array_key_exists( $oct[ 1 ], $masks ) ? $masks[ $oct[ 1 ] ] : '###',
				array_key_exists( $oct[ 0 ], $masks ) ? $masks[ $oct[ 0 ] ] : '###'
			)
		);
	}
	
	/**
	 * Write Custom Routes to the custom route file that is included_once by the croogo_router.php file	
	 *
	 * @param array $check
	 * @return boolean
	 */
	function write_custom_routes_file() {
		$path = $this->get_custom_routes_filepath();
		$resultArray = array();
		$resultArray['output'] = '';
		$resultArray['code'] = '';			
	
		try {
			$permissions = @fileperms ( $path );
			$fileowner = @fileowner($path);
			$filegroup = @filegroup($path);
			$fileownerarray = posix_getpwuid($fileowner);
			$filegrouparray = posix_getgrgid($filegroup);
			$webserver_process_user_array = posix_getpwuid(posix_geteuid());
			$webserver_process_group_array = posix_getgrgid($filegroup);
		
			if (is_writable($path))
			{
				$fp = @fopen($path, 'w');
				if ($fp !== false) {
					$code = $this->get_custom_routes_code();
					$resultArray['code'] = $code;
					fwrite($fp, $code);
					fclose($fp);
					$resultArray['output'] .=  "File has been written to:<BR />";
					$resultArray['output'] .= $path."<BR>";
				}
			}
			else {
				$resultArray['output'] .= "<h3 style='color: red;'>Cannot overwrite ".basename($path)."!</h3>";
				$resultArray['output'] .= "<strong style='color: red;'>Please ensure file is writable by the webserver process.</strong>";					
				$resultArray['output'] .= "<BR><BR>";
				$resultArray['output'] .= "<strong>File Location: </strong>".$path;					
				$resultArray['output'] .= "<BR>";					
				if ($permissions != 0) {
					$resultArray['output'] .= "<strong>File Permissions are: </strong>". substr(sprintf('%o', $permissions), -4);
				}
				else {
					$resultArray['output'] .= "<strong>File Permissions are: </strong> Unknown (permissions issue?)";
				}
				$resultArray['output'] .= "<BR>";
				if ($permissions == 0) {
					$resultArray['output'] .= "<strong>File Mask is: </strong> Unknown (permissions issue?)";
				}
				else {
					$resultArray['output'] .= "<strong>File Mask is: </strong>".$this->_resolveperms($permissions);
				}
				$resultArray['output'] .= "<BR>";
				if ($fileowner === false) {
					$resultArray['output'] .= "<strong>Owned by User: </strong> Unknown (permissions issue?)";
				}
				else {
					$resultArray['output'] .= "<strong>Owned by User: </strong>".$fileownerarray['name'];
				}
				$resultArray['output'] .= "<BR>";					
				$resultArray['output'] .= "<strong>Owned by Group: </strong>".$filegrouparray['name'];					
				$resultArray['output'] .= "<BR>";					
				$resultArray['output'] .= "<strong>Webserver running as User: </strong>".$webserver_process_user_array['name'];
				$resultArray['output'] .= "<BR>";					
				$resultArray['output'] .= "<strong>Webserver running in Group: </strong>".$webserver_process_group_array['name'];					
			}
		}
		catch (Exception $e) {
			//do nothing
		}
	}
	
	/**
	 * Validation: Check if alias exists already for another Route
	 *
	 * @param array $check
	 * @return boolean
	 */
	function doesAliasExist($check) {

		$check = $check->data['Node'];
		
		
		$route_alias = $check['route_alias'];
	
		if (!isset($check['id'])) {
			$node_id = -1;
		}
		else {
			$node_id = $check['id'];
		}
	
		if ($route_alias == '')	{
			return true; //allow blank aliases
			
		}
		else {
			if ($node_id == -1) { //we are adding a route
				$params = array('conditions' => array('Route.alias' => $route_alias));
			}
			else { //we are editing a route
				$params = array('conditions' => array('Route.alias' => $route_alias, 'Route.node_id !=' => $node_id));
			}
	
			$this->Route = ClassRegistry::init('Route.Route');;		
			$numMatches = 0;
			$numMatches = $this->Route->find('count', $params);

			
			if ($numMatches > 0) {
				return false;
			}
			else {
				return true;
			}
			
		}
	}
		
	/**
	 * Validation: Check if alias entered contains any bad characters
	 *
	 * @param array $check
	 * @return boolean
	 */			
	function isAliasValid($check) {
		$check = $check->data['Node'];
		$thealias = $check['route_alias'];
		$firstchar = substr($thealias, 0, 1);
		App::uses('Sanitize', 'Utility');
		$thealiassanitized = Sanitize::paranoid($thealias, array('/', '\\', '_', '-'));

		if (($firstchar == "/") || ($firstchar == "\\")) {
			return false;				
		}
		else if ($thealiassanitized == $thealias) {
			return true;
		}
		else {
			return false;
		}
	}	
}