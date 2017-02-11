<?php
namespace ActiveRecords\Handlers;

use ActiveRecords\DataValidator;

class MySQLHandler extends DataValidator implements HandlerInterface
{

  const QUERY_INSERT = 1;
  const QUERY_SELECT = 2;
  const QUERY_UPDATE = 3;
  const QUERY_DELETE = 4;

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
   * @var
   *
   * Database handler - PDO object
   */
  protected $dbh;

  /**
   * @var
   *
   * Default database table to use in SQL queries.
   */
  private $tblname;

  /**
   * @var
   *
   * Used by pdo_ methods to store values related to query tags.
   */
  private $queryValues = array();


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
  }

  /**
   * Set default database table to use in queries.
   *
   * @param		string		$tblname
   */
  public function setTable( $tblname )
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
      default:
        throw new \InvalidArgumentException(sprintf('%s attribute is not supported by %s', $attrName, __CLASS__));
    }
  }

  public function exec($sql, $values = array(), $fetch_single_record = false)
  {
    try {
      $stmt = $this->dbh->prepare($sql);
      $stmt->execute($values);
    }
    catch(\PDOException $e) {
      trigger_error(sprintf('Failed to execute sql query: %s. Error: %s', $sql, $e->getMessage()), E_USER_ERROR);
    }

    if ( preg_match('/^SELECT.*/i', $sql) )
      return $fetch_single_record ? $stmt->fetch($this->fetchMode) : $stmt->fetchAll($this->fetchMode);
  }

  static private function pdo_insert($data, &$values) {
    return self::pdo_set($data, $values);
  }

  static private function pdo_set($data, &$values) {
    $set = '';

    foreach ($data as $field=>$value)
    {
      $tag = isset($values[$field]) ? $field . rand() : $field;
      $set .= "`".str_replace("`","``",$field)."`". "=:$tag, ";
      $values[$tag] = $value;
    }

    return ' SET ' . substr($set, 0, -2);
  }

  static private function pdo_where($criteria = array(), &$values)
  {
    $where = '';

    foreach($criteria as $field=>$value)
    {
      $tag = isset($values[$field]) ? $field . rand() : $field;

      $case_insensitive = NULL;
      if ( substr($value,0,1) === '/' && ( substr($value,-1,1) === '/' || $case_insensitive = substr($value,-2,2) === '/i' ) )
      {
        if ( $case_insensitive )
          $value = substr($value,0,-2);

        $value = trim($value, '/^$');
        $value = str_replace('.+','%',$value);
        $where .= $case_insensitive ? " AND (LOWER($field) LIKE :$tag)" : " AND ($field LIKE :$tag)";
      }
      else
      {
        $where .= " AND ($field = :$tag)";
      }
      $values[$tag] = $case_insensitive ? strtolower($value) : $value;
    }

    return $where ? ' WHERE ' . substr($where, 5) : '';
  }

  private function buildQuery($type, $what = array(), $where = array(), $sort = array(), $limit = NULL, $tblname = NULL)
  {
    if ( ! $tblname )
    {
      if ( NULL === $tblname = $this->tblname )
        throw new \LogicException('Table name must be either set via setTable() method or must be explicitly defined as argument');
    }

    $this->queryValues = array();

    switch($type)
    {
      case self::QUERY_SELECT:
        $select_what = $what ? implode(', ', $what) : '*';
        $order_by = $sort ? ' ORDER BY ' . key($sort) . ' ' . reset($sort) : '';
        $limit = $limit ? ' LIMIT ' . $limit : '';
        $sql = "SELECT ${select_what} FROM `${tblname}`" . self::pdo_where($where, $this->queryValues) . $order_by . $limit . ';';
        break;
      case self::QUERY_INSERT:
        $sql = "INSERT INTO `${tblname}`" . static::pdo_insert($what, $this->queryValues) . ';';
        break;
      case self::QUERY_UPDATE:
        $sql = "UPDATE `${tblname}`" . self::pdo_set($what, $this->queryValues) . self::pdo_where($where, $this->queryValues) . ';';
        break;
      case self::QUERY_DELETE:
        $sql = "DELETE FROM `${tblname}`" . self::pdo_where($what, $this->queryValues) . ';';
        break;
      default:
        throw new \InvalidArgumentException(sprintf('%s is not supported SQL query type', $type));
        break;
    }
    //trigger_error(sprintf('SQL: %s', $sql));
    return $sql;
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
      if ( $v === 1 )
        $v = 'ASC';
      if ( $v === -1 )
        $v = 'DESC';

      if ( $v !== 'ASC' && $v !== 'DESC' )
        throw new \InvalidArgumentException(sprintf('"%s" is invalid for sort, allowed values are: ASC, DESC, 1, -1', $v)); 
    }
    return $sort;
  }

  public function add($data, $tblname = NULL)
  {
    if ( ! $this->validated($data) )
      return;

    $sql = $this->buildQuery(self::QUERY_INSERT,$data, $tblname);
    $this->exec($sql, $this->queryValues);
    return $this->dbh->lastInsertId();
  }

  public function get($criteria = array(), $projection = array(), $sort = array(), $limit = NULL, $tblname = NULL)
  {
    $sql = $this->buildQuery(self::QUERY_SELECT, $projection, $criteria, self::normalize_sort($sort), $limit, $tblname);
    return $this->exec($sql, $this->queryValues);
  }

  public function getOne($criteria = array(), $projection = array(), $tblname = NULL)
  {
    $sql = $this->buildQuery(self::QUERY_SELECT, $projection, $criteria, $tblname);
    return $this->exec($sql, $this->queryValues, true);
  }

  public function update($criteria = array(), $data, $tblname = NULL)
  {
    $sql = $this->buildQuery(self::QUERY_UPDATE, $data, $criteria, $tblname);
    $this->exec($sql, $this->queryValues);
  }

  public function delete($criteria = array(), $tblname = NULL)
  {
    $sql = $this->buildQuery(self::QUERY_DELETE, $criteria, $tblname);
    $this->exec($sql, $this->queryValues);
  }
}
?>