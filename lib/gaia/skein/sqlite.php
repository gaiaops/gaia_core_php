<?php
namespace Gaia\Skein;
use Gaia\DB;
use Gaia\Exception;

// sqlite impementation of skein
class SQLite implements Iface {
    
    
    protected $db;
    protected $thread;
    protected $table_prefix;
    
    /**
    * Thread is an integer id that your thread of entries will be tied to.
    * For db, you can pass in:
    *       a closure that will accept the table name return the db
    *       a db\iface object
    *       a dsn string that will be passed to db\connection::instance to create the db object
    *  Table prefix is an optional string that will allow you to prefix your table names with
    * a custom string. If you pass in nothing, you will get back table names like:
    *       skein_index
    *       skein_201207
    * if you were to pass in 'test', you would get names like:
    *       testskein_index
    *       testskein_201207
    */
    public function __construct( $thread, $db, $table_prefix = '' ){
        $this->db = $db;
        $this->thread = $thread;
        $this->table_prefix = $table_prefix;
    }
    
    public function count(){
        $table = $this->table('index');
        $db = $this->db( $table );
        if( DB\Transaction::inProgress() ) DB\Transaction::add( $db );
        $sql = "SELECT SUM( `sequence` ) as ct FROM $table WHERE `thread` = %s";
        $rs = $db->execute( $sql, $this->thread );
        $result = 0;
        if( $row = $rs->fetch() ){
            $result = $row['ct'];
        }
        $rs->free();
        return $result;
    }
    
    public function get( $id ){
        if( is_array( $id ) ) return $this->multiget( $id );
        $res = $this->multiget( array( $id ) );
        return isset( $res[ $id ] ) ? $res[ $id ] : NULL;
    }
    
    protected function multiGet( array $ids ){
        $result = array_fill_keys( $ids, NULL );
        foreach( Util::parseIds( $ids ) as $shard=>$sequences ){
            $table= $this->table( $shard );
            $db = $this->db( $table );
            if( DB\Transaction::inProgress() ) DB\Transaction::add( $db );
            $sql = "SELECT `sequence`,`data` FROM `$table` WHERE `thread` = %s AND `sequence` IN( %i )";
            $rs = $db->execute( $sql, $this->thread, $sequences );
            while( $row = $rs->fetch() ){
                $id = Util::composeId( $shard, $row['sequence'] );
                $result[ $id ] = $this->unserialize($row['data']);
                if( ! is_array( $result[ $id ] ) ) $result[ $id ] = array();
            }
            $rs->free();
        }
        foreach( array_keys( $result, NULL, TRUE) as $rm ) unset( $result[ $rm ] );
        return $result;
    }
    
    
    public function add( $data, $shard = NULL ){
        $shard = strval($shard);
        if( ! ctype_digit( $shard ) ) $shard = Util::currentShard();
        $table = $this->table('index');
        $dbi = $this->db($table);
        DB\Transaction::start();
        $dbi->start();
        $sql = "INSERT OR IGNORE INTO $table (thread,shard,sequence) VALUES (%i, %i, 1)";
        $rs = $dbi->execute( $sql, $this->thread, $shard );
        if( ! $rs->affected() ){
            $sql = "UPDATE $table SET `sequence` = `sequence` + 1 WHERE `thread` = %i AND `shard` = %i";
            $dbi->execute( $sql, $this->thread, $shard );
        }
        $sql = "SELECT `sequence` FROM $table WHERE `thread` = %i AND `shard` = %i";
        $rs = $dbi->execute($sql, $this->thread, $shard);
        $sequence = NULL;
        if( $row = $rs->fetch() ) $sequence = $row['sequence'];
        $rs->free();
        $table = $this->table($shard);
        $dbs = $this->db( $table );
        $dbs->start();
        $sql = "INSERT OR IGNORE INTO $table (thread, sequence, data) VALUES (%i, %i, %s)";
        $data = $this->serialize($data);
        $dbs->execute( $sql, $this->thread, $sequence, $data );
        if( ! $rs->affected() ){
            $sql = "UPDATE $table SET `data` = %s WHERE `thread` = %i AND `sequence` = %i";
            $dbs->execute( $sql, $data, $this->thread, $sequence );
        }
        $dbi->commit();
        $dbs->commit();
        DB\Transaction::commit();
        $id = Util::composeId( $shard, $sequence );
        return $id;
    }
    
    public function store( $id, $data ){
        $ids = Util::validateIds( $this->shardSequences(), array( $id ) );
        if( ! in_array( $id, $ids ) ) throw new Exception('invalid id', $id );
        list( $shard, $sequence ) = Util::parseId( $id );
        $table = $this->table($shard);
        $db = $this->db( $table );
        if( DB\Transaction::inProgress() ) DB\Transaction::add( $db );
        $sql = "INSERT OR IGNORE INTO $table (thread, sequence, data) VALUES (%i, %i, %s)";
        $data = $this->serialize($data);
        $rs = $db->execute( $sql, $this->thread, $sequence, $data );
         if( ! $rs->affected() ){
            $sql = "UPDATE $table SET `data` = %s WHERE `thread` = %i AND `sequence` = %i";
            $db->execute( $sql, $data, $this->thread, $sequence );
         }         
        return TRUE;
    }
    
    public function ids( array $params = array() ){
        return Util::ids( $this->shardSequences(), $params );
    }
    
    public function filter( array $params ){
        Util::filter( $this, $params );
    }
    
    public function shardSequences(){
        $table = $this->table('index');
        $db = $this->db( $table );
        if( DB\Transaction::inProgress() ) DB\Transaction::add( $db );
        $sql = "SELECT `shard`, `sequence` FROM $table WHERE `thread` = %s ORDER BY `shard` DESC";
        $rs = $db->execute( $sql, $this->thread );
        $result = array();
        while( $row = $rs->fetch() ){
            $result[ $row['shard'] ] = $row['sequence'];
        }
        $rs->free();
        return $result;
    }
    
    public static function dataSchema( $table ){
        return 
        "CREATE TABLE IF NOT EXISTS $table (
          `thread` bigint  NOT NULL,
          `sequence` int NOT NULL,
          `data` text,
          UNIQUE (`thread`, `sequence`)
        )";
    }
    
    public static function indexSchema( $table ){
        return 
        "CREATE TABLE IF NOT EXISTS $table (
          `thread` bigint NOT NULL,
          `shard` int NOT NULL,
          `sequence` int NOT NULL,
          UNIQUE (`thread`, `shard`)
        )";
    }
    
    protected function serialize( $data ){
        return serialize( $data );
    }
    
    protected function unserialize( $string ){
        return unserialize( $string );
    }
    
    
    protected function table( $suffix ){
        return $this->table_prefix . 'skein_' . $suffix;
    }
    
    protected function db( $table ){
        if( $this->db instanceof \Closure ){
            $mapper = $this->db;
            $db = $mapper( $table );
        } elseif( is_scalar( $this->db ) ){
            $db = DB\Connection::instance( $this->db );
        } else {
            $db = $this->db;
        }
        if( ! $db instanceof DB\Iface ) throw new Exception('invalid db');
        if( ! $db->isa('sqlite') ) throw new Exception('invalid db');
        if( ! $db->isa('Gaia\DB\Except') ) $db = new DB\Except( $db );
        return $db;
    }
}
