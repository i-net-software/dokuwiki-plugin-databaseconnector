<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Gerry Weissbach <gweissbach@inetsoftware.de>
 * @author     i-net software <tools@inetsoftware.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

class helper_plugin_databaseconnector extends DokuWiki_Plugin { // DokuWiki_Helper_Plugin

	var $supportedDatabases = array('odbc', 'mysqli');
	var $databaseType = null;
	var $databaseConnection = null;
	var $lastResultSet = null;
	var $lastStatementSet = null;
	var $lastResultWasQuery = true;

  
  function getMethods(){
    $result = array();
    $result[] = array(
      'name'   => 'setType',
      'desc'   => 'sets the database type',
      'params' => array("connectionname " . implode(', ', $this->supportedDatabases) => 'string'),
      'return' => array('success' => 'boolean'),
    );
    $result[] = array(
      'name'   => 'connect',
      'desc'   => 'create connection to database',
      'params' => array("'database', 'user', 'passsword', 'disconnect' (if connection is still open)" => 'array'),
      'return' => array('success' => 'object'),
    );
    $result[] = array(
      'name'   => 'close',
      'desc'   => 'close the database connection',
      'return' => array('success' => 'boolean'),
    );
    $result[] = array(
      'name'   => 'prepare',
      'desc'   => 'prepare a sql statement for execution with parameters',
      'params' => array("'Statement'" => 'String'),
      'return' => array('success' => 'boolean'),
    );
    $result[] = array(
      'name'   => 'execute',
      'desc'   => 'executes a prepares statement',
      'params' => array("'PARAM1', 'PARAM2', 'PARAM3' ..." => 'string'),
      'return' => array('success' => 'integer'),
    );
    $result[] = array(
      'name'   => 'query',
      'desc'   => 'executes a SQL query',
      'params' => array("'statement'" => 'string'),
      'return' => array('success' => 'integer'),
    );
    $result[] = array(
      'name'   => 'fetch_array',
      'desc'   => 'return an array of the actual resultset',
      'return' => array('success' => 'array'),
    );
    $result[] = array(
      'name'   => 'fetch_object',
      'desc'   => 'return an object of the actual resultset',
      'return' => array('success' => 'object'),
    );
    $result[] = array(
      'name'   => 'num_rows',
      'desc'   => 'return the amount of hte resultset',
      'return' => array('amount' => 'integer'),
    );

    return $result;
  }

	function setType($type) {

		if ( !in_array($type, $this->supportedDatabases) ) { return false;}

		$this->databaseType = $type;
		return true;
	}

	function connect($database, $user, $passwd, $hostname='localhost') {

		if ( empty($database) || empty($user) ||  empty($passwd) ) { return null; }
		if ( !is_null($this->databaseConnection) ) {
			$this->close();
		}

		switch( $this->databaseType ) {
			case 'odbc': $this->databaseConnection = odbc_connect( $database, $user, $passwd,  SQL_CUR_USE_ODBC); break;
			case 'mysqli':	$this->databaseConnection = @(new mysqli( $hostname, $user, $passwd, $database));
							if (@mysqli_connect_errno()) {
								// msg("Connect failed: %s\n", mysqli_connect_error(), -1);
								$this->databaseConnection = null;
								return false;
							}
							break;
							
			default: $this->close();
		}

		return ( is_null($this->databaseConnection) || $this->databaseConnection === false ? false : true);
	}

	function close() {
		
		if ( is_null($this->databaseConnection) ) {
			return true;
		}

		switch( $this->databaseType ) {
			case 'odbc': odbc_close( $this->databaseConnection ); return true; break;
			case 'mysqli': return @$this->databaseConnection->close(); break;
		}

		return false;
	}

	function query($statement) {
		
		if ( empty($statement) || is_null($this->databaseConnection) ) { return false; }
//		if ( substr($statement, -1) == ';' ) { $statement = substr($statement, 0, -1); }

		$result = false;
		switch( $this->databaseType ) {
			case 'odbc': $error = $result = odbc_exec( $this->databaseConnection, $statement); break;
			case 'mysqli':	if ( $this->lastResultSet ) { @$this->lastResultSet->close(); }
							$result = $this->databaseConnection->query($statement);

							$error = $this->databaseConnection->error;
							if ( !empty($error) ) {
								msg($error, -1);
							}
							break;
		}

		$this->lastResultSet = $result;
		$this->lastResultWasQuery = true;
		return $error;
	}

	function prepare($statement) {

		if ( empty($statement) || is_null($this->databaseConnection) ) { return false; }

		$result = false;
		switch( $this->databaseType ) {
			case 'odbc': $this->lastResultSet = odbc_prepare( $this->databaseConnection, $statement); break;
			case 'mysqli':	if ( $this->lastResultSet ) { @$this->lastResultSet->close(); }
							$this->lastResultSet = @$this->databaseConnection->prepare($statement);
							$result = $this->databaseConnection->error;
							if ( !empty($result) ) {
								msg($result, -1);
							}

							return $result;
							break;
		}

		return $this->lastResultSet;
	}

	function execute() {
		
		if ( empty($this->lastResultSet) ) { return false;}

		$args = func_get_args();
		if ( gettype($args) != 'array') {
			$args = array( $args );
		}
		
		if ( sizeof($args) == 1 && gettype($args[0]) == 'array' ) {
			$args = $args[0];
		}

		$result = false;

		switch( $this->databaseType ) {
			case 'odbc':	for ($i = 0; $i<sizeof($args); $i++) {
								if ( !is_numeric($args[$i]) ) {
									$args[$i] = $this->escapeParameter($args[$i]);
								}
							}

							$result = odbc_execute( $this->lastResultSet, $args);

							break;
			case 'mysqli':	if ( sizeof($args) > 0 ) {
								$bind_params = array();
								$types = '';
								for ($i = 0; $i<sizeof($args); $i++) {
									$bind_name = 'bind' . $i;

									if ( is_string($args[$i]) || empty($args[$i]) ) {
										$types .= 's';
										$args[$i]  = $this->escapeParameter($args[$i]);
									} else if ( is_float($args[$i]) ) {
										$types .= 'd';
									} else if ( is_int($args[$i]) ) {
										$types .= 'i';
									} else if ( is_bool($args[$i]) ) {
										$types .= 'b';
									}

									$$bind_name = $args[$i];
									$bind_names[] = &$$bind_name;
								}

								$bind_params[] = $types;
								$bind_params = array_merge($bind_params, $bind_names);
								
								$return = call_user_func_array(array($this->lastResultSet, 'bind_param'), $bind_params);
							}
							$this->lastResultSet->execute();

							$result = $this->lastResultSet->error;
							if ( !empty($result) ) {
								msg($result, -1);
							}

							break;
		}

		$this->lastResultWasQuery = false;
		return $result;
	}

	function insert_id() {
		
		if ( empty($this->lastResultSet) ) { return false;}
		$result = false;
		switch( $this->databaseType ) {
			case 'odbc':	$result = 0; break;
			case 'mysqli':	$result = $this->databaseConnection->insert_id; break;
		}
		
		return $result;
	}

	public function fetch() {

		if ( empty($this->lastResultSet) ) { return false;}
		switch( $this->databaseType ) {
			case 'odbc':	$result = odbc_fetch($this->lastResultSet);
			case 'mysqli':	$result = $this->lastResultSet->fetch();
		}

		return $result;
	}

	function bind_assoc (&$out) {
		if ( empty($this->lastResultSet) || empty($this->databaseConnection) ) { return false;}

		switch( $this->databaseType ) {
			case 'odbc':	return;
			case 'mysqli':	

				$data = $this->lastResultSet->result_metadata();
				$fields = array(); $out = array();
				$fields[0] = $this->lastResultSet;
				$count = 1;

				while($field = mysqli_fetch_field($data)) {
					$fields[$count] = &$out[$field->name];
					$count++;
				}   
				call_user_func_array(mysqli_stmt_bind_result, $fields);
			
			break;
		}
	}

	function fetch_array() {
		
		if ( empty($this->lastResultSet) ) { return false;}
		
		$result = false;
		switch( $this->databaseType ) {
			case 'odbc':	$result = odbc_fetch_array($this->lastResultSet);
			case 'mysqli':	$result = $this->lastResultSet->fetch_assoc();
		}
		
		return $result;
	}
	
	function fetch_object() {
		
		if ( empty($this->lastResultSet) ) { return false;}
		$result = false;
		switch( $this->databaseType ) {
			case 'odbc':	$result = odbc_fetch_object($this->lastResultSet);
			case 'mysqli':	$result = $this->lastResultSet->fetch_object();
		}
		
		return $result;
	}
	
	function num_rows($CurrRow = 1) {
		
		if ( empty($this->lastResultSet) ) { return -1;}
		$num_rows=0;

		switch( $this->databaseType ) {

			case 'odbc':	odbc_fetch_row($this->lastResultSet,0);
							while (odbc_fetch_row($this->lastResultSet))
								{
									$num_rows++;
								}

							odbc_fetch_row($this->lastResultSet, $CurrRow);

							break;
			case 'mysqli':	if ( ! $this->lastResultSet ) { return false; }
			
							$num_rows = $this->lastResultWasQuery ? $this->lastResultSet->num_rows : (
											$this->lastResultSet->store_result() ? $this->lastResultSet->affected_rows : $this->lastResultSet->affected_rows
										);
		}

		return $num_rows;
	}
	
	function _escapeParameter($input) {
	    return $this->escapeParameter($input);	    
	}
	
	function escapeParameter($input) {
		switch( $this->databaseType ) {
			case 'mysqli':	if ( $this->databaseConnection ) {
			                    return $this->databaseConnection->escape_string($input);
			                }
			                break;
			default:        return $input; 
		}

		return $input;
	}

	function error() {
		
		if ( is_null($this->databaseConnection) ) {
			return true;
		}

		switch( $this->databaseType ) {
			case 'odbc': return ''; break;
			case 'mysqli': return $this->databaseConnection->error; break;
		}

		return false;
	}
	
}
  
//Setup VIM: ex: et ts=4 enc=utf-8 :
