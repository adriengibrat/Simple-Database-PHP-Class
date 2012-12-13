<?php
class Db {
	/* Configuration */
	/**
	 * Configuration storage
	 * @var array
	 */
	protected static $config = array(
		'driver' => 'mysql',
		'host'   => 'localhost',
		'fetch'  => 'stdClass'
	);
	/**
	 * Get and set Db configurations
	 * @uses   static::config
	 * @param  string|array $key   [Optional] Name of configuration or hash array of configurations names / values
	 * @param  mixed        $value [Optional] Value of the configuration
	 * @return mixed        Configuration value(s), get all configurations when called without arguments
	 */
	static public function config ( $key = null, $value = null ) {
		if ( ! isset( $key ) )
			return static::$config;
		if ( isset( $value ) )
			return static::$config[ (string) $key ] = $value;
		if ( is_array( $key ) )
			return array_map( 'static::config', array_keys( (array) $key ), array_values( (array) $key ) );
		if ( isset( static::$config[ $key ] ) )
			return static::$config[ $key ];
	}
	/* Constructor */
	/**
	 * Database connection
	 * @var PDO
	 */
	protected $db;
	/**
	 * Latest query statement
	 * @var PDOStatement
	 */
	protected $statement;
	/**
	 * Constructor
	 * @uses  PDO
	 * @throw PDOException
	 * @param string $dsn  Dsn (Data Source Name) string
	 * @param string $user User name
	 * @param string $pass [Optional] User password
	 * @see   http://php.net/manual/fr/pdo.construct.php
	 */
	public function __construct ( $dsn, $user, $password = null ) {
		$this->db = new pdo( $dsn, $user, $password, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		) );
		parse_str( 'driver=' . str_replace( array( ':', ';' ), '&', $dsn ), $this->db->info );
	}
	/* Static instance */
	/**
	 * Singleton instance
	 * @var Db
	 */
	protected static $instance;
	/**
	 * Get singleton instance
	 * @uses   static::config
	 * @uses   static::__construct
	 * @return Db Singleton instance
	 */
	static public function instance () {
		return static::$instance ?: 
			static::$instance = new static( 
				static::config( 'dsn' ) ?:
					static::config( 'dsn', static::config( 'driver' ) . ':host=' . static::config( 'host' ) . ';dbname=' . static::config( 'database' ) ), static::config( 'user' ), static::config( 'password' )
			);
	}
	/* Query methods */
	/**
	 * Execute raw SQL query
	 * @uses   PDO::query
	 * @param  string $sql Plain SQL query
	 * @return Db     Self instance
	 * @todo   ? detect USE query to update dbname ?
	 */
	public function raw ( $sql ) {
		$this->statement = $this->db->query( $sql );
		return $this;
	}
	/**
	 * Execute SQL query with paramaters
	 * @uses   self::config
	 * @uses   PDO::prepare
	 * @uses   self::_uncomment
	 * @uses   PDOStatement::execute
	 * @param  string $sql    SQL query with placeholder
	 * @param  array  $params SQL parameters to escape (quote)
	 * @return Db     Self instance
	 * @todo   ? detect USE query to update dbname ?
	 */
	public function query ( $sql, array $params ) {
		$name = 'STATEMENT:' . $sql;
		if ( $config = self::config( $name ) )
			$this->statement = $config;
		else
			$this->statement = self::config( $name, $this->db->prepare( self::_uncomment( $sql ) ) );
		$this->statement->execute( $params );
		return $this;
	}
	/**
	 * Execute SQL select query
	 * @uses   PDO::query
	 * @param  string       $table  
	 * @param  string|array $fields [Optional] 
	 * @param  string|array $where  [Optional] 
	 * @param  string       $order  [Optional] 
	 * @param  string|int   $limit  [Optional] 
	 * @return Db     Self instance
	 * @todo   Need complete review
	 */
	public function select ( $table, $fields = '*', $where = null, $order = null, $limit = null ) {
		$sql = 'SELECT ' . self::_fields( $fields ) . ' FROM ' . $this->_table( $table );
		if ( $where && $where = $this->_conditions( $where ) )
			$sql .= ' WHERE ' . $where->sql;
		if ( $order )
			$sql .= ' ORDER BY ' . $order;
		if ( $limit )
			$sql .= ' LIMIT ' . $limit;
		return $where ?
			$this->query( $sql, $where->params ) :
			$this->raw( $sql );
	}
	/* Query formating helpers */
	/**
	 * Check if data is a plain key (without SQL logic)
	 * @param  mixed $data Data to check
	 * @return bool
	 */
	static protected function _is_plain ( $data ) {
		if ( ! is_scalar( $data ) )
			return false;
		return is_string( $data ) ? ! preg_match( '/\W/i', $data ) : true;
	}
	/**
	 * Check if array is a simple indexed list
	 * @param  array $array Array to check
	 * @return bool
	 */
	static protected function _is_list ( array $array ) {
		foreach ( array_keys( $array ) as $key )
			if ( ! is_int( $key ) )
				return false;
		return true;
	}
	/**
	 * Remove all (inline & multiline bloc) comments from SQL query
	 * @param  string $sql SQL query string
	 * @return string SQL query string without comments
	 */
	static protected function _uncomment ( $sql ) {
		/* '@
		(([\'"`]).*?[^\\\]\2) # $1 : Skip single & double quoted expressions
		|(                    # $3 : Match comments
		  (?:\#|--).*?$       # - Single line comments
		  |                   # - Multi line (nested) comments
		  /\*                 #   . comment open marker
		    (?: [^/*]         #   . non comment-marker characters
		      |/(?!\*)        #   . ! not a comment open
		      |\*(?!/)        #   . ! not a comment close
		      |(?R)           #   . recursive case
		    )*                #   . repeat eventually
		  \*\/                #   . comment close marker
		)\s*                  # Trim after comments
		|(?<=;)\s+            # Trim after semi-colon
		@msx' */
		return trim( preg_replace( '@(([\'"`]).*?[^\\\]\2)|((?:\#|--).*?$|/\*(?:[^/*]|/(?!\*)|\*(?!/)|(?R))*\*\/)\s*|(?<=;)\s+@ms', '$1', $sql ) );
	}
	/**
	 * Format query parameters
	 * @uses   self::_escape
	 * @param  string|array $data     Data to format
	 * @param  string       $operator [Optional] 
	 * @param  string       $glue     [Optional] 
	 * @return string       SQL params query chunk
	 * @todo   Handle integer keys like in self::_conditions
	 */
	static protected function _params ( $data, $operator = '=', $glue = ', ' ) {
		$params = is_string( $data) ? array( $data ) : array_keys( (array) $data );
		foreach ( $params as &$param )
			$param = implode( ' ', array( self::_escape( $param ), $operator, ':' . $param ) );
		return implode( $glue, $params );
	}
	/**
	 * Format query fields
	 * @uses   self::_is_plain
	 * @param  string  $field Field String
	 * @return string  SQL field query chunk
	 * @todo   ? Refactor with _table ?
	 */
	static protected function _escape ( $field ) {
		return self::_is_plain( $field ) ?
			'`' . $field  . '`' :
			$field;
	}
	static protected function _fields ( $fields ) {
		if ( empty( $fields ) )
			return '*';
		if ( is_string( $fields ) )
			return $fields;
		$_fields = array();
		foreach ( $fields as $alias => $field )
			$_fields[] = self::_escape( $field ) . ( is_string( $alias ) ? ' AS `' . $alias . '`' : '' );
		return implode( ', ', $_fields );
	}
	static protected function _table ( $table, $database = null ) {
		if ( is_null( $database ) )
			return ( ( $database = self::_table( $table, true ) ) ?
				self::_escape( $database ) . '.' :
				''
			) . self::_escape( self::_table( $table, false ) );
		if ( preg_match( $database ?
			'@(?:(?:^|\s)(`?)(\w+)(?<=[^\\\])\1)(?=\.)@' : // get first
			'@(?:(?:^|\.)(`?)(\w+)(?<=[^\\\])\1)+@'        // get last
		,  $table, $match ) )
		return $match[ 2 ];
	}
//@todo
	static protected function _conditions ( array $conditions ) {
		$sql    = array();
		$params = array();
		$i      = 0;
		foreach ( $conditions as $condition => $param ) {
			if ( is_string( $condition ) ) {
				for ( $keys = array(), $n = 0; false !== ( $n = strpos( $condition, '?', $n ) ); $n ++ )
					$condition = substr_replace( $condition, ':' . ( $keys[] = '_' . ++ $i ), $n, 1 );
				if ( ! empty( $keys ) )
					$param = array_combine( $keys, (array) $param );
				$params += (array) $param;
				if ( self::_is_plain( $condition ) ) // change condiftion by reference ?
					$condition = self::_params( $condition );
			} else
				$condition = $param;
			$sql[]= $condition;
		}
		return (object) array( 
			'sql'    => '( ' . implode( ' ) AND ( ', $sql ) . ' )',
			'params' => $params
		);
	}
	/* Data column helpers */
	static protected function _column ( array $data, $field ) {
		$column = array();
		foreach ( $data as $row )
			if ( is_object( $row ) && isset( $row->{$field} ) )
				$column[] = $row->{$field};
			else if ( is_array( $row ) && isset( $row[ $field ] ) )
				$column[] = $row[ $field ];
			else 
				$column[] = null;
		return $column;
	}
	static protected function _index ( array $data, $field ) {
		return array_combine(
			self::_column( $data, $field ),
			$data
		);
	}
	/* CRUD methods */
	public function create ( $table, array $data ) {
		$keys = array_keys( $data );
		$sql  = 'INSERT INTO ' . $this->_table( $table ) . ' (' . implode( ', ', $keys ) . ') VALUES (:' . implode( ', :', $keys ) . ')';
		return $this->query( $sql, $data );  
	}
	//public function read ( $table, $where ) 
	public function read ( $table, $id, $key = null ) {
		$key = $key ?: current( $this->key( $table ) );
		$sql = 'SELECT * FROM ' . $this->_table( $table ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array( ':' . $key => $id ) );
	}
	//public function update ( $table, $data, $where )
	public function update ( $table, $data, $value = null, $id = null, $key = null ) {
		if ( is_array( $data ) ) {
			$key  = $id;
			$id   = $value;
		} else
			$data = array( $data => $value );
		$key = $key ?: current( $this->key( $table ) );
		if ( is_null( $id ) && isset( $data[ $key ] ) && ! ( $id = $data[ $key ] ) )
			throw new Exception( 'No `' . $key . '` key value to update `' . $table . '` table, please specify a key value' );
		$sql = 'UPDATE ' . $this->_table( $table ) . ' SET ' . self::_params( $data ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array_merge( $data, array( ':' . $key => $id ) ) );
	}
	//public function delete ( $table, $where )
	public function delete ( $table, $id, $key = null ) {
		$key = $key ?: current( $this->key( $table ) );
		$sql = 'DELETE FROM ' . $this->_table( $table ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array( ':' . $key => $id ) );
	}
	/* Fetch methods */
	public function fetch ( $class = null ) {
		if ( ! $this->statement )
			throw new Exception( 'Can\'t fetch result if no query!' );
		return $class === false ?
			$this->statement->fetch( PDO::FETCH_ASSOC ) :
			$this->statement->fetchObject( $class ?: self::config( 'fetch' ) );
	}
	public function all ( $class = null ) {
		if ( ! $this->statement )
			throw new Exception( 'Can\'t fetch results if no query!' );
		return $class === false ?
			$this->statement->fetchAll( PDO::FETCH_ASSOC ) :
			$this->statement->fetchAll( PDO::FETCH_CLASS, $class ?: self::config( 'fetch' ) );
	}
	public function column ( $field, $index = null ) {
		$data   = $this->all( false );
		$values = self::_column( $data, $field );
		return is_string( $index ) ?
			array_combine( self::_column( $data, $index ), $values ) :
			$values;
	}
	/* Table infos */
	public function key ( $table ) {
		$name = 'KEY:' . $table;
		if ( $config = self::config( $name ) )
			return $config;
		$sql = 'SELECT 
				`COLUMN_NAME`
			FROM `INFORMATION_SCHEMA`.`COLUMNS`
			WHERE 
				`TABLE_SCHEMA` = ' . $this->quote( $this->database( $table ) ) . ' AND 
				`TABLE_NAME` = ' . $this->quote( self::_table( $table, false ) ) . ' AND 
				`COLUMN_KEY` = "PRI"
			ORDER BY `ORDINAL_POSITION` ASC';
		$key = $this->db->query( $sql );
		if ( ! $key->rowCount() && $this->fields( $table ) )
			throw new Exception( 'No primary key on ' . self::_table( $table ) . ' table, please set a primary key' );
		return self::config( $name, $key->fetchAll( PDO::FETCH_COLUMN, 0 ) );
	}
	public function fields ( $table ) {
		$name = 'FIELDS:' . $table;
		if ( $config = self::config( $name ) )
			return $config;
		$sql = 'SELECT 
				`COLUMN_NAME`                                               AS `name`, 
				`COLUMN_DEFAULT`                                            AS `default`, 
				NULLIF( `IS_NULLABLE`, "NO" )                               AS `null`, 
				`DATA_TYPE`                                                 AS `type`, 
				COALESCE( `CHARACTER_MAXIMUM_LENGTH`, `NUMERIC_PRECISION` ) AS `length`, 
				`CHARACTER_SET_NAME`                                        AS `encoding`, 
				`COLUMN_KEY`                                                AS `key`, 
				`EXTRA`                                                     AS `auto`, 
				`COLUMN_COMMENT`                                            AS `comment`
			FROM `INFORMATION_SCHEMA`.`COLUMNS`
			WHERE 
				`TABLE_SCHEMA` = ' . $this->quote( $this->database( $table ) ) . ' AND 
				`TABLE_NAME` = ' . $this->quote( self::_table( $table, false ) ) . '
			ORDER BY `ORDINAL_POSITION` ASC';
		$fields = $this->db->query( $sql );
		if ( ! $fields->rowCount() )
			throw new Exception( 'No ' . self::_table( $table ) . ' table, please specify a valid table' );
		return self::config( $name, self::_index( $fields->fetchAll( PDO::FETCH_CLASS ), 'name' ) );
	}
	/* Quote Helper */
	public function quote ( $value ) {
		return is_null( $value ) ?
			'NULL' : 
			$this->db->quote( $value );
	}
	public function database ( $table = null ) {
		return self::_table( $table, true ) ?: 
			$this->db->info[ 'dbname' ];
	}
	/* Statement infos */
	public function id () {
		// !! see http://php.net/manual/fr/pdo.lastinsertid.php
		return $this->db->lastInsertId();
	}
	public function count () {
		return $this->statement ? 
			$this->statement->rowCount() :
			null;
	}
	public function sql () {
		return $this->statement ? 
			$this->statement->queryString :
			null;
	}
}
/*
Trait UnitTest
{
    protected $values = array();
 
    public function set($key, $value)
    {
        $this->values[$key] = $value;
    }
 
    public function get($key)
    {
        return $this->values[$key];
    }
}
*/
Class Mysql extends Db {
	public static function test_uncomment ( $sql ) {
		return self::_uncomment( $sql );
	}
	public static function test_table ( $table, $database = null ) {
		return self::_table( $table, $database );
	}
}
//*
var_dump(
	'<pre>'
	, Mysql::test_table( '`t1`', true )
	, Mysql::test_table( 't2', true )
	, Mysql::test_table( '`t1`', false )
	, Mysql::test_table( 't2', false )
	, Mysql::test_table( '`t1`' )
	, Mysql::test_table( 't2' )
	, '--'
	, Mysql::test_table( '`db1`.`t1`', true )
	, Mysql::test_table( '`db2`.t2', true )
	, Mysql::test_table( 'db3.`t3`', true )
	, Mysql::test_table( 'db4.t4', true )
	, Mysql::test_table( '`db1`.`t1`', false )
	, Mysql::test_table( '`db2`.t2', false )
	, Mysql::test_table( 'db3.`t3`', false )
	, Mysql::test_table( 'db4.t4', false )
	, Mysql::test_table( '`db1`.`t1`' )
	, Mysql::test_table( '`db2`.t2' )
	, Mysql::test_table( 'db3.`t3`' )
	, Mysql::test_table( 'db4.t4' )
	, '--'
	, Mysql::test_table( 'db1.t1, db.t2, t3', true )
	, Mysql::test_table( '`db1`.t1, db.t2, t3', true )
	, Mysql::test_table( '`db1`.`t1`, db.t2, t3', true )
	, Mysql::test_table( 'db1.t1, db.t2, t3', false )
	, Mysql::test_table( '`db1`.t1, db.t2, t3', false )
	, Mysql::test_table( '`db1`.`t1`, db.t2, t3', false )
	, Mysql::test_table( 'db1.t1, db.t2, t3' )
	, Mysql::test_table( '`db1`.t1, db.t2, t3' )
	, Mysql::test_table( '`db1`.`t1`, db.t2, t3' )
	, '--'
	, Mysql::test_table( 'db1.t1.f1', true )
	, Mysql::test_table( '`db1`.t1.f1', true )
	, Mysql::test_table( '`db1`.`t1`.f1', true )
	, Mysql::test_table( 'db1.t1.f1', false )
	, Mysql::test_table( '`db1`.t1.f1', false )
	, Mysql::test_table( '`db1`.`t1`.f1', false )
	, Mysql::test_table( 'db1.t1.f1' )
	, Mysql::test_table( '`db1`.t1.f1' )
	, Mysql::test_table( '`db1`.`t1`.f1' )
);
exit;
//*/
Mysql::config( array(
	'database' => 'corbeille',
	'user'     => 'root',
	'password' => 'baobab5'
) );
$where = array( 
	'categorie_id=0',                                                                       // do nothing
	//'content'                                   => 'pouet ',                              // _params!  -> content = "pouet "
	//'content'                                   => 'p%',                                  // _params? -> content LIKE "%p"
	//'id'                                        => array( 1, 2 ),                         // _params? -> id IN ( 1, 2 )
	'suivi_equalis LIKE ?'                      => 'ou%',                                   // don't use ? placeholder -> url LIKE "%.php"
	'etat <> :etat OR pf_zone_id = :pf_zone_id' => array( 'etat' => 1, 'pf_zone_id' => 9 ), // redy for query + execute
	'etat <> ? OR pf_zone_id = ?'               => array( 1, 9 )                            // don't use ? placeholder -> note <= 2 OR id = 1
);
var_dump(
	'<pre>'
	//, Mysql::instance()->read( 'test', 9 )->column( 'ville', 'id' )
	, Mysql::instance()->select( 'centres', array( 'count' => 'count(id)', '`organisation` AS u', 'centres.ville', 'ville' ), $where )->fetch()
	, Mysql::instance()->sql()
	, Mysql::instance()->key( 'centres' )
	, array_keys( Mysql::instance()->fields( 'centres' ) )
	//, Mysql::config()
);
