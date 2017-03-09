<?php
namespace ActiveRecords\Handlers;

use ActiveRecords\DataValidator;
use QueryBuilder\SQLQueryBuilder;

class SQLiteHandler extends MySQLHandler implements HandlerInterface
{

  public function __construct($dbfile)
  {
    $dsn = "sqlite:${dbfile}";
    try {
      $this->dbh = new \PDO($dsn);
    }
    catch(\PDOException $e) {
      trigger_error(sprintf('Failed to connect with: %s. Error: %s', $dsn, $e->getMessage()), E_USER_ERROR);
    }
    $this->dbh->setAttribute(\PDO::ATTR_ERRMODE, self::$errMode );
    
    $this->setQueryBuilder();
  }

  /**
   * Setup $this->qb. This method is the only diff from parent class.
   *
   */
  protected function setQueryBuilder()
  {
    $this->qb = new SQLQueryBuilder('sqlite');
  }
}
?>
