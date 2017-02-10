<?php
namespace ActiveRecords;

class DataValidator
{
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
    $valid = true;
    foreach($this->mandatory_fields as $field)
    {
      if ( ! isset($data[$field]) )
      {
        $valid = false;
        $this->validation_errors[self::ERROR_MISSED_MANDATORY_FIELD] = sprintf('Mandatory field "%s" is not found.',$field);
        break;
      }
    }
    return $valid;
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
      $this->validation_errors[self::ERROR_RECORD_EXIST] = sprintf('Record already exists: %s',print_r($criteria, true));
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
     if ( ! is_array($arg) || count(array_filter(array_keys($arg),'is_string')) === 0 )
     {
       $this->validation_errors[self::ERROR_NOT_ASSOC] = sprintf('Data supplied is %s, not associative array.', print_r($arg, true));
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