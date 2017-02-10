<?php
namespace ActiveRecords\Handlers;

interface HandlerInterface
{
  public function add($data, $tblname);
  public function get($criteria, $projection, $sort, $limit, $tblname);
  public function getOne($criteria, $projection, $tblname);
  public function update($criteria, $data, $tblname);
  public function delete($criteria, $tblname);
}
?>