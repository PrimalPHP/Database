<?php
namespace Primal\Database\MySQL;

use \Primal\Database\Connection;
use \PDO, \DateTime;

/**
 * Primal\Database\Record - PHP/MySQL Object Relationship Model
 * 
 * @package Primal
 * @author Jarvis Badgley
 * @copyright 2008 - 2012 Jarvis Badgley
 */

class Record extends \ArrayObject {
	/**
	 * Table column description cache
	 * @var array
	 * @static
	 * @access private
	 */
	static $keysreference;
	
	/**
	 * Record was found upon loading.
	 *
	 * @var boolean
	 */
	public $found = null;
	
	/**
	 * Name of the database the table resides in.  Optional, should only be set if the application needs to work on multiple databases.
	 *
	 * @var string
	 * @access protected
	 */
	protected $dbname;
	
	/**
	 * Name of the database table this model will be interfacing with.
	 *
	 * @var string
	 * @access protected
	 */
	protected $tablename;
	
	/**
	 * Array containing the table's column names.
	 * If not provided by the model subclass, Primal\Database\Record will query the database for the table description
	 *
	 * @var array
	 * @access protected
	 */
	protected $keys;

	/**
	 * Array containing the table's primary keys.
	 * If not provided by the model subclass, Primal\Database\Record will query the database for the table description
	 *
	 * @var array
	 * @access protected
	 */
	protected $primary;

	/**
	 * Name of the auto_increment field for the table, if one is defined in the schema.
	 * Auto-populated by Record if either $keys or $primary is omitted.
	 *
	 * @var array
	 * @access protected
	 */
	protected $auto_increment_field;
	
	/**
	 * PDO object used for communication with the server
	 * If this is not set by the descendent, Record attempts to use Connection::Link()
	 *
	 * @var array
	 * @access protected
	 */
	protected $pdolink;
	
	
	
		
	/**
	 * Exports the record contents as a named array.
	 *
	 * @return array
	 */	
	public function export() {return $this->getArrayCopy();}

	/**
	 * Imports a named array into the record contents.
	 *
	 * @param array $in
	 */	
	public function import($in) {
		if ($in instanceof Record) $in = $in->export();
		$this->exchangeArray( array_merge($this->export(), $in) );
	}

	
	/**
	 * Primal\Database\Record is not intended to be used on it's own and should be implemented as a subclass for the specific model.
	 * However, in the case of quick and/or simple tasks, the class may be instantiated as a standalone object as such:
	 *
	 *		$o = new Record('TableName');
	 *
	 * If the Record is implemented as a subclass which defines the table name, the constructor does one of two things
	 * depending on the data passed to it.  If the argument is an array the argument is used as the initial record; otherwise
	 * the value is converted to a string and passed to the load() function as the primary key value.
	 * 
	 * If the argument is absent, an empty record is instantiated.
	 * 
	 * @param array|integer|string $o optional
	 */
	function __construct($o=null, $pdolink=null) {

		//set up our pdo link.  if one is provided, always use it. If not, verify that Primal\Database\Connection exists and then grab the named link, or default if no name provided.
		if ($pdolink instanceof PDO) {
			$this->pdolink = $pdolink;
		} elseif (!$this->pdolink && class_exists('\\Primal\\Database\\Connection')) {
			$this->pdolink = Connection::Link($pdolink);
		} else {
			throw new RecordException('Could not find Primal\\Database\\Connection, you must provide a PDO database link.');
		}

		
		if ($o) {
			if (is_array($o)) {
				//if passed object is an array, load it into arrayaccess as if it were the record contents
				parent::__construct($o);
			} else {
				//if tablename is defined, assume $o is a primary key and load the record.
				//otherwise, assume $o is the table name and load the table definition.
				if ($this->tablename && $this->primary) $this->load($o);
				elseif (is_string($o)) {
					$this->tablename = $o;
					$this->_loadKeys();
				}
			}
		}
	}
		
	
	/**
	 * Returns the model's table name as an SQL ready string.  Includes the database name if defined.
	 *
	 * @return string
	 * @access protected
	 */
	protected function tablename() {
		return $this->dbname ? "`{$this->dbname}`.`{$this->tablename}`" : "`{$this->tablename}`";
	}
	
	/**
	 * Internal function to query the database for the table description.
	 *
	 * @return void
	 * @access private
	 */
	private function _loadKeys() {
		if (static::$keysreference[$this->tablename()]) {
			$this->keys = static::$keysreference[$this->tablename()]['keys'];
			$this->primary = static::$keysreference[$this->tablename()]['primary'];
			$this->auto_increment_field = static::$keysreference[$this->tablename()]['auto_increment_field'];
			return;
		}

		$tablename = $this->tablename();
		
		$qs = $this->pdolink->query("SHOW COLUMNS FROM {$tablename}");
		$rows = $qs->fetchAll(PDO::FETCH_ASSOC);
		
		foreach ($rows as $row) {
			$t = explode('(',$row['Type']);
			$this->keys[$row['Field']] = $t[0];
			if ($row['Key']=='PRI') $this->primary[] = $row['Field'];
			if ($row['Extra']=='auto_increment') $this->auto_increment_field = $row['Field'];
		}
		if (empty($this->keys)) throw new RecordException("Can not use Record: Couldn't load any table fields.",E_USER_ERROR);
		static::$keysreference[$this->tablename()]['keys'] = $this->keys;
		static::$keysreference[$this->tablename()]['primary'] = $this->primary;
		static::$keysreference[$this->tablename()]['auto_increment_field'] = $this->auto_increment_field;
	}
	
	/**
	 * Internal function to verify that all primary keys have been defined in the record when performing a save() or set()
	 *
	 * @return void
	 * @access private
	 */
	private function _testPrimarys() {
		foreach ($this->primary as $pkey) if (!isset($this[$pkey]) || $this[$pkey]===null) return false;
		return true;
	}
	
		
	/**
	 * Loads a record from the database.
	 *
	 * Syntax:
	 * 		$o->load()
	 *			Loads the record using primary keys already present in the record array.
	 *
	 * 		$o->load(value)
	 *			Loads the record using a single primary key value
	 *
	 * 		$o->load(value, columnName)
	 *			Loads the record using a single value in the named column.  If multiple rows are found with that value the first row returned is used.
	 *
	 * 		$o->load(array('columnName'=>'value', ... ))
	 *			Loads the record using a sequence of columnName/Value pairs.  Necessary if your table contains a multi-column primary key.
	 *
	 * @param integer|string|array $value optional The value of the primary key, an array of key/value pairs to search for.  If absent, the function will attempt to load using information already present in the record.
	 * @param string $field optional If $value is an integer or string, $field may be used to define a specific column name to search within.
	 * @return boolean True if a matching record was found
	 */
	public function load($value=null, $field=null) {
		
		//no value was defined, so we're just reloading the existing record using the primary key
		if ($value===null) {
			if (empty($this->primary)) $this->_loadKeys();
			$value = array();
			foreach ($this->primary as $fld) {
				//loop through all primary keys and verify they exist in the record
				//if they don't throw an exception.
				//if they do, add them to the search array
				if (!isset($this[$fld])) throw new RecordException("Can not load Record: No value supplied for primary key '$fld'.",E_USER_ERROR);
				else $value[$fld] = $this[$fld];
			}
		} else {
			
			//no field was defined and the value is not an array, so we assume primary key search.
			//test to make sure the primary key field is known
			if ($field===null && !is_array($value)) {
				if (empty($this->primary)) $this->_loadKeys();
				if (empty($this->primary)) throw new RecordException("Can not load Record: No field specified and table lacks primary keys.",E_USER_ERROR);
				$field = array_shift($this->primary);
			}

			//$value is not an array, so we're just performing a search on a single field.
			//create an array of search values
			if (!is_array($value)) {
				$value = array($field=>$value);
			}

		}
		
		//we should now have an array of column=>value pairs.
		
		//loop through the array and build a where string, storing the parameters in an indexed array
		$where = array();
		$data = array();
		foreach ($value as $column=>$param) {
			$where[] = "`{$column}` = :$column";
			$data[":$column"] = $param;
		}
		$where = implode(' AND ', $where);

		//now perform a prepared statement query using this data
		//if the data is loaded via the query, we're done.  if it isn't, 
		//set the record to the passed values, but mark found as false
		if ($this->loadWhere($where, $data)) {
			return true;	
		} else {
			$this->found = false;
			if (is_array($value)) $this->import($value);
			else $this[$field] = $value;
		}
	}
	
	
	/**
	 * Loads a record using a defined SQL WHERE clause.
	 *
	 * @param string $wherestring 
	 * @return boolean True if a matching record was found
	 */
	function loadWhere($wherestring, $data=null) {
		$tablename = $this->tablename();
		
		$query = "SELECT {$tablename}.* FROM {$tablename} WHERE {$wherestring}";
		
		$qs = $this->pdolink->prepare($query);
		if ($data) $qs->execute($data);
		else $qs->execute();
		
		if ($qs->rowCount() > 0) {
			$this->import($qs->fetch(PDO::FETCH_ASSOC));
			$this->found = true;
			return true;
		} else {
			return false;
		}

	}
	
	/**
	 * Deletes the current record from the database using primary keys defined in the record
	 *
	 * @return void
	 */
	function delete() {
		$tablename = $this->tablename();
		if (!$this->tablename) throw new RecordException("Can not save Record: Missing table name.",E_USER_ERROR);
		if (!$this->_testPrimarys()) throw new RecordException("Can not delete Record: Missing values for primary keys.",E_USER_ERROR);
		
		$where = array();
		$data = array();
		foreach ($this->primary as $pkey) {
			$where[] = "`{$pkey}`= :$pkey";
			$data[":$pkey"] = $this[$pkey];
		}

		if (!empty($where) && !empty($data)) {
			$where = implode(' AND ',$where);
			$qs = $this->pdolink->prepare("DELETE FROM {$tablename} WHERE {$where}");
			$qs->execute($data);
			
			if ($qs->rowCount() > 0) {
				foreach ($this->primary as $pkey) unset($this[$pkey]);
				return true;
			} else {
				return false;
			}
		}
	}
	
	
	
	/**
	 * Saves the record to the database using the primary keys in the record.
	 *
	 * @param string $replacement optional Skips testing if the record already exists in the database and inserts to the table using a REPLACE query.
	 * @return boolean True is record was saved.
	 */
	function save($replacement=false) {
		$tablename = $this->tablename();
		if (!$this->tablename) throw new RecordException("Can not save Record: Missing table name.",E_USER_ERROR);
		if (empty($this->keys)) $this->_loadKeys();
		

		$where = array();
		$data = array();
		if ($replacement) {
			//if this is a replacement, set found to false to force an insert
			$this->found = false;

		} else {
			//if the record contains all the primary keys, build a where clause
			if ($this->_testPrimarys()) {
				foreach ($this->primary as $pkey) {
					$where[] = "`{$pkey}` = :$pkey";
					$data[":$pkey"] = $this[$pkey];
				}
				$wherestring = "WHERE ".implode(' AND ', $where);
			}
		
			//if we don't know that it exists check if it exists before we attempt to write the data
			if ($this->found === null && $where) {

				$qs = $this->pdolink->prepare("SELECT COUNT(*) FROM {$tablename} {$wherestring}");
				$qs->execute($data);
				if ($qs->rowCount() > 0 && array_shift($qs->fetch())) {
					$this->found = true;
				} else {
					$this->found = false;
				}
			}
		}
		
		//build the array of new data to be written into the DB.
		$set = array();
		$setdata = array();
		foreach ($this as $column=>$value)
			if (isset($this->keys[$column]) && $column != $this->auto_increment_field) {
			//for each field in the record that is also in the table schema, and isn't an auto-increment value
			$set[] = "`{$column}` = :set$column";
			$setdata[":set$column"] = $this->processValue($value, $this->keys[$column], $column);
		}
		$setstring = empty($set)?"":"SET ".implode(', ',$set);
			

		//build the query
		if ($this->found) {
			//record exists, so we're doing an update
			$query = "UPDATE {$tablename} $setstring $wherestring";
			$data = array_merge($data, $setdata);
		} else {
			$query = ($replacement?"REPLACE":"INSERT")." INTO {$tablename} $setstring";
			$data = $setdata;
		}

		//run the query
		$qs = $this->pdolink->prepare($query);
		if ($qs->execute($data)) {
			if (!$this->found && $this->auto_increment_field) {
				$this[$this->auto_increment_field] = $this->pdolink->lastInsertId();
			}
			$this->found = true;
			return true;
		} else {
			return false;
		}

	}
	
	/**
	 * Sets the specified field to the value supplied and updates the database with the change.  If record does not exist in database, it is created.
	 *
	 * @param string $field Column name
	 * @param string|integer $value Value to be set
	 */
	function set ($field, $value) {
		$tablename = $this->tablename();
		if (!$this->tablename) throw new RecordException("Can not save Record: Missing table name.",E_USER_ERROR);
		if (empty($this->keys)) $this->_loadKeys();
		if (!$this->_testPrimarys()) throw new RecordException("Can not save Record: Missing values for primary keys.",E_USER_ERROR);


		//key isn't in the schema, reject.
		if (!$this->keys[$field]) return false;

		//build the wherestring
		$where = array();
		$data = array();
		foreach ($this->primary as $pkey) {
			$where[] = "`{$pkey}` = :$pkey";
			$data[":$pkey"] = $this[$pkey];
		}
		$wherestring = "WHERE ".implode(' AND ',$where);

		//if we don't know if the record exists, verify
		if (!$this->found === null && $where) {
			$qs = $this->pdolink->prepare("SELECT COUNT(*) AS count FROM {$tablename} {$wherestring}");
			$qs->execute($data);
			if ($qs->rowCount() > 0) {
				$this->found = true;
			} else {
				$this->found = false;
			}
		}
		
		//set the value internally
		$this[$field]=$value;
		
		//if the record does exist, do a single column update.  Otherwise trigger a save call to create the record.
		if ($this->found) {
			$qs = $this->pdolink->prepare("UPDATE {$tablename} SET `{$field}`= :ABCD {$wherestring}");
			
			$data[':ABCD'] = $this->processValue($value, $this->keys[$field], $field);

			if ($qs->execute($data)) {
				return true;
			} else {
				return false;
			}
		} else {
			$this->save();
		}
	}
	

	/**
	 * Removes the named columns from the record set (blacklist)
	 *
	 * @param string|array $fields Column name(s) to remove
	 * @return void
	 */
	function filter() {
		$args = array();
		foreach (func_get_args() as $arg) {
			if (is_array($arg)) $args = array_merge($args, $arg);
			else $args[] = $arg;			
		}

		foreach ($args as $column) {
			unset($this[$column]);
		}
	}


	/**
	 * Removes all columns not included in the passed set (whitelist)
	 *
	 * @param string|array $fields Column name(s) to remove
	 * @return void
	 */
	function allow() {
		$args = array();
		foreach (func_get_args() as $arg) {
			if (is_array($arg)) $args = array_merge($args, $arg);
			else $args[] = $arg;			
		}

		foreach (array_keys($this->export()) as $column) {
			if (!in_array($column, $args)) unset($this[$column]);
		}
	}

	
	/**
	 * Internal function for escaping column values based on datatype.  
	 * Used by save() and set() to ensure that data in the INSERT and UPDATE query is formatted correctly for the table.
	 *
	 * @param mixed $value Data to be escaped
	 * @param string $type Datatype to escape as.
	 * @access protected
	 */
	protected function processValue ($value, $type) {
		switch (strtolower($type)) {
			case 'date':
			case 'datetime':
			case 'timestamp':
				if ($value instanceof DateTime) return $value->format('Y-m-d H:i:s');
				if ($value==='') return "";
				if ($value==='now') return date('Y-m-d H:i:s');
				if ($value==='null' || is_null($value)) return null;
				if ($value==='none') return "00-00-00 00:00:00";
				if (is_integer($value)) return date('Y-m-d H:i:s', $value);
				
				return date('Y-m-d H:i:s', strtotime((string)$value));
				
			case 'bool':
			case 'int':
			case 'tinyint':
			case 'smallint':
			case 'mediumint':
			case 'bigint':
				return (int)$value;
				
			case 'decimal':
			case 'float':
			case 'double':
			case 'real':
				return (float)$value;
				
			default:
				return $value;
		}
	}
	
	
	/**
	 * Returns the requested field formatted as a date if the value is a valid date. Returns null otherwise.
	 *
	 * @param string $field Column name to retreive.
	 * @param string $format Optional, date() format string
	 * @return void
	 */
	function getDate($field, $format='m/d/Y') {
		if (!$this[$field]) return null;
		$time = strtotime($this[$field]);
		if ($time>0) return date($format, $time);
		return null;
	}
	
	
	/**
	 * Fetch an array of models containing the results of the request.
	 *
	 * Syntax:
	 * 		$results = $o->LoadMultiple()
	 * 			Fetches all rows in the table.
	 *
	 * 		$results = $o->LoadMultiple(array)
	 * 			Fetches all rows in the table that match the column=>value pairs passed in the array.
	 *
	 * 		$results = $o->LoadMultiple('ORDER BY ...')
	 * 			Fetches all rows in the table, ordered by a defined SQL ORDER clause.
	 *
	 * 		$results = $o->LoadMultiple('WHERE ...')
	 * 			Fetches all rows in the table which match a defined SQL WHERE clause.
	 *
	 * 		$results = $o->LoadMultiple('SELECT ...')
	 * 			Fetches all rows in the table using a defined SQL SELECT statement.  Necessary for performing custom joins.
	 *
	 * @param string $query 
	 * @param string $field optional Array of data to be used for the prepared statement's parameters
	 * @return array
	 */
	static function LoadMultiple($query='', $data=null) {
		$o = new static();
		$tablename = $o->tablename();
		
		//received an array of column=>value pairs to search on.
		if (is_array($query) && $data==null) {
			//loop through the array and build a where string, storing the parameters in an indexed array
			$where = array();
			$data = array();
			foreach ($query as $column=>$param) {
				$where[] = "`{$column}` = :$column";
				$data[":$column"] = $param;
			}
			$query = "WHERE ".implode(' AND ', $where);
		}
		
		
		if (!$query) $fullquery = "SELECT {$tablename}.* FROM {$tablename}";
		else {
			$qb = array_shift(explode(' ',$query));
			switch (strtoupper($qb)) {
				case "INSERT":
				case "REPLACE":
				case "DELETE":
					throw new RecordException("You should not be using LoadMultiple for {$qb} queries");

				case "WHERE":
				case "GROUP":
				case "ORDER":
					$fullquery = "SELECT {$tablename}.* FROM {$tablename} {$query}";
					break;

				case "SELECT":
				default:
					$fullquery = $query;
					break;

			}
		}
		
		
		$qs = $o->pdolink->prepare($fullquery);
		$qs->execute($data);	
		
		$results = array();
		if ($qs->rowCount() > 0) foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$o = new static($row);
			$o->found = true;
			$results[] = $o;
		}
		
		return $results;
	}
	
	/**
	 * Returns all records for the data model
	 *
	 * @param string $orderby optional mysql order by directive
	 * @param string $limit optional number of rows to return
	 * @param string $start optional starting position for rows returned
	 * @return array
	 */
	static function All($orderby='', $limit=0, $start=0) {
		$limit = $limit?"LIMIT ".(int)$start.', '.(int)$limit:'';
		$orderby = $orderby?"ORDER BY $orderby":'';
		return static::LoadMultiple(trim("$orderby $limit"));
	}
	
	/**
	 * Returns the total number of records in the data model
	 *
	 * @return integer
	 */
	static function Total() {
		$o = new static();
		$tablename = $o->tablename();
		$q = "SELECT COUNT(*) FROM {$tablename}";

		$qs = $o->pdolink->prepare($q);
		$qs->execute();
		
		$row = $qs->fetch(PDO::FETCH_NUM);
		return (int)$row[0];
	}

	/**
	 * Returns an array of single characters and the total number records starting with that character for the column provided.
	 *
	 * @param string $column The name of the table column to index
	 * @param integer $page_count If provided, an extra field will indicate what page the character starts on
	 * @return array
	 */
	static function Index($column, $page_count = 0) {
		$o = new static();
		$tablename = $o->tablename();
		$q = "SELECT LEFT(`$column`, 1) AS `firstletter`, COUNT(*) AS `count` FROM {$tablename} GROUP BY `firstletter` ORDER BY `$column`";
		
		$qs = $o->pdolink->prepare($q);
		$qs->execute();
		
		$results = $qs->rowCount() ? $qs->fetchAll(PDO::FETCH_ASSOC) : array();
		
		if ($page_count > 0) {
			$total = 0;
			foreach ($results as $index=>$row) {
				$total += $row['count'];
				$results[$index]['page'] = floor($total/$page_count);
			}
		}

		return $results;
	}
	
	
	
	/**
	 * This is a development function only designed to help you build optimized Record objects.
	 * It is designed to be executed from a developer console and should never be used in actual code
	 *
	 * Syntax: Primal\Database\MySQL\Record::BuildDefaults('tablename');
	 * Result: Outputs PHP code for pre-defining the column names and primary keys for the table your model accesses.  
	 * Copy and paste the resulting code into your model's class declaration.
	 *
	 * @param string $tablename Name of the database table the model is created for.
	 * @return void
	 */
	public function BuildDefaults($tablename) {
		if (!$tablename) throw new RecordException("Can not build defaults: Missing table name.",E_USER_ERROR);

		$o = new self();
		$o->tablename = $tablename;
		$o->_loadKeys();
	
		echo "<pre>";
	
		echo 'var $tablename = '."'$tablename';\n";
		
		$ar = array();
		foreach ($o->primary as $key) $ar[] = "'$key'";
		echo 'var $primary = array(',"\n\t", implode(",\n\t",$ar), "\n);\n";
		if ($o->auto_increment_field) echo 'var $auto_increment_field = '."'$o->auto_increment_field';\n";

		$ar = array();
		foreach ($o->keys as $key=>$value) $ar[] = "'$key'=>'$value'";
		echo 'var $keys = array(',"\n\t", implode(",\n\t",$ar), "\n);\n";

		
		echo "</pre>";
	}
	
}


class RecordException extends \Exception {}