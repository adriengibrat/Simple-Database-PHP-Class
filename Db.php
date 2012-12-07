<?php
class Db {
	/* CONFIG */
	protected static $config;
	static public function config ( $key = null, $value = null ) {
		if ( ! isset( $key ) )
			return self::$config;
		if ( isset( $value ) )
			return self::$config[ (string) $key ] = $value;
		if ( is_array( $key ) )
			return array_map( 'self::config', array_keys( (array) $key ), array_values( (array) $key ) );
		if ( isset( self::$config[ $key ] ) )
			return self::$config[ $key ];
	}
	/* CONSTRUCT */
	protected $db;
	private   $results;
	public function __construct ( $dns, $user, $pass = null ) {
		$this->db = new pdo( $dns, $user, $pass, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		) );
	}
	/* STATIC */
	private static $instance;
	static public function instance () {
		if ( self::$instance )
			return self::$instance;
		return self::$instance = new static( db::config( 'dsn' ) ?: db::config( 'dsn', db::config( 'driver' ) . ':host=' . db::config( 'host' ) . ';dbname=' . db::config( 'database' ) ), db::config( 'user' ), db::config( 'pass' ) );
	}
	/* QUERY */
	public function raw ( $query ) {
		if( ! $this->results = $this->db->query( $query ) )
			throw new Exception( 'SQL error : ' . implode( ' - ', $this->db->errorInfo() ) );
		return $this;
	}
	public function query ( $query, $params ) {
		if( ! $this->results = $this->db->prepare( $query ) )
			throw new Exception( 'SQL error : ' . implode( ' - ', $this->db->errorInfo() ) );
		if ( ! $this->results->execute( $params ) )
			throw new Exception( 'SQL Statement error : ' . implode( ' - ', $this->results->errorInfo() ) );
		return $this;
	}
	public function select ( $table, $fields = '*', $where = null, $order = null, $limit = null ) {
		$sql = 'SELECT ' . self::_fields( $fields ) . ' FROM `' . $table . '`';
		if ( $where )
			$sql .= ' WHERE ' . $where;
		if ( $order )
			$sql .= ' ORDER BY ' . $order;
		if ( $limit )
			$sql .= ' LIMIT ' . $limit;
		return $this->raw( $sql );
	}
	/* HELPERS */
	protected function _key ( $table, $key = null ) {
		return $key ?:
			reset( $this->key( $table ) );
	}
	static protected function _obj ( $class ) {
		return $class ?:
			self::config( 'obj' ) ?:
				'stdClass';
	}
	static protected function _param ( $data, $glue = ', ' ) {
		$keys = array_keys( (array) $data );
		foreach ( $keys as &$key )
			$key = '`' . $key . '` = :' . $key
		return implode( $glue, $keys );
	}
	static protected function _fields ( $fields ) {
		if ( empty( $fields ) )
			return '*';
		if ( is_string( $fields ) )
			return $fields;
		$fields = (array) $fields; // Copy if referenced object
		foreach ( (array) $fields as $alias => &$field )
			$field = is_string( $alias ) ?
				'`' . $field  . '` AS `' . $alias . '`' :
				'`' . $field  . '`';
		return implode( ', ', $fields );
	}
	static protected function _column ( $data, $field ) {
		$data = (array) $data; // Copy if referenced object
		foreach ( $data as &$row )
			if ( isset( $row->{$field} ) )
				$row = $row->{$field};
		return $data;


		static $column;
		if ( ! $column )
			$column = function ( &$r, $i, $f ) { if ( isset( $r->{$f} ) ) $r = $r->{$f}; };
		$data = (array) $data; // Walk on a copy
		if ( array_walk( $data, $column, preg_replace( '/[^\w-]/', '', $field ) ) )
			return new ArrayObject( $data );
	}
	/* CRUD */
	public function create ( $table, $data ) {
		$keys = array_keys( $data );
		$sql  = 'INSERT INTO `' . $table . '` (' . implode( ', ', $keys ) . ') VALUES (:' . implode( ', :', $keys ) . ')';
		return $this->query( $sql, $data );  
	}
	public function read ( $table, $id, $key = null ) {
		$key = $this->_key( $table, $key );
		$sql = 'SELECT * FROM `' . $table . '` WHERE `' . $key . '` = :' . $key;
		return $this->query( $sql, array( ':' . $key => $id ) );
	}
	public function update ( $table, $field, $value = null, $id = null, $key = null ) {
		if ( is_array( $field ) || $field instanceof Traversable ) {
			$key  = $id;
			$id   = $value;
			$data = $field;
		} else
			$data = array( $field  => $value );
		$key = $this->_key( $table, $key );
		if ( is_null( $id ) && isset( $data[ $key ] ) && ! ( $id = $data[ $key ] ) )
			throw new Exception( 'No `' . $key . '` key value to update `' . $table . '` table, please specify a key value' );
		$sql = 'UPDATE `' . $table . '` SET ' . self::_param( $data ) . ' WHERE `' . $key . '` = :' . $key;
		return $this->query( $sql, array_merge( $data, array( ':' . $key => $id ) ) );
	}
	public function delete ( $table, $id, $key = null ) {
		$key = $this->_key( $table, $key );
		$sql = 'DELETE FROM `' . $table . '` WHERE `' . $key . '` = :' . $key;
		return $this->query( $sql, array( ':' . $key => $id ) );
	}
	/* FETCH */
	public function fetch ( $class = null ) {
		var_dump( $this->results );
		return $class === false ?
			$this->results->fetch( PDO::FETCH_ASSOC ) :
			$this->results->fetchObject( self::_obj( $class ) );
	}
	public function all ( $class = null ) {
		return $class === false ?
			$this->results->fetchAll( PDO::FETCH_ASSOC ) :
			new ArrayObject( $this->results->fetchAll( PDO::FETCH_CLASS, self::_obj( $class ) ) );
	}
	public function column ( $field, $index = null ) {
		$data   = $this->all( false );
		$values = self::_column( $data, $field );
		return $index ?
			new ArrayObject( array_combine( (array) self::_column( $data, $index ), (array) $values ) ) :
			$values;
	}
	/* VARIOUS */
	public function index ( $table ) {
		$index = 'INDEX:' . $table;
		if ( $config = self::config( $config ) )
			return $config;
		if ( ! $index = $this->db->query( 'SHOW INDEX FROM `' . $table . '`', PDO::FETCH_ASSOC ) )
			throw new Exception( 'No primary key on `' . $table . '` table, please specify a key' );
		return self::config( $index, $index->fetchAll( false ) );
	}
	public function key ( $table ) {
		$pk = 'PK:' . $table;
		if ( $config = self::config( $pk ) )
			return $config;
		if ( ! $key = $this->db->query( 'SHOW INDEX FROM `' . $table . '` WHERE `Key_name` = "PRIMARY"', PDO::FETCH_COLUMN, 4 ) )
			throw new Exception( 'No primary key on `' . $table . '` table, please specify a key' );
		return self::config( $pk, $key->fetchAll( false ) );
	}
	/* @todo */
	public function fields ( $table ) {

	}
	public function quote ( $value ) {

		return $this->db->quote( $value );
	}
	public function id () {

		return $this->db->lastInsertId();
	}
	public function error () {
		return ! $this->results ? 
			$this->db->errorInfo() :
			$this->results->errorInfo() ?: null;
	}
}