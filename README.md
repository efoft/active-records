# ActiveRecords for Databases
=====

The package gives a simple and unified interface to interact with various database engines. You can operate with the same set of method independently of which database is on backend. Currently supported:
* MongoDB
* MySQL
* SQLite

### Installation
------
The package is intended to be used via Composer. Add this to you composer.json:
```
    "require": {
        "efoft/active-records" : "dev-master"
    }
```
or simply run from command-line:
```
composer require efoft/active-records
```

### Initialization
------
The package consists of the main class ActiveRecords and some Database Handlers. When initialize the class instance you need to specify one of the Handlers. Each type of the Handler in turn requires some parameters for database connection.

* MongoDB
  * on localhost without auth to database named 'testdb':
```
use ActiveRecords\ActiveRecords;
use ActiveRecords\Handlers\MongoDBHandler;

$ar = new ActiveRecords(new MongoDBHandler('testdb'));
```
  * on another host with auth and non-default port:
```
$ar = new ActiveRecords(new MongoDBHandler('testdb','username','password','host.example.com', 27018));
```

* MySQL
  * on localhost with auth to database named 'testdb':
```
use ActiveRecords\ActiveRecords;
use ActiveRecords\Handlers\MySQLHandler;

$ar = new ActiveRecords(new MySQLHandler('testdb','username','password'));
```
  * on another host with specific charset (UTF-8 is default):
```
$ar = new ActiveRecords(new MySQLHandler('testdb','username','password','host.example.com',3306,'cp1252'));
```

* SQLite
```
use ActiveRecords\ActiveRecords;
use ActiveRecords\Handlers\SQLiteHandler;

$ar = new ActiveRecords(new SQLiteHandler('path/to/dbfile.sqlite'));
```
! Make sure path to db file is writable.

### Database preparation
Unkike MongoDB, which is schemaless and doesn't require any preparation, for SQL engines you first need to create database and tables in it. MongoDB has very convinient fiture to store sequential arrays (sets) as a field in a doc. 
```
{ "_id" : ObjectId("58c12662a068f30f1f8b4567"), "name" : "John", "age" : NumberLong(28), "tags" : [  "four",  "six" ], "imgs" : [  "john.jpg",  "all.png" ] }
```
To emulate such feature with SQL DB, this package uses a separate database table (subtables) for every such field. So if the call to DB tries to search through such sets than related subtables are left joined. And when set modification is requested, separate queries to the subtables are being run to perform this. From performance perpective it's definately not the best solution for havy usage but acceptable for relatively small loads. 

In MySQL 5.7 there is JSON type fields support so the same behavior can be achived with JSON approach in a future.

If you plan to store some information as an arrays (sets), the following steps are recommended:
  1. The main database table must use InnoDB engine since it supports foreign key contraints.
  2. For each field that will store a set, create a table with the name of the field and foreign key to the main table (see examples below). 
  3. Such table __must__ have column named 'relid' of integer type and a column named with the same word as the table itself that will store set's data.
  
Below are the examples of creating the main tables "table1" and the subtable "tags" that will store arrays related to the main table's records:
```
CREATE TABLE `table1` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` text,
  `age` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE `tags` (
  `relid` int(11) NOT NULL,
  `tags` text,
  KEY `relid` (`relid`),
  FOREIGN KEY (`relid`) REFERENCES `table1` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
```
To add the constraint to the existing table use the following query:
```
ALTER TABLE tags
  ADD CONSTRAINT FOREIGN KEY (`relid`) REFERENCES table1(`id`);
```
If setting ON DELETE CASCADE constraint is not possible for any reasons than set the attribute:
```
$ar->setHandlerAttrt('cascade', false);
```
which will activate additional routine to run each time on subtables when the records from the main table is removed. It all needed to avoid orphan records in the subtables.

### Usage
------
The table (or collection for MongoDB) can be set once for all later operations or can be specified each time for single operation.
```
$ar->setTable('table1');

$ar->add($data, 'table2');
```
If you set table name via setTable it stays default table for all cases when the table is not specified explicitly in operations.

#### Add (insert) data
Usage: $ar->add($data, [$tablename]);
```
$data = array(
  'name' => 'Testuser2',
  'age'  => '36'
);

$ar->add($data);
```
Add operation returns the id of the inserted record (if such field is used in SQL database, always returns in case of MongoDB).

If add operation returns NULL that likely mean that validation failed.

#### Validation
* $data used in add/update operations must be associative array. Validation will fail otherwise.
* You may specify mandatory fields that must be in $data in order to add operation to proceed:
```
$ar->setMandatoryFields(array('name','age'));
```
* You may prevent duplicate records creation specifying fields to check for already existing data:
```
$ar->setUniqueRecordFields(array('name','age'));
```
In the example above the search will run on database to check fields `name` and `age` if they already contain the same data is being tried to insert.

If validation failed you can get the error information:
```
if ( $inserted = $ar->add($data) )
{
  echo "inserted id: $inserted <br>";
}
else
{
  if ( $errors = $ar->getError() )
    foreach($errors as $k=>$v)
      echo "$k: $v<br>";
}
```
#### Update data
Usage: $ar->update($criteria, $data, [$tblname]);
```
$ar->update(array('id'=>'12'), array('age'=>'36','name'=>'Testuser2'));
```
The fields `age` and `name` will be updated for the record with `id` 12.

The $data must be an array and can include special keys '$set', '$addToSet', '$push' and '$pull'. The meaning is equal to what they mean for MongoDB updates. If none of those keys are specified, then '$set' is assumed. Below is the example of mixed $data update:
```
$ar->update(array('tags'=>'three'),array('age'=>33, '$addToSet'=>array('tags'=>'two','imgs'=>'jack1.jpg')));
```
So the values 'two' and 'jack1.jpg' will be appended to the corresponding arrays 'tags' and 'imgs' if the don't exist there yet, but 'age' will be set to 33 since in this case '$set' is assumed.

#### Delete records
```
$ar->delete(array('age'=>'36'));
```
Delete all records where age field has value 36.

#### Get data
There are 2 methods of usage:
* get($criteria, [$projection], [$sort], [$limit], [$tblname]) - returns multi-dimentional associative array with all found records data
* getOne($criteria, [$projection], [$tblname]) - return single record as associative array

All arguments are optional. Below are the description of them:
..$criteria - associative array with db field names as keys. Values may be as exact value or regexp. For MongoDB full regexp syntax is supported and is simply transfered to mongodb commands. For MySQL/SQLite the syntax is converted to SQL format, so only limited syntax is accepted:
```
/.../ - value of regexp must start and end with slash
/.../i - means to run case-insensitive match
.+ - the combination mean any number of any symbols and converted to % sign for SQL. The combination may be use multiple times in expression.
```
* $data - associative array with db fields names as keys, values are what to the field equals
* $sort - associative array with db fields names as keys, values can be (independently of the actual database type):
  * 'ASC' or 1 - for ascending sort
  * 'DESC' or -1 - for descending sort
* $limit is an integer
* $tblname is table name if the operation is run not on the early specied default table.
