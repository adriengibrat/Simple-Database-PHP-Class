## How to use it

```php
<?php
require 'Db.php';
$db = new Db( 'mysql:host=hostName;dbname=databaseName', 'databaseUser'[, 'databaseUserPassword'] );
```
Or use config file
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
