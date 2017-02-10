<?php
namespace ActiveRecords\Handlers;

use ActiveRecords\DataValidator;

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
  }

  /**
   * Forms the last part of sql INSERT query in a
   * classic way, e.g.:
   * (`col1`, `col2` ... ) VALUES (:value1, :value: ... )
   *
   * @param  array  data fields to check for
   * @param  array  array by ref to be filled with values from POST
   * @param  array  source of values (optional), $_POST if not supplied
   */
  static protected function pdo_insert($data, &$values) {
    $part1 = $part2 = '';

    foreach ($data as $field=>$value)
    {
      $tag = isset($values[$field]) ? $field . rand() : $field;
      $part1 .= '`' . $field . '`,';
      $part2 .= ':' . $tag . ',';
      $values[$tag] = $value;
    }
    return ' (' . substr($part1, 0, -1) . ') VALUES (' . substr($part2, 0,-1) . ');';
  }
}
?>