## How to use it

Get new instance like this
```php
<?php
require 'Db.php';
$db = new Db( 'mysql', 'hostName', 'databaseName', 'databaseUser'[, 'databaseUserPassword'] );
```
Or set config
```php
<?php
require 'Db.php';
Db::config( 'driver',   'mysql' );
Db::config( 'host',     'hostName' );
Db::config( 'database', 'databaseName' );
Db::config( 'user',     'databaseUser' );
Db::config( 'password', 'databaseUserPassword' );
```
And later get instance easily
```php
<?php
$db = Db::instance();
```
### Basic methods
```php
<?php
$db->raw( 'SELECT * FROM Table' ); // Execute raw SQL query (don't forget to secure your SQL)

$db->query( 'SELECT * FROM Table WHERE id = :id', array( 'id' => 1 ) ); // Prepare and execute SQL

$db->select( 'Table' ); // Select everything (*) from Table
$db->select( 'Table', 'id' ); // Select id column
$db->select( 'Table', array( 'id', 'field' ) ); // Select id & field columns
$db->select( 'Table', '*', array( // Select everything where field1 = $value1 AND field2 = $value2
    'field1' => $value1,
    'field2' => $value2
) );
$db->select( 'Table', '*', array( // Complex select where N.B: you can't mix ? and named placeholder
    'field1 >= ? AND field1 <= ? '                     => array(42, 100),
    'field2 LIKE ?'                                    => '%test%',
    'field3 = :value OR CHAR_LENGTH(field3) < :length' => array(
        'value'  => $value,
        'length' => 10
    )
) );

```
### CRUD methods
```php
<?php
$db->create( 'Table', array( 'field' => 'data' ) ); // Insert into Table

$db->read( 'Table', 1 ); // Select from Table where primary key = 1
$db->read( 'Table', 'data', 'field' ); // Select from Table where field = 'data'

$db->update( 'Table', 'field', 'test', 1 ) // Update field to 'test' in Table where primary key = 1
$data = array( 'field' => 'test' ); // Passing data as array shift next arguments
$db->update( 'Table', $data, 1 ) // Equivalent to previous line
$db->update( 'Table', $data, 'test', 'data' ) // Update field(s) containing 'data' to  'test'

$db->delete( 'Table', 1 ) // Delete Table row where primary key = 1
$db->delete( 'Table', 'test', 'field' ) // Delete Table row where field = 'test'
```
### Fetch methods
```php
<?php
$db->read( 'Table', 1 )->fetch(); // fetch returns objects (stdClass by default)
$db->read( 'Table', 1 )->fetch( 'Class' ); // But you can specify a class
$select = $db->select( 'Table' );
while ( $row = $select->fetch() ) // It's a row fetching method
  echo $row->field;

$select = $db->select( 'Table' );
while ( $row = $select->fetch( false ) ) // returns associative array
  echo $row[ 'field' ];

$db->select( 'Table' )->all(); // all returns all rows (as ArrayObject)
$db->select( 'Table' )->all( 'Class' ); // You also can specify row class

$db->select( 'Table', 'id' )->column( 'id' ); // column returns only values (as an array)
```
### Various methods
```php
<?php
$db->key( 'Table' ); // Get Table primary key (id)
$db->quote( $value ); // Get quote protected value
$db->create( 'Table', array( 'field' => 'data' ) ); // Add a row, then
$db->id(); // Get last inserted id
```
### Config tricks
Db::config method is a getter and a setter
```php
<?php
Db::config( 'host', 'hostName' ); // Two argument -> setter
Db::config( array(    // One array argument -> setter
  'user'       => 'databaseUser'
  , 'password' => 'databaseUserPassword'
) );

Db::config( 'host' ); // One argument -> getter
```
You can store what you want in config (only driver, host, database, user, password & fetch are reserved)
```php
<?php
Db::config( 'salt', 'p*d5h|zpor7spm#i' ); // set a salt to reuse it later
$user = array(
  'login'      => $login
  , 'password' => md5( Db::config( 'salt' ) . $password ) //  Hash password
);
$userId = $db->create( 'User', $user )->id(); // Save new user
```
By default fetch method return stdClass, but you can customize globaly
```php
<?php
Db::config( 'fetch', 'Class' ); // Set class to use for object
$db->read( 'Table', 1 )->fetch(); // Methods fetch and all now return Class object(s)
$db->select( 'Table' )->all();
$db->read( 'Table', 1 )->fetch( 'OtherClass' ); // You still can override it
```
Read, update and delete methods automatically guess which primary key to use,
but you can set/override it manually
```php
<?php
Db::config( 'databaseName.Table:PK', 'field' ); // Manually set 'primary' key of table to field
$db->read( 'Table', 'test' ); // Now, this select from Table where field = 'test'
```
