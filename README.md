# ActiveRecords for Databases
=====

The package gives a simple and unified interface to interact with various database engines. You can operate with the same set of method independently of which database is on backend. Currently supported:
* MongoDB
* MySQL
* SQLite

### Installation
------
The package is intended to be used via Composer. It currently is not on Packagist, so add this repository description to you composer.json:
```
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/efoft/active-records"
        },
    ]
```
and require it:
```
    "require": {
        "efoft/active-records" : "dev-master"
    }
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

### Usage
------
The table (or collection for MongoDB) can be set once for all later operations or can be specified each time for single operation.
```
$ar->setTable('table1');

$ar->add($data, 'table2');
```
If you set table name via setTable it stays default table for all cases when the table is not specified explicitly in operations.

#### Add (insert) data
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
```
$ar->update(array('id'=>'12'), array('age'=>'36','name'=>'Testuser2'));
```
The fields `age` and `name` will be updated for the record with `id` 12.

#### Delete records
```
$ar->delete(array('age'=>'36'));
```
Delete all records where age field has value 36.

#### Get data
There are 2 methods:
* get($criteria, $projection, $sort, $limit, $tblname) - returns multi-dimentional associative array with all found records data
* getOne($criteria, $projection, $tblname) - return single record as associative array

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
