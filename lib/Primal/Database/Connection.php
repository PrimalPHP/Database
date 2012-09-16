<?php
namespace Primal\Database;
use \PDO;

/**
 * Primal\Database\Connection - PDO Database link management library
 * 
 * @package Primal
 * @author Jarvis Badgley
 * @copyright 2008 - 2012 Jarvis Badgley
 */

class Connection {
	const RETURN_NONE = -1;
	const RETURN_FULL = 0;
	const RETURN_SINGLE_ROW = 1;
	const RETURN_SINGLE_COLUMN = 2;
	const RETURN_SINGLE_CELL = 3;
	
	const METHOD_MYSQL = 'mysql';
	const METHOD_SQLITE = 'sqlite';
	
	/**
	 * Database link pointer
	 * @var array->PDO
	 * @static
	 * @access private
	 */
	private static $pdos = array();
	
	/**
	 * Database configurations
	 *
	 * @var array->array
	 * @static
	 * @access private
	 */
	private static $configs = array();

	/**
	 * Query() response pointer
	 * @var PDOStatement
	 * @static
	 * @access private
	 */
	private static $lastResult;

	
	/**
	 * Last made SQL query
	 * @var string
	 * @static
	 */
	public static $lastQuery;
	
	
	/**
	 * Sets the configuration settings for a server link
	 * 
	 * 	Acceptable Invocations:
	 * 		Connection::AddLinkConfig(array(
	 *			'method'   => Connection::METHOD_SQLITE,
	 * 			'database' => 'path/to/database.db'
	 * 		));
	 * 		Connection::AddLinkConfig('name', array(
	 *			'method'   => Connection::METHOD_MYSQL,
	 * 			'database' => 'MyDatabase',
	 * 			'host'     => 'localhost',
	 * 			'username' => 'mysqluser',
	 * 			'password' => 'password'
	 * 		));
	 * 		Connection::AddLinkConfig('name', Connection::METHOD_SQLITE, 'path/to/database.db');
	 * 		Connection::AddLinkConfig('name', Connection::METHOD_MYSQL, 'MyDatabase', 'localhost', 'mysqluser', 'password');
	 *
	 * @param string|array $name Name for the link or an array of link settings which will be used for a default single link
	 * @param string|array $method optional database connection method or an array of link settings that will be used for the defined name
	 * @param string $database optional Name of MySQL database or path to SQLite database.
	 * @param string $host optional MySQL server address. Only required if method is mysql. Defaults to localhost
	 * @param string $username optional MySQL server username. Only required if method is mysql. Defaults to root
	 * @param string|null $password optional MySQL server password. Only required if method is mysql. Defaults to no password
	 * @return void
	 * @static
	 */
	public static function AddLink ($name = null, $method=null, $database=null, $host='localhost', $username='root', $password=null) {
		if (is_array($name)) {
			static::$configs['Default'] = (object)$name;
		} elseif (is_string($name) && is_array($method)) {
			static::$configs[$name] = (object)$method;
		} elseif ($method !== null && $database !== null) {
			static::$configs[$name] = (object)array('method'=>$method, 'database'=>$database, 'host'=>$host, 'username'=>$username, 'password'=>$password);
		} else {
			throw new ConnectionExeption('Link configuration provided did not match expected parameters');
		}
	}
	

	/**
	 * PDO link recall function.  Returns the named connection, opening it if none exists.
	 *
	 * @param string $name Optional link name. If omitted will return the first connection defined in settings
	 * @static
	 */
	public static function Link ( $name = null ) {

		if ($name === null) {
			$name = array_keys(static::$configs);
			$name = array_shift($name);
		}

		if (!static::$pdos[$name]) static::Connect($name);
		return static::$pdos[$name];
	}
	

	/**
	 * Opens a connection to a MySQL server.
	 * All arguments are optional.  If left out, system will attempt to use declared constants for credentials and database name.
	 *
	 * @param string $name Name for the link.
	 * @param string $method optional database connection method. Only required if you are defining the link settings via Connect
	 * @param string $database optional Name of MySQL database or path to SQLite database.  Only required if you are defining the link settings via Connect
	 * @param string $host optional MySQL server address. Only required if method is mysql and you are defining the link settings via Connect
	 * @param string $username optional MySQL server username. Only required if method is mysql and you are defining the link settings via Connect. Defaults to 'root'
	 * @param string|null $password optional MySQL server password. Only required if method is mysql and you are defining the link settings via Connect.  Defaults to no password
	 * @return void
	 * @static
	 */
	public static function Connect ($name = null, $method=null, $database=null, $host='localhost', $username='root', $password=null) {
		if ($name === null) $name = count(static::$configs) >0 ? array_shift(array_keys(static::$configs)) : 'Default';

		if ($method !== null && $database !== null) {
			static::$configs[$name] = (object)array('method'=>$method, 'database'=>$database, 'host'=>$host, 'username'=>$username, 'password'=>$password);
		} elseif (isset(static::$configs[$name])) {
			$config = static::$configs[$name];
		} else {
			throw new ConnectionException('No database configuration could be found for '.$name);
		}
		
		$pdo = null;
		
		switch ($config->method) {
		case self::METHOD_MYSQL:
			$pdo = new PDO("mysql:host={$config->host};dbname={$config->database}", $config->username, $config->password, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
			break;
						
			
		case self::METHOD_SQLITE:
			$pdo = new PDO("sqlite:{$config->database}");
			break;
		}
		
		if (!isset($config->silentErrors) || $config->silentErrors === true) $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION ); //enable database exceptions
		
		return static::$pdos[$name] = $pdo;
	}
	
	
	/**
	 * Escapes passed string for use in an sql query
	 *
	 * @param mixed $value 
	 * @param string $name optional Name of the link to use. If omitted, grabs the first config defined
	 * @return string
	 */
	public static function Escape($value, $name = null) {
		if ($name === null) $name = array_shift(array_keys(static::$configs));

		$pdo = static::Link($name);
		
		return $pdo->quote($value);
	}
	

	/**
	 * Performs the requested database query and stores the returned resultset for use by other functions.
	 *
	 * @param string $query The SQL query to perform.
	 * @param string $name optional Name of the link to use. If omitted, grabs the first config defined
	 * @return PDOStatement
	 */
	public static function Query($query, $name = null) {
		if ($name === null) $name = array_shift(array_keys(static::$configs));

		$pdo = static::Link($name);
		
		static::$lastQuery = $query;
		
		$config = static::$configs[$name];
		if (isset($config->debugLog) && is_string($config->debugLog) && !empty($config->debugLog)) {
			error_log(date('Ymd.His').' '.static::$lastQuery, 3, $config->debugLog);
		}
		
		static::$lastResult = $pdo->query($query);
		return static::$lastResult;
	}
	
	/**
	 * Performs the request query and returns the results in the format constant specified:
	 *  Connection::RETURN_NONE				Returns the number of affected rows.
	 *  Connection::RETURN_FULL				Returns an indexed array of column named arrays for all rows returned.
	 *  Connection::RETURN_SINGLE_ROW		Returns a column named array of the first row returned
	 *  Connection::RETURN_SINGLE_COLUMN	Returns an indexed array of all row results in the first column
	 *  Connection::RETURN_SINGLE_CELL		Returns a string containing the first column of the first row
	 *
	 * @param string $query The SQL query to perform.
	 * @param integer $mode Format to return the requested data as.
	 * @param string $name optional Name of the link to use. If omitted, grabs the first config defined
	 * @return array|string
	 * @static
	 */
	public static function QuickQuery($query, $mode=self::RETURN_FULL, $name = null) {
		static::Query($query, $name);
		$c = static::TotalResults();
		
		switch ((int)$mode) {
			case self::RETURN_NONE:
				return $c;

			case self::RETURN_SINGLE_ROW:
				return $c ? static::$lastResult->fetch(PDO::FETCH_ASSOC):array();
				
			case self::RETURN_SINGLE_COLUMN:
				return $c ? static::$lastResult->fetchAll(PDO::FETCH_COLUMN, 0):array();				
				
			case self::RETURN_SINGLE_CELL:
				if ($c) {
					$row = static::$lastResult->fetch(PDO::FETCH_NUM);
					return $row[0];
				} else {
					return null;
				}
					
			case self::RETURN_FULL:
			default:
				return $c ? static::$lastResult->fetchAll(PDO::FETCH_ASSOC):array();
		}
	}
	
	/**
	 * Performs the request query as a prepared statement and returns the results in the format constant specified:
	 *  Connection::RETURN_NONE				Returns the number of affected rows.
	 *  Connection::RETURN_FULL				Returns an indexed array of column named arrays for all rows returned.
	 *  Connection::RETURN_SINGLE_ROW		Returns a column named array of the first row returned
	 *  Connection::RETURN_SINGLE_COLUMN	Returns an indexed array of all row results in the first column
	 *  Connection::RETURN_SINGLE_CELL		Returns a string containing the first column of the first row
	 *
	 * @param string $query The SQL query to perform.
	 * @param array $data Array containing the bound parameters
	 * @param integer $mode Format to return the requested data as.
	 * @param string $name optional Name of the link to use. If omitted, grabs the first config defined
	 * @return array|string
	 * @static
	 */
	public static function PreparedQuery($query, array $data = null, $mode=self::RETURN_FULL, $name = null) {
		if ($name === null) $name = array_shift(array_keys(static::$configs));

		$pdo = static::Link($name);
		
		static::$lastQuery = static::Unprepare($query, $data);
		
		$config = static::$configs[$name];
		if (isset($config->debugLog) && is_string($config->debugLog) && !empty($config->debugLog)) {
			error_log(date('Ymd.His').' '.static::$lastQuery, 3, $config->debugLog);
		}
		
		static::$lastResult = $pdo->prepare($query);
		static::$lastResult->execute($data);
		
		$c = static::TotalResults();
		
		switch ((int)$mode) {
			case self::RETURN_NONE:
				return $c;
			
			case self::RETURN_SINGLE_ROW:
				return $c ? static::$lastResult->fetch(PDO::FETCH_ASSOC):array();
				
			case self::RETURN_SINGLE_COLUMN:
				return $c ? static::$lastResult->fetchAll(PDO::FETCH_COLUMN, 0):array();				
				
			case self::RETURN_SINGLE_CELL:
				if ($c) {
					$row = static::$lastResult->fetch(PDO::FETCH_NUM);
					return $row[0];
				} else {
					return null;
				}
					
			case self::RETURN_FULL:
			default:
				return $c ? static::$lastResult->fetchAll(PDO::FETCH_ASSOC):array();
		}
	}
	
	/**
	 * Retrieve the last auto incremented id number created by the most recent insert in this session
	 *
	 * @return integer
	 * @static
	 */
	public static function LastInsertID() {
		return static::Link()->lastInsertId();
	}
	
	
	/**
	 * Retrieve the total results available from the last executed query performed via Connection::Query()
	 *
	 * @return integer
	 * @static
	 */
	public static function TotalResults(){
		if (static::$lastResult) return static::$lastResult->rowCount();
		else return false;
	}
	
	
	/**
	 * Retrieve the total number of rows affected by the last executed INSERT, UPDATE, REPLACE or DELETE query.
	 *
	 * @return void
	 * @static
	 */
	public static function AffectedRows(){
		if (static::$lastQuery) return static::$lastQuery->rowCount();
		else return false;
	}
	
	
	/**
	 * Close a database connection.
	 *
	 * @param string $name optional Name of the link to use. If omitted, grabs the first config defined
	 * @return void
	 * @static
	 */
	public static function Close($name = null) {
		if ($name === null) $name = array_shift(array_keys(static::$configs));
		
		static::$pdos[$name] = null;
		unset(static::$pdos[$name]);
	}
	
	
	/**
	 * Combines a prepared query and data array to return unified query string.
	 * THIS FUNCTION IS FOR DEBUG PURPOSES ONLY, NEVER USE THIS IN REAL CODE
	 *
	 * @param string $query 
	 * @param string $data 
	 * @return string
	 */
	static function Unprepare($query, $data=null) {
		if (is_array($query)) { //this allows Unprepare to take raw query output from Primal\Database\Query
			$data = $query[1];
			$query = $query[0];
		}

		if (!is_array($data)) {
			return $query;
		}
		
		foreach ($data as $key=>$value) {
			$data[$key] = "'".static::Escape($value)."'";
		}
		
		$split = explode('?', $query);
		$stack = array();
		foreach ($split as $chunk) {
			$stack[] = $chunk;
			$stack[] = array_shift($data);
		}
		
		return implode('',$stack);
	}
	
}

class ConnectionException extends \Exception {}
