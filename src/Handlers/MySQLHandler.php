<?php
namespace ActiveRecords\Handlers;

use ActiveRecords\DataValidator;
use QueryBuilder\SQLQueryBuilder;

class MySQLHandler extends DataValidator implements HandlerInterface
{
  /**
   * @var
   *
   * Defines the PDO strategy of handling errors:
   * PDO::ERRMODE_SILENT (default)
   * PDO::ERRMODE_WARNING
   * PDO::ERRMODE_EXCEPTION
   * See http://php.net/manual/en/pdo.error-handling.php
   */
  protected static $errMode = \PDO::ERRMODE_EXCEPTION;

  /**
   * @var
   *
   * Defines default fetch mode. To change use $this->setHandlerAttr('fetchMode',...)
   *
   * PDO::ATTR_DEFAULT_FETCH_MODE == PDO::FETCH_BOTH (default) - returns array with both numeric indexes and column names indexes
   * PDO::FETCH_ASSOC - column named indexed array
   * PDO::FETCH_NUM - numeric indexed array (starting from 0)
   * etc, see http://php.net/manual/en/pdostatement.fetch.php
   */
  private $fetchMode = \PDO::FETCH_ASSOC;

  /**
   * @var   object
   *
   * Database handler - PDO object
   */
  protected $dbh;
  
  /**
   * @var  object
   *
   * PDO::Statement instance
   */
  private $stmt;

  /**
   * @var   string
   *
   * Default database table to use in SQL queries.
   */
  private $tblname;
  
  /**
   * @var   array
   *
   * List of subtables to map some fields
   */
  private $fieldsInSubtables = array();
  
  /**
   * @var   object
   *
   * SQLQueryBuilder object
   */
  protected $qb;

  /**
   * @var   boolean
   *
   * Debug mode flag
   */
  private $debug = false;
  
  /**
   * @var   boolean
   *
   * Whether or not SQL subtables uses CONSTRAINT ... ON DELETE CASCADE with main table
   */
  private $cascase = true;

  public function __construct($dbname, $dbuser = NULL, $dbpass = NULL, $dbhost = 'localhost', $dbport='3306', $dbchar = 'UTF-8')
  {
    $dsn = "mysql:host=${dbhost};dbname=${dbname};charset=${dbchar};port=${dbport}";
    try {
      $this->dbh = new \PDO($dsn, $dbuser, $dbpass);
    }
    catch(\PDOException $e) {
      trigger_error(sprintf('Failed to connect with: %s. Error: %s', $dsn, $e->getMessage()), E_USER_ERROR);
    }
    $this->dbh->setAttribute(\PDO::ATTR_ERRMODE, self::$errMode );
    
    $this->setQueryBuilder();
  }
  
  /**
   * Setup $this->qb. The function is separated from __construct in order to be easily replaced in child classes.
   *
   */
  protected function setQueryBuilder()
  {
    $this->qb = new SQLQueryBuilder('mysql');
  }

  /**
   * Set default database table to use in queries.
   *
   * @param		string		$tblname
   */
  public function setTable($tblname)
  {
    $this->tblname = $tblname;
  }

  public function setHandlerAttr($attrName, $value)
  {
    switch($attrName)
    {
      case 'fetchMode':
        $this->fetchMode = $value;
        break;
      case 'cascade':
        $this->cascase = (bool)$value;
        break;
      case 'fieldsInSubtables';
        if ( ! is_array($value) )
          throw new \InvalidArgumentException('Attribute "fieldsInSubtables" requires an array as value.');
        $this->fieldsInSubtables = $value;
        break;
      default:
        throw new \InvalidArgumentException(sprintf('%s attribute is not supported by %s', $attrName, __CLASS__));
    }
  }
  
  public function setDebug($debug = false)
  {
    $this->debug = (bool)$debug;
  }

  /**
   * Execute SQL query. Use for insert, update and delete operations. For select better to use fetch().
   * 
   * @param   string    $sql      SQL statement with :tokens
   * @param   array     $values   array of binded params
   * @return  boolean             true if resulted row count > 0
   */
  public function exec($sql, $values = array())
  {
    try {
      $this->debug && trigger_error(sprintf('Going to exec stmt "%s" with params "%s"', $sql, print_r($values, true)));
      $stmt = $this->dbh->prepare($sql);
      $stmt->execute($values);
      $this->stmt = $stmt;
      return (bool) $stmt->rowCount();
    }
    catch(\PDOException $e) {
      trigger_error(sprintf('Failed to execute sql query: %s. Error: %s', $sql, $e->getMessage()), E_USER_ERROR);
    } 
  }
  
  /**
   * Fetches records from executed SQL query. Applicable only for select operations.
   *
   * @param   string      $sql      SQL statement with :tokens
   * @param   array       $values   array of binded params
   * @return  array|null
   */
  public function fetch($sql, $values = array(), $fetch_single_record = false)
  {
    $this->exec($sql, $values);
    return $fetch_single_record ? $this->stmt->fetch($this->fetchMode) : $this->stmt->fetchAll($this->fetchMode);
  }

  /**
   * Checks if a record(-s) matching the supplied criteria exists.
   *
   * @param   array         $criteria
   * @param   string|null   $tblname
   * @return  boolean
   */
  public function exist($criteria = array(), $tblname = NULL)
  {
    $this->qb->newQuery()->select()->from($this->fixTablename($tblname))->where($criteria);
    return $this->exec($this->qb->getQuery(), $this->qb->getBindings());
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
      if ( $v === 1 )
        $v = 'ASC';
      if ( $v === -1 )
        $v = 'DESC';

      if ( $v !== 'ASC' && $v !== 'DESC' )
        throw new \InvalidArgumentException(sprintf('"%s" is invalid for sort, allowed values are: ASC, DESC, 1, -1', $v)); 
    }
    return $sort;
  }
  
  /**
   * Many function of the class allow to specify $tblname as optional argument. If it's not specified than globally set value
   * must be used. If neither was set raise exception.
   *
   * @param   string|null
   * @return  string
   * @throws  LogicException
   */
  private function fixTablename($tblname = NULL)
  {
    if ( ! $tblname )
      if ( NULL === $tblname = $this->tblname )
        throw new \LogicException('Table name must be either set via setTable() method or must be explicitly defined as argument');
    return $tblname;
  }

  /**
   * @param   array       $data     assoc array like ('fields'=>$value...), $value can be seq array to put into subtable
   * @param   string      $tblname  (optional)  table name if differs from globally set on has not been globally set via setTable()
   * @return  string|int            inserted id (main table not subtables)
   */
  public function add($data, $tblname = NULL)
  {
    if ( ! $this->validated($data) ) return;
    
    $subtables = array();
    if ( $this->fieldsInSubtables ) $this->splitFieldsSubtables($data, $subtables);
    
    $this->qb->newQuery()->insert($data)->table($this->fixTablename($tblname));
    $this->exec($this->qb->getQuery(), $this->qb->getBindings());
    if ( ( $inserted = $this->dbh->lastInsertId() ) && $subtables )
      foreach($subtables as $subtable=>$data)
        $this->modifySubtable($subtable, '$addToSet', $inserted, $data);
        
    return ( $inserted ) ? $inserted : NULL;
  }

  /**
   * If criteria match the same record multiple times (due to search through subtables), the function will return only distinct records.
   * 
   * @param   array|null  $criteria   search criteria: what fields must be or look like ('/regexp/'), as wildcards only .* is accepted, /i for case-insensitive
   * @param   array|null  $projection fields to include in the returned set
   * @param   array|null  $sort       fields to order by, assoc array to specify destination like ('field1'=>'ASC'...)
   * @param   int|null    $limit      how many records to return
   * @param   string      $tblname    (optional)  table name if differs from globally set on has not been globally set via setTable()
   * @return  array                   multi-dimentional assoc array of resulted set of db records
   */
  public function get($criteria = array(), $projection = array(), $sort = array(), $limit = NULL, $tblname = NULL)
  {
    return $this->select(false, $criteria, $projection, $sort, $limit, $tblname);
  }

  /**
   * @param   array|null  $criteria   search criteria: what fields must be or look like ('/regexp/'), as wildcards only .* is accepted, /i for case-insensitive
   * @param   array|null  $projection fields to include in the returned set
   * @param   string      $tblname    (optional)  table name if differs from globally set on has not been globally set via setTable()
   * @return  array                   assoc array of a signle db record
   */
  public function getOne($criteria = array(), $projection = array(), $tblname = NULL)
  {
    return $this->select(true, $criteria, $projection, array(), NULL, $tblname);
  }

  /**
   * Single auxiliary function to run by get() and getOne().
   *
   */
  private function select($one = false, $criteria = array(), $projection = array(), $sort = array(), $limit = NULL, $tblname = NULL)
  {
    $subtables = array();
    if ( $this->fieldsInSubtables ) $this->splitFieldsSubtables($projection, $subtables);
    
    $this->qb->newQuery()->select($projection)->from($this->fixTablename($tblname))->where($criteria)->order(self::normalize_sort($sort))->limit($limit);
    foreach($this->extractSubtables($criteria) as $subtable)
      $this->qb->join($subtable,'id','relid')->distinct();
        
    if ( ( $result = $this->fetch($this->qb->getQuery(), $this->qb->getBindings(), $one) ) && $subtables )
      if ( $one )
        $this->appendSubtableData($result, $subtables);
      else
        foreach($result as &$record)
         $this->appendSubtableData($record, $subtables);
        
    return $result; 
  }
  
  public function update($criteria = array(), $data, $tblname = NULL)
  {
    $tblname = $this->fixTablename($tblname);
    
    // separate $data into `operations`: $set, $addToSet, $push, $pull
    // if no operation specified explicitly, $set is assumed
    $ops = array('$set'=>array(),'$addToSet'=>array(),'$push'=>array(),'$pull'=>array());
    foreach($data as $key=>$subdata)
      if ( in_array($key, array_keys($ops)) )
        foreach($subdata as $col=>$value)
          $ops[$key][$col] = $value;
      else
        $ops['$set'][$key] = $subdata;
    
    // some data in $set can also be related to subtables, so we also need to separate it out
    $subtables = array();
    if ( $this->fieldsInSubtables ) $this->splitFieldsSubtables($ops['$set'], $subtables);
  
    // do update for $set data in main table
    $this->qb->newQuery()->update($ops['$set'])->table($tblname)->where($criteria);
    foreach($this->extractSubtables($criteria) as $subtable)
      $this->qb->join($subtable,'id','relid');
      
    $this->exec($this->qb->getQuery(), $this->qb->getBindings());
    
    // for subtables operation we have to determine the id from the main table
    $relid = ( isset($criteria['id']) ) ? $criteria['id'] : $this->getRecordId($criteria, $tblname);
    
    // do modifications for subtables found in $set
    foreach($subtables as $subtable=>$data)
      $this->modifySubtable($subtable, '$set', $relid, $data);
    
    // operations other than $set must be always related to subtables only (according to the logic of subtables)
    // so we modify subtables with specifying the operation type
    unset($ops['$set']);
    foreach($ops as $op=>$d)
      foreach($d as $subtable=>$data)
        $this->modifySubtable($subtable, $op, $relid, $data);
  }

  /**
   * @param   array|null  $criteria   search criteria: what fields must be or look like ('/regexp/'), as wildcards only .* is accepted, /i for case-insensitive
   * @param   string      $tblname    (optional)  table name if differs from globally set on has not been globally set via setTable()
   */
  public function delete($criteria = array(), $tblname = NULL)
  {
    $tblname = $this->fixTablename($tblname);
    $relid = ( isset($criteria['id']) ) ? $criteria['id'] : $this->getRecordId($criteria, $tblname);
    
    // exit if no id (=no record found)
    if ( ! $relid ) return;
    
    // if subtable don't use CONSTRAINT ... ON DELETE CASCADE with main table
    // we need to manually delete all related records
    if ( ! $this->cascase )
      foreach($this->fieldsInSubtables as $subtable)
        $this->clearSubtable($subtable, $relid);
    
    $this->qb->newQuery()->delete()->table($tblname)->where($criteria);
    foreach($this->extractSubtables($criteria) as $subtable)
      $this->qb->join($subtable,'id','relid');
      
    $this->exec($this->qb->getQuery(), $this->qb->getBindings());
  }
    
  /**
   * Helper function to separate real fields and subtables for $data & $projection in add(), get(), getOne(), update() and delete()
   * It intersects first array into two parts: one containing real table fields (with possibly data) and second reflecting subtables
   *
   * @param   array   $fields     assoc (for add, update) or sequential (for get, getOne, delete) array
   * @param   array   $subtables  empty array to be filled with subtables info
   */
  private function splitFieldsSubtables(&$fields, &$subtables)
  {
    if ( $fields )
    {
      if ( $this->isAssocArray($fields) ) // this function is in DataValidator parent class
      {
        // $fields contain data for insert or update, so split parts must also be assoc arrays
        $subtables = array_intersect_key($fields, array_flip($this->fieldsInSubtables));
        $fields = array_diff_key($fields, array_flip($this->fieldsInSubtables));
      }
      else
      {
        // $fields is sequential array meaning this is for select query
        $subtables = array_intersect($this->fieldsInSubtables, $fields);
        $fields = array_diff($fields, $this->fieldsInSubtables);
        // add id to the search criteria since we need it to look through subtables later
        if ( ! in_array('id', $fields) ) $fields[] = 'id';
      }
    }
    else
      $subtables = $this->fieldsInSubtables;
  }
  
  /**
   * Helper function to exctract only subtables criteria from full criteria array.
   *
   * @param   array   $criteria
   * @return  array
   */
  private function extractSubtables($criteria = array())
  {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($criteria), \RecursiveIteratorIterator::LEAVES_ONLY);
    $fields = array_keys(iterator_to_array($iterator));
    return array_intersect($this->fieldsInSubtables, $fields);
  }
  
  /**
   * Helper function to append subtables data to the resulted set of get() and getOne().
   *
   * @param   array   $result     result of a query exec to DB to be filled with subtables data
   * @param   array   $subtables  subset of $this->fieldsInSubtables
   */
  private function appendSubtableData(&$result, $subtables)
  {
    foreach($subtables as $subtable)
      $result[$subtable] = $this->getSubtable($subtable, $result['id']);
  }
  
  /**
   * Add or removes element from subtable.
   *
   * @param   string        $subtable
   * @param   string        $mode     one of: '$addToSet' - add but skip if already exists, '$push' - unconditionally add, '$pull' - remove
   * @param   integer       $relid    related id: id of the record in the main tables with which the records in the subtable are related
   * @param   array|string  $elems    can be single string or sequential array of elements to add or remove
   */
  private function modifySubtable($subtable, $mode, $relid, $elems)
  {
    if ( $mode === '$set' )
      $this->clearSubtable($subtable, $relid);
      
    foreach((array)$elems as $elem)
    {
      if ( $mode === '$pull')
        $this->qb->newQuery()->delete()->table($subtable)->where(array('relid'=>$relid, $subtable=>$elem));
      else
      {
        if ( $mode === '$addToSet' && $this->exist(array('relid'=>$relid, $subtable=>$elem), $subtable) ) continue;
        $this->qb->newQuery()->insert(array('relid'=>$relid, $subtable=>$elem))->table($subtable);
      }
      $this->exec($this->qb->getQuery(), $this->qb->getBindings());
    }
  }
  
  /**
   * Removes all elements in a subtable related to given id
   *
   * @param   string        $subtable
   * @param   string|int    $relid
   */
  private function clearSubtable($subtable, $relid)
  {
    $this->qb->newQuery()->delete()->table($subtable)->where(array('relid'=>$relid));
    $this->exec($this->qb->getQuery(), $this->qb->getBindings());
  }
  
  /**
   * Helper function to get subtable data as sequential array.
   *
   * @param   string   $subtable
   * @param   integer  $relid     related id: id of the record in the main tables with which the records in the subtable are related
   * @return  array               sequential array
   */
  private function getSubtable($subtable, $relid)
  {
    $this->qb->newQuery()->select($subtable)->from($subtable)->where(array('relid'=>$relid));
    $assoc = $this->fetch($this->qb->getQuery(), $this->qb->getBindings());
    return $assoc ? array_map(function($elem) use($subtable) { return $elem[$subtable]; }, $assoc) : array();
  }
  
  /**
   * Search by match criteria and returns id of the record if found.
   *
   * @param   array
   * @param   string
   * @return  integer
   */
  public function getRecordId($criteria, $tblname)
  {
    $this->qb->newQuery()->select('id')->from($tblname)->where($criteria);
    foreach($this->extractSubtables($criteria) as $subtable)
      $this->qb->join($subtable,'id','relid');
      
    if ( $record = $this->fetch($this->qb->getQuery(), $this->qb->getBindings(), true) )
      return $record['id'];
    
    $this->debug && trigger_error(sprintf('No record found or no id field exists with criteria %s.', print_r($criteria, true)));
    return;
  }
}
?>
