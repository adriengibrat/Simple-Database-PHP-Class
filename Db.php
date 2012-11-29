<?php
class Db {
	/* CONFIG */
	protected static $config;
	static public function config ( $key = null, $value = null ) {
		if ( ! isset( $key ) )
			return self::$config;
		if ( isset( $value ) )
      return self::$config[ (string) $key ] = $value;
    return is_array( $key ) ?
      array_map( 'self::config', array_keys( (array) $key ), array_values( (array) $key ) )
      : self::$config[ $key ];
	}
	/* CONSTRUCT */
	protected $db;
	private   $results;
	public function __construct ( $dns, $user, $pass = null ) {
		$this->db = new pdo( $dns, $user, $pass );
	}
	/* STATIC */
	private static $instance;
	static public function instance () {
		if ( self::$instance )
			return self::$instance;
		return self::$instance = new self( db::config( 'dsn' ) ? db::config( 'dsn' ) : db::config( 'dsn', db::config( 'driver' ) . ':host=' . db::config( 'host' ) . ';dbname=' . db::config( 'database' ) ), db::config( 'user' ), db::config( 'pass' ) );
	}
	/* QUERY */
	public function raw ( $query ) {
		if( ! $this->results = $this->db->query( $query ) )
			throw new Exception( 'SQL error : ' . implode( ' - ', $this->db->errorInfo() ) );
		return $this;
	}
	public function query ( $query, $params ) {
		$this->results = $this->db->prepare( $query );
		if ( ! $this->results->execute( $params ) )
			throw new Exception( 'SQL error : ' . implode( ' - ', $this->results->errorInfo() ) );
		return $this;
	}
	public function select ( $table, $columns = null ) {
		$columns = is_null( $columns ) ? '*' : '`' . implode( '`, `', (array) $columns ) . '`';
		return $this->raw( 'SELECT ' . $columns . ' FROM `' . $table . '`' );
	}
	/* HELPERS */
	protected function _key ( $table, $key = null ) {
		return $key ? preg_replace( '/\W/', '', $key ) : $this->key( $table );
	}
	static protected function _obj ( $class ) {
		return $class ?: self::config( 'obj' ) ?: 'stdClass';
	}
	static protected function _param ( $data, $glue = ', ' ) {
		static $param;
		if ( ! $param )
			$param = function ( $k ) { return "`$k` = :$k"; };
		return implode( $glue, array_map( $param, array_keys( $data ) ) );
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
	public function obj ( $class = null ) {
		return $this->results->fetchObject( self::_obj( $class ) );
	}
	public function assoc () {
		return $this->results->fetch( PDO::FETCH_ASSOC );
	}
	public function all ( $class = null ) {
		return new ArrayObject( $this->results->fetchAll( PDO::FETCH_CLASS, self::_obj( $class ) ) );
	}
	public function column ( $field ) {
		$field = preg_replace( '/[^\w-]/', '', $field );
		return array_map( 
			create_function( '$r', 'if ( isset( $r->{"' . $field . '"} ) ) return $r->{"' . $field . '"};' ), 
			$this->all( 'stdClass' )->getArrayCopy()
		);
	}
	/* VARIOUS */
	public function key ( $table, $key = null ) {
		$config = $table . ':PK';
		if ( $key )
			return self::config( $config, $key );
		if ( $pk = self::config( $config ) )
			return $pk;
		if ( $index = $this->db->query( 'SHOW INDEX FROM `' . $table . '` WHERE `Key_name` = "PRIMARY"', PDO::FETCH_COLUMN, 4 ) )
			return self::config( $config, $index->fetch() );
		throw new Exception( 'No primary key on `' . $table . '` table, please specify a key' );
	}
	public function quote ( $value ) {
		return $this->db->quote( $value );
	}
	public function id () {
		return $this->db->lastInsertId();
	}
  public function error () {
    return $this->db->errorInfo();
  }
}