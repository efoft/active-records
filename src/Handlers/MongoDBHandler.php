<?php
namespace ActiveRecords\Handlers;

use ActiveRecords\DataValidator;

class MongoDBHandler extends DataValidator implements HandlerInterface
{
  /**
   * @var   object
   *
   * Database handler - MongoClient instance.
   */
  private $db;

  /**
   * @var   object
   *
   * Collection handler - MongoCollection instance.
   */
  private $coll;
  
  /**
   * @var   boolean
   *
   * Debug mode flag
   */
  private $debug = false;

  public function __construct($dbname, $dbuser = NULL, $dbpass = NULL, $dbhost = 'localhost', $dbport = '27017')
  {
    $connopt =  ( $dbuser && $dbpass )
             ? array('username' => $dbuser, 'password' => $dbpass)
             : array();
    $connstring = "mongodb://${dbhost}:${dbport}";
    try 
    {
      $conn = new \MongoClient($connstring, $connopt);
    } 
    catch (\MongoConnectionException $e)
    {
      trigger_error(sprintf('Failed to connect with: %s. Error: %s', $connstring, $e->getMessage()), E_USER_ERROR);
    }

    try
    {
      $this->db = $conn->selectDB($dbname);
    }
    catch (\MongoException $e)
    {
      trigger_error(sprintf('Failed to use MongoDB database "%s". Error: %s', $dbname, $e->getMessage()), E_USER_ERROR);
    }
  }

  /**
   * Set collection to use. We call the function setTable for compatibility with SQL databases.
   *
   * @param		string		$collname		MongoDB collection name
   */
  public function setTable( $tblname )
  {
    if ( null === $this->db )
      throw new \LogicException('Cannot set MongoDB collection without database selection');

    $this->coll = $this->db->selectCollection($tblname);
  }

  public function setDebug($debug = false)
  {
    $this->debug = (bool)$debug;
  }
  
  public function add($data, $tblname = NULL)
  {
    if ( ! $this->validated($data) ) return;

    $coll = $tblname ? $this->db->selectCollection($tblname) : $this->coll;
    $coll->insert($data);
    // return inserted doc id
    return (string)$data['_id'];
  }

  public function get($criteria = array(), $projection = array(), $sort = array(), $limit = NULL, $tblname = NULL)
  {
    $coll = $tblname ? $this->db->selectCollection($tblname) : $this->coll;
    $cursor = $coll->find(self::normalize_criteria($criteria), $projection);
    if ( $sort ) $cursor->sort(self::normalize_sort($sort));
    if ( $limit ) $cursor->limit($limit);
    return iterator_to_array($cursor, false);
  }

  public function getOne($criteria = array(), $projection = array(), $tblname = NULL)
  {
    $coll = $tblname ? $this->db->selectCollection($tblname) : $this->coll;
    return $coll->findOne(self::normalize_criteria($criteria), $projection);
  }

  public function update($criteria = array(), $data, $tblname = NULL)
  { 
    $coll = $tblname ? $this->db->selectCollection($tblname) : $this->coll;
    $coll->update(self::normalize_criteria($criteria), self::normalize_data($data), array('multiple'=>true));
  }

  public function delete($criteria = array(), $tblname = NULL)
  {
    $coll = $tblname ? $this->db->selectCollection($tblname) : $this->coll;
    $coll->remove(self::normalize_criteria($criteria));
  }
  
  /**
   * Converts universal criteria syntax to specific mongo usage.
   *
   * @param		array		$criteria
   * @return  array
   */
  private static function normalize_criteria($criteria)
  {
    if ( $criteria && is_array($criteria) )
    {
      self::replace_keys($criteria, 'id', '_id');
      self::replace_with_mongo($criteria);
    }
        
    return $criteria;
  }
  
  /**
   * Helper function recursively iterates over multi-dimentional array and makes replacement of old array key with new one
   * but preserving the order.
   *
   * @param   array   $arr
   * @param   string  $old_key
   * @param   string  $new_key
   */
  private static function replace_keys(&$arr, $old_key, $new_key)
  {
    if ( is_array($arr) )
    {
      $keys = array_keys($arr);
      while( false !== $index = array_search($old_key, $keys, true) )
        $keys[$index] = $new_key;
  
      $arr = array_combine($keys, array_values($arr));
      
      foreach($arr as &$subarr)
        self::replace_keys($subarr, $old_key, $new_key);
    }
  }
  
  /**
   * Helper function recursively iterates over multi-dimentional array and replaces the values:
   * - for each _id key: with MongoId()
   * - for each regular expression: with MongoRegex()
   *
   * @param   array   $arr
   */
  private static function replace_with_mongo(&$arr)
  {
    foreach($arr as $k=>&$v)
      if ( is_array($v) )
        self::replace_with_mongo($v);
      elseif ( $k === '_id' )
        $v = new \MongoId($v);
      elseif ( preg_match('%/.*/i?%', $v) )
        $v = new \MongoRegex($v);
  }

  /**
   * Converts any sort syntax (see below) to relevant to the current database syntax.
   * ASC  (MySQL) == 1 (MongoDB)
   * DESC (MySQL) == -1 (MongoDB)
   */
  private static function normalize_sort($sort)
  {
    if ( ! is_array($sort) )
      throw new \InvalidArgumentException(sprintf('Array expected as argument, %s is given.', gettype($sort)));
    
    foreach($sort as $k=>&$v)
    {
      if ( preg_match('/^ASC$/i', $v) )
        $v = 1;
      if ( preg_match('/^DESC$/i', $v) )
        $v = -1;

      if ( $v !== 1 && $v !== -1 )
        throw new \InvalidArgumentException(sprintf('"%s" is invalid for sort, allowed values are: ASC, DESC, 1, -1', $v)); 
    }
    return $sort;
  }
  
  /**
   * Helper function which puts '$set' mode for those data where the mode was omitted.
   *
   * @param   array   $data
   * @return  array   $data   modified input
   */
  private static function normalize_data($data)
  {
    if ( $data && is_array($data) )
    {
      $supported_modes = array('$set','$addToSet','$pull','$push');
    
      foreach($data as $k=>&$v)
        if ( ! in_array($k, $supported_modes) )
        {
          // if there is no '$set' already in $data - initialize it
          if ( ! isset($data['$set']) ) $data['$set'] = array();
          
          $data['$set'] = array_merge($data['$set'], array($k=>$v));
          unset($data[$k]);
        }
    }
    return $data;
  }

  public function getRecordId($criteria, $tblname)
  {
    if ( $record = $this->getOne($criteria, array('_id'), $tblname) )
      return $record['_id'];
    
    $this->debug && trigger_error(sprintf('No record found with criteria %s.', print_r($criteria, true)));
    return;
  }
}
?>
