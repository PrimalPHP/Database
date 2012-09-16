#Primal.Database

Created and Copyright 2012 by Jarvis Badgley, chiper at chipersoft dot com.

Primal.Database is a set of three classes for interacting with MySQL databases using PHP Data Objects (PDO).  Each of the three classes is standalone and can be used without the others.  All three are setup within the Primal\Database\ namespace by default.

[Primal](http://www.primalphp.com) is a collection of independent micro-libraries that collectively form a [LAMP](http://en.wikipedia.org/wiki/LAMP_\(software_bundle\)) development framework, but is not required to use Primal.Database in your own projects.


###Primal\Database\Connection

PDO static wrapper class.  Creates a connection to the database on demand (via `Connection::Link()`) and retains it for the life of the request.  Also provides convenience functions for running one-off queries against the link.

Database links can be established either by calling `Connection::AddLink()` to define a configuration (the link wont be opened until it is requested with `Connection::Link()`), or by explicitly opening a link with `Connection::Connect()`.  See the class file for documentation.

###Primal\Database\MySQL\Query

Chainable asynchronous query builder class; allows for easy construction and execution of complex queries with data escapement.

```php
$q=Primal\Database\Query::Table('users','u')
   ->join("LEFT JOIN billing b ON (u.user_id = b.user_id)")
   ->orderBy('u.name')
   ->returns('u.id', 'u.name', 'b.start_date')
   ->whereBoolean('b.active', true)
   ->whereDateInRange('b.start_date', new DateTime('yesterday'));
$results = $q->select();
```

By default Query uses the default PDO instance defined in Primal\Database\Connection, but this may be overridden by calling `$query->setPDOLink()` with a different link.

###Primal\Database\MySQL\Record

Record is the meat of Primal Database; an Object Relationship Model class for easily reading and writing of table rows.  
Record extends ArrayObject to make working with individual rows as painless as possible. To create a model, just subclass Record and define the table name & primary key(s). That's all you need to start reading and writing rows in the database, Record writes all the queries for you, sanitizing data accordingly.

```php
class MyTable extends Primal\Database\Record {
	var $tablename = 'my_table';
	var $primary = array('id');
}

$row = new MyTable();
$row->load(15); //load function can take a single primary key, or an array of column named values.
echo $row['column_name'];
```

By default Record uses the default PDO instance defined in `Primal\Database\Connection`, but this may be overridden by passing the alternate object as the second parameter on constructor.

###DB\Example

The `\DB` namespace is the recommended (not required) location for all Record derived data models.  Each class would represent a single table in your database.  DB\Example is a template class to use for creating new models.
