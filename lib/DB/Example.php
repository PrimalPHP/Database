<?php 
namespace DB;
use \Primal\Database\Query;

/**
 * Example Implementation of Primal Database Record
 *
 * @package Primal.Database
 */

class Example extends \Primal\Database\MySQL\Record {
	var $tablename = 'table_name';
	var $primary = array('id');


}