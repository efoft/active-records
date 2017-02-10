<?php
namespace ActiveRecords\Handlers;

use ActiveRecords\DataValidator;

class MongoDBHandler extends DataValidator implements HandlerInterface
{
  /**
   * @var
   *
   * Database handler - MongoClient object.
   */
  private $db;

  /**
   * @var
   *
   * Collection handler - MongoCollection object.
   */
  private $coll;

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

  /**
   * Converts universal criteria syntax to specific mongo usage.
   *
   * @param		array		$criteria
   * @return  array
   */
  private static function normalize_criteria($criteria)
  {
    // replace id with _id
    if ( isset($criteria['id']) )
    {
      $criteria['_id'] = new \MongoId($criteria['id']);
      unset($criteria['id']);
    }

    // regexp
    foreach($criteria as $field=>&$value)
    {
      if ( substr($value,0,1) === '/' && ( substr($value,-1,1) === '/' || substr($value,-2,2) === '/i' ) )
      {
        $value = new \MongoRegex($value);
      }
    }
    return $criteria;
  }

  /**
   * Converts any sort syntax (see below) to relevant to the current database syntax.
   * ASC  (MySQL) == 1 (MongoDB)
   * DESC (MySQL) == -1 (MongoDB)
   */
  private static function normalize_sort($sort)
  {
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

  public function add($data, $tblname = NULL)
  {
    if ( ! $this->validated($data) )
      return;

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
    $coll->update(self::normalize_criteria($criteria), array('$set'=>$data), array('multiple'=>true));
  }

  public function delete($criteria = array(), $tblname = NULL)
  {
    $coll = $tblname ? $this->db->selectCollection($tblname) : $this->coll;
    $coll->remove(self::normalize_criteria($criteria));
  }
}
?>