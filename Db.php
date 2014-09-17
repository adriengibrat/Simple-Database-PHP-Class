<?php
/**
 * Db
 * PHP 5.3+
 */
class Db {
	/* Configuration */
	/**
	 * Configuration storage
	 * @var array
	 */
	protected static $config = array(
		'driver' => 'mysql'
		, 'host'   => 'localhost'
		, 'port'   => 3307
		, 'fetch'  => 'stdClass'
	);
	/**
	 * Get and set default Db configurations
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
	/* Static instances */
	/**
	 * Multiton instances
	 * @var array
	 */
	protected static $instance  = array();
	protected static $arguments = array( 'driver', 'host', 'database', 'user', 'password' );
	/**
	 * Get singleton instance
	 * @uses   static::config
	 * @uses   static::__construct
	 * @param string $driver   [Optional] Database driver
	 * @param string $host     [Optional] Database host
	 * @param string $database [Optional] Database name
	 * @param string $user     [Optional] User name
	 * @param string $pass     [Optional] User password
	 * @return Db Singleton instance
	 */
	static public function __callStatic ( $name, $config ) {
		if ( isset( static::$instance[ $name ] ) )
			return static::$instance[ $name ];
		$config = array_merge(
			static::config(),
			array_filter( array_combine( static::$arguments, $config + array_fill( 0, count( static::$arguments ), null ) ) )
		);
		return static::$instance[ $name ] = new static( $config[ 'driver' ], $config[ 'host' ], $config[ 'database' ], $config[ 'user' ], $config[ 'password' ] );
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
	protected $result;
	/**
	 * Database information
	 * @var stdClass
	 */
	protected $info;
	/**
	 * Statements cache
	 * @var array
	 */
	protected $statement = array();
	/**
	 * Tables shema information cache
	 * @var array
	 */
	protected $table     = array();
	/**
	 * Primary keys information cache
	 * @var array
	 */
	protected $key       = array();
	/**
	 * Constructor
	 * @uses  PDO
	 * @throw PDOException
	 * @param string $driver   Database driver
	 * @param string $host     Database host
	 * @param string $database Database name
	 * @param string $user     User name
	 * @param string $pass     [Optional] User password
	 * @see   http://php.net/manual/fr/pdo.construct.php
	 * @todo  Support port/socket within DSN?
	 */
	public function __construct ( $driver, $host, $database, $user, $password = null ) {
		set_exception_handler( array( __CLASS__, 'safe_exception' ) );
		$this->db = new pdo( $driver . ':host=' . $host . ';dbname=' . $database, $user, $password, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		) );
		restore_exception_handler();
		$this->db->exec('SET NAMES UTF8');
		$this->info = (object) array_combine( static::$arguments, func_get_args() );
		unset($this->info->password);
	}
	/**
	 * Avoid exposing exception informations
	 * @param Exception $exception [Optional] User password
	 */
	public static function safe_exception ( Exception $exception ) {
		die( 'Uncaught exception: ' . $exception->getMessage() );
	}
	/* SQL query */
	/**
	 * Get latest SQL query
	 * @return string Latest SQL query
	 */
	public function __toString () {
		return $this->result ? 
			$this->result->queryString :
			null;
	}
	/* Query methods */
	/**
	 * Execute raw SQL query
	 * @uses   PDO::query
	 * @throw  PDOException
	 * @param  string $sql Plain SQL query
	 * @return Db     Self instance
	 * @todo   ? detect USE query to update dbname ?
	 */
	public function raw ( $sql ) {
		$this->result = $this->db->query( $sql );
		return $this;
	}
	/**
	 * Execute SQL query with paramaters
	 * @uses   PDO::prepare
	 * @uses   self::_uncomment
	 * @uses   PDOStatement::execute
	 * @throw  PDOException
	 * @param  string $sql    SQL query with placeholder
	 * @param  array  $params SQL parameters to escape (quote)
	 * @return Db     Self instance
	 */
	public function query ( $sql, array $params ) {
		$this->result = isset( $this->statement[ $sql ] ) ?
			$this->statement[ $sql ] :
			$this->statement[ $sql ] = $this->db->prepare( self::_uncomment( $sql ) );
		$this->result->execute( $params );
		return $this;
	}
	/**
	 * Execute SQL select query
	 * @uses   PDO::query
	 * @throw  PDOException
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
			$sql .= ' ORDER BY ' . ( is_array( $order ) ? implode( ', ', $order ) : $order );
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
	 */
	static protected function _escape ( $field ) {
		return self::_is_plain( $field ) ?
			'`' . $field  . '`' :
			$field;
	}
	static protected function _extract ( $table, $type = 'table' ) {
		static $infos = array(
			'database' => '@(?:(`?)(?P<database>\w+)\g{-2})\.(`?)(?P<table>\w+)\g{-2}(?:\.(`?)(?P<field>\w+)\g{-2})?@'
			, 'table'  => '@(?:(`?)(?P<database>\w+)\g{-2}\.)?(?:(`?)(?P<table>\w+)\g{-2})(?:\.(`?)(?P<field>\w+)\g{-2})?@'
			, 'field'  => '@(?:(`?)(?P<database>\w+)\g{-2}\.)?(?:(`?)(?P<table>\w+)\g{-2}\.)?(`?)(?P<field>\w+)\g{-2}@'
		);
		if ( ! isset( $infos[ $type ] ) || ! preg_match( $infos[ $type ], $table, $match ) )
			return;
		$match = array_filter( array_intersect_key( $match, $infos ) );
		return $match[ $type ];
	}
	static protected function _alias ( array $alias ) {
		foreach ( $alias as $k => $v )
			$_alias[] = self::_escape( $v ) . ( is_string( $k ) ? ' AS '. self::_escape( $k ) : '' );
		return $_alias;
	}
	static protected function _fields ( $fields ) {
		if ( empty( $fields ) )
			return '*';
		if ( is_string( $fields ) )
			return $fields;
		return implode( ', ', self::_alias ( $fields ) );
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
				if ( self::_is_plain( $condition ) ) {
					$param = array( $condition => (string) $param );
					$condition = self::_params( $condition );
				}
				$params += (array) $param;
			} else
				$condition = $param;
			$sql[] = $condition;
		}
		return (object) array( 
			'sql'    => '( ' . implode( ' ) AND ( ', $sql ) . ' )',
			'params' => $params
		);
	}
	protected function _table ( $table, $escape = true ) {
		return $escape ?
			self::_escape( $this->_database( $table ) )  . '.' . self::_escape( self::_extract( $table, 'table' )  ) :
			$this->_database( $table )  . '.' . self::_extract( $table, 'table' );
	}
	protected function _database ( $table = null ) {
		return self::_extract( $table, 'database' ) ?: 
			$this->info->database;
	}
	/* Data column helpers */
	static protected function _column ( array $data, $field ) {
		$column = array();
		foreach ( $data as $key => $row )
			if ( is_object( $row ) && isset( $row->{$field} ) )
				$column[ $key ] = $row->{$field};
			else if ( is_array( $row ) && isset( $row[ $field ] ) )
				$column[ $key ] = $row[ $field ];
			else 
				$column[ $key ] = null;
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
		if ( ! $this->result )
			throw new Exception( 'Can\'t fetch result if no query!' );
		return $class === false ?
			$this->result->fetch( PDO::FETCH_ASSOC ) :
			$this->result->fetchObject( $class ?: self::config( 'fetch' ) );
	}
	public function all ( $class = null ) {
		if ( ! $this->result )
			throw new Exception( 'Can\'t fetch results if no query!' );
		return $class === false ?
			$this->result->fetchAll( PDO::FETCH_ASSOC ) :
			$this->result->fetchAll( PDO::FETCH_CLASS, $class ?: self::config( 'fetch' ) );
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
		$table = $this->_table( $table, false );
		if ( self::config( $table . ':PK' ) )
			return self::config( $table . ':PK' );
		else if ( isset( $this->key[ $table ] ) )
			return $this->key[ $table ];
		$keys = array_keys( self::_column( $this->fields( $table ), 'key' ), 'PRI' );
		if ( empty( $keys ) )
			throw new Exception( 'No primary key on ' . $this->_table( $table ) . ' table, please set a primary key' );
		return $this->key[ $table ] = $keys;
	}
	public function fields ( $table ) {
		$table = $this->_table( $table, false );
		if ( isset( $this->table[ $table ] ) )
			return $this->table[ $table ];
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
				`TABLE_SCHEMA` = ' . $this->quote( self::_database( $table ) ) . ' AND 
				`TABLE_NAME` = ' . $this->quote( self::_extract( $table )  ) . '
			ORDER BY `ORDINAL_POSITION` ASC';
		$fields = $this->db->query( $sql );
		if ( ! $fields->rowCount() )
			throw new Exception( 'No ' . $this->_table( $table ) . ' table, please specify a valid table' );
		return $this->table[ $table ] = self::_index( $fields->fetchAll( PDO::FETCH_CLASS ), 'name' );
	}
	/* Quote Helper */
	public function quote ( $value ) {
		return is_null( $value ) ?
			'NULL' : 
			$this->db->quote( $value );
	}
	public function database ( $table = null ) {
		return $this->_table( $table, true ) ?: 
			$this->info->database;
	}
	/* Statement infos */
	public function id () {
		// !! see http://php.net/manual/fr/pdo.lastinsertid.php
		return $this->db->lastInsertId();
	}
	public function count () {
		return $this->result ? 
			$this->result->rowCount() :
			null;
	}

}
