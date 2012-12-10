<?php
class Db {
	/* Configuration */
	protected static $config = array(
		'driver' => 'mysql',
		'host'   => 'localhost'
	);
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
	protected $db;
	protected $statement;
	public function __construct ( $dns, $user, $pass = null ) {
		$this->db = new pdo( $dns, $user, $pass, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		) );
	}
	/* Static instance */
	protected static $instance;
	static public function instance () {
		return static::$instance ?: 
			static::$instance = new static( 
				static::config( 'dsn' ) ?:
					static::config( 'dsn', static::config( 'driver' ) . ':host=' . static::config( 'host' ) . ';dbname=' . static::config( 'database' ) ), static::config( 'user' ), static::config( 'password' )
			);
	}
	/* Query methods */
	public function raw ( $query ) {
		$this->statement = $this->db->query( $query );
		return $this;
	}
	public function query ( $query, array $params ) {
		$name = 'STATEMENT:' . $query;
		if ( $config = self::config( $name ) )
			$this->statement = $config;
		else
			$this->statement = self::config( $name, $this->db->prepare( $query ) );
		$this->statement->execute( $params );
		return $this;
	}
//@todo
	public function select ( $table, $fields = '*', $where = null, $order = null, $limit = null ) {
		$sql = 'SELECT ' . self::_fields( $fields ) . ' FROM ' . $this->_escape( $table );
		if ( $where )
			$sql .= ' WHERE ' . $where;
		if ( $order )
			$sql .= ' ORDER BY ' . $order;
		if ( $limit )
			$sql .= ' LIMIT ' . $limit;
		return $this->raw( $sql );
	}
	/* Helpers methods */
	protected function _key ( $table, $key = null ) {
		return $key ?:
			current( $this->key( $table ) );
	}
	static protected function _fetch ( $class ) {
		return $class ?:
			self::config( 'fetch' ) ?:
				'stdClass';
	}
	static protected function _plain ( $string ) {

		return ! preg_match( '/\W/i', $string );
	}
	static protected function _param ( $param ) {
		return self::_plain( $param ) ?
			':' . $param :
			'?';
	}
	static protected function _params ( $data, $operator = '=', $glue = ', ' ) {
		$keys = is_string( $data) ? array( $data ) : array_keys( (array) $data );
		foreach ( $keys as &$key )
			$key = implode( ' ', array( $this->_escape( $key ), $operator, self::_param( $key ) ) );
		return implode( $glue, $keys );
	}
	static protected function _escape ( $field ) {
		return self::_plain( $field ) ?
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
	static protected function _conditions ( $conditions, $glue = ' AND ' ) {
		$sql = $params = array();
		$i   = 0;
		foreach ( (array) $conditions as $condition => $param ) {
			if ( is_string( $condition ) ) {
				for ( $keys = array(), $n = 0; $n = strpos( $condition, '?', $n ); $n ++ )
					$condition = substr_replace( $condition, ':' . ( $keys[] = '_' . ++ $i ), $n, 1 );
				$params += empty( $keys ) ?
					(array) $param :
					array_combine( $keys, (array) $param );
			} else
				$condition = $param;
			$sql[]= $condition;
		}
		return array( implode( $glue, $sql ) => $params );
	}
	/* CRUD methods */
	public function create ( $table, array $data ) {
		$keys = array_keys( $data );
		$sql  = 'INSERT INTO ' . $this->_escape( $table ) . ' (' . implode( ', ', $keys ) . ') VALUES (:' . implode( ', :', $keys ) . ')';
		return $this->query( $sql, $data );  
	}
	public function read ( $table, $id, $key = null ) {
		$key = $this->_key( $table, $key );
		$sql = 'SELECT * FROM ' . $this->_escape( $table ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array( ':' . $key => $id ) );
	}
	public function update ( $table, $data, $value = null, $id = null, $key = null ) {
		if ( is_array( $data ) ) {
			$key  = $id;
			$id   = $value;
		} else
			$data = array( $data => $value );
		$key = $this->_key( $table, $key );
		if ( is_null( $id ) && isset( $data[ $key ] ) && ! ( $id = $data[ $key ] ) )
			throw new Exception( 'No `' . $key . '` key value to update `' . $table . '` table, please specify a key value' );
		$sql = 'UPDATE ' . $this->_escape( $table ) . ' SET ' . self::_params( $data ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array_merge( $data, array( ':' . $key => $id ) ) );
	}
	public function delete ( $table, $id, $key = null ) {
		$key = $this->_key( $table, $key );
		$sql = 'DELETE FROM ' . $this->_escape( $table ) . ' WHERE ' . self::_params( $key );
		return $this->query( $sql, array( ':' . $key => $id ) );
	}
	/* Fetch methods */
	public function fetch ( $class = null ) {
		if ( ! $this->statement )
			throw new Exception( 'Can\'t fetch result if no query!' );
		return $class === false ?
			$this->statement->fetch( PDO::FETCH_ASSOC ) :
			$this->statement->fetchObject( self::_fetch( $class ) );
	}
	public function all ( $class = null ) {
		if ( ! $this->statement )
			throw new Exception( 'Can\'t fetch results if no query!' );
		return $class === false ?
			$this->statement->fetchAll( PDO::FETCH_ASSOC ) :
			$this->statement->fetchAll( PDO::FETCH_CLASS, self::_fetch( $class ) );
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
		$sql = 'SELECT `COLUMN_NAME`
			FROM `INFORMATION_SCHEMA`.`COLUMNS`
			WHERE `TABLE_NAME` = ' . $this->quote( $table ) . ' AND `COLUMN_KEY` = "PRI"
			ORDER BY `ORDINAL_POSITION` ASC';
		$key = $this->db->query( $sql );
		if ( ! $key->rowCount() )
			throw new Exception( 'No primary key on `' . $table . '` table, please set a primary key' );
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
			WHERE `TABLE_NAME` = ' . $this->quote( $table ) . '
			ORDER BY `ORDINAL_POSITION` ASC';
		$fields = $this->db->query( $sql );
		if ( ! $fields->rowCount() )
			throw new Exception( 'No `' . $table . '` table, please specify a valid table' );
		return self::config( $name, self::_index( $fields->fetchAll( PDO::FETCH_CLASS ), 'name' ) );
	}
	public function quote ( $value ) {
		return is_null( $value ) ?
			'NULL' : 
			$this->db->quote( $value );
	}
	/* Statement infos */
	public function id () {

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