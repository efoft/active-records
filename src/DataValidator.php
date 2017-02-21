<?php
namespace ActiveRecords;

class DataValidator
{
  const ERROR_EMPTY_SET = 1000;
  const ERROR_NOT_ASSOC = 1001;
  const ERROR_MISSED_MANDATORY_FIELD = 1002;
  const ERROR_RECORD_EXIST = 1003;

  private $mandatory_fields = array();
  private $unique_record_fields = array();
  private $validation_errors = array();

  public function setMandatoryFields($fields)
  {
    if ( ! is_array($fields) )
      throw new \InvalidArgumentException('Argument must be an array');

    $this->mandatory_fields = $fields;
  }

  public function setUniqueRecordFields($fields)
  {
    if ( ! is_array($fields) )
      throw new \InvalidArgumentException('Argument must be an array');

    $this->unique_record_fields = $fields;
  }

  protected function testMandatoryFields($data)
  {
    $missed_fields = array();
    foreach($this->mandatory_fields as $field)
    {
      if ( ! isset($data[$field]) )
        $missed_fields[] = $field;
    }
    if ( $missed_fields )
      $this->validation_errors[self::ERROR_MISSED_MANDATORY_FIELD] = array(
        'errmsg'  =>'Mandatory field(-s) not found.',
        'extinfo' => $missed_fields
      );
    return (bool) ! $missed_fields;
  }

  protected function testRecordExist($data)
  {
    $criteria = array();
    foreach($this->unique_record_fields as $field)
    {
      if ( isset($data[$field]) )
        $criteria[$field] = $data[$field];
    }
    if ( $exists = (bool)$this->getOne($criteria) )
      $this->validation_errors[self::ERROR_RECORD_EXIST] = array(
        'errmsg'  => 'Record already exists matching criteria: %s',print_r($criteria, true),
        'extinfo' => $exists
      );
    return $exists;
  }

  /**
   * Check if arguments are associative arrays.
   *
   * @param   array(s)	origin data to operate with
   * @return  bool			true|false = passed pre-check or not
   */
  private function isAssocArray()
  {
    $retval = true;
    // check whether each of args is associatiave array (keys are strings)
    foreach( func_get_args() as $arg )
    {
      if ( empty($arg) )
      {
        $this->validation_errors[self::ERROR_EMPTY_SET] = array('errmsg'=>'Empty set received.', 'extinfo'=>'');
        $retval = false;
        break;
      }
      elseif ( ! is_array($arg) || count(array_filter(array_keys($arg),'is_string')) === 0 )
      {
        $this->validation_errors[self::ERROR_NOT_ASSOC] = array(
          'errmsg'  => 'Data supplied is not associative array.',
          'extinfo' => print_r($arg, true)
        );
        $retval = false;
        break;
      }
    }
    return $retval;
  }

  public function validated($data)
  {
    $bValue = $this->isAssocArray($data);

    if ( $bValue && $this->mandatory_fields )
      $bValue = $bValue & $this->testMandatoryFields($data);

    if ( $bValue && $this->unique_record_fields )
      $bValue = $bValue & ! $this->testRecordExist($data);

    return $bValue;
  }

  public function getError()
  {
    return $this->validation_errors;
  }
}
?>