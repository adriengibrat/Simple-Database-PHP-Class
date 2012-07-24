## How to use it

Get new instance like PDO
```php
<?php
require 'Db.php';
$db = new Db( 'mysql:host=hostName;dbname=databaseName', 'databaseUser'[, 'databaseUserPassword'] );
```
Or set config
```php
<?php
require 'Db.php';
Db::config( 'driver',   'mysql' );
Db::config( 'host',     'hostName' );
Db::config( 'database', 'databaseName' );
// or use PDO dsn format directly Db::config( 'dsn', 'mysql:host=hostName;dbname=databaseName' );
Db::config( 'user',     'databaseUser' );
Db::config( 'pass',     'databaseUserPassword' );
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
```
### CRUD methods
```php
<?php
$db->create( 'Table', array( 'field' => 'data' ) ); // Insert into Table

$db->read( 'Table', 1 ); // Select from Table where primary key = 1
$db->read( 'Table', 'data', 'field' ); // Select from Table where field = 'data'

$db->update( 'Table', 'field', 'test', 1 ) // Update field to 'test' in Table where primary key = 1
$db->update( 'Table', array( 'field' => 'test' ), 1 ) // Equivalent to previous line
$db->update( 'Table', array( 'field' => 'data' ), 'test', 'field' ) // Update field(s) containing 'test' to 'data'

$db->delete( 'Table', 1 ) // Delete Table row where primary key = 1
$db->delete( 'Table', 'test', 'field' ) // Delete Table row where field = 'test'
```
### Fetch methods
```php
<?php
$db->read( 'Table', 1 )->obj();
$db->read( 'Table', 1 )->obj( 'Class' );

$select = $db->select( 'Table', array( 'id', 'field' ) );
while ( $row = $select->assoc() )
  echo $row[ 'field' ];

$db->read( 'Table', 'data', 'field' )->all();
$db->select( 'Table' )->all( 'Class' );

$db->select( 'Table', 'id' )->column( 'id' );
```
### Info methods
```php
<?php
$db->key( 'Table' ); // Get Table primary key (id)

$db->create( 'Table', array( 'field' => 'data' ) )->id(); // Get last inserted id
```
### Config tricks
Db::config method is a getter and a setter.
```php
<?php
Db::config( 'host', 'hostName' ); // Two argument -> setter
Db::config( 'host' ); // One argument -> getter
Db::config( array(    // Tricky, one argument -> setter ;)
  'user'   => 'databaseUser'
  , 'pass' => 'databaseUserPassword'
) );
```
Read, update and delete methods automatically guess whitch primary key to use.
You can set/customize it manually.
```php
<?php
Db::config( 'Table:PK', 'field' );
```
By default obj method return stdClass, but you can customize this once and for all.
```php
<?php
Db::config( 'obj', 'Class' );
```
