<?php
namespace ActiveRecords;

use ActiveRecords\Handlers\HandlerInterface;

class ActiveRecords
{

  private $handler;

  public function __construct(HandlerInterface $handler)
  {
    $this->handler = $handler;
  }

  public function getHandler()
  {
    return $this->handler;
  }

  public function setTable($tblname)
  {
    if ( empty($tblname) || ! is_string($tblname) )
      throw new \InvalidArgumentException(sprintf('"%s" is not valid table name, set it to non empty string.', $tblname));

    $this->handler->setTable($tblname);
  }

  public function __call($method, $args)
  {
    return call_user_func_array(array($this->handler, $method), $args);
  }
}
?>