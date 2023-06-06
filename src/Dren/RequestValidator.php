<?php

namespace Dren;

use stdClass;

abstract class RequestValidator
{
    abstract protected function setRules() : void;
    protected Request $request;

    protected string $failureResponseType = 'redirect'; // or json

    protected array $rules = [];

    private array $requestData = [];

    protected array $messages = [];

    private array $errors = [];

    public function __construct(Request $request)
    {

        $this->request = $request;

        $this->requestData = array_merge(
            ($request->getGetData() ? (array)$request->getGetData() : []),
            ($request->getPostData() ? (array)$request->getPostData() : [])
        );

    }

    public function getErrors() : object
    {
        return (object)$this->errors;
    }

    public function getFailureResponseType() : string
    {
        return $this->failureResponseType;
    }

    public function validate() : bool
    {
        $this->setRules();

        foreach($this->rules as $field => $methodChain)
        {
            if(is_string($methodChain))
                $methodChain = explode('|', $methodChain);

            foreach($methodChain as $methodChainDetails)
            {
                if(is_string($methodChainDetails))
                {
                    $fenceUp = false;
                    if(str_starts_with($methodChainDetails, "#"))
                    {
                        $fenceUp = true;
                        $methodChainDetails = substr($methodChainDetails, 1);
                    }

                    $methodChainDetails = explode(':', $methodChainDetails);
                    $method = $methodChainDetails[0];
                    $params = [];
                    if(count($methodChainDetails) > 1)
                        $params = explode(',', $methodChainDetails[1]);
                    $this->$method(array_merge([$field, $this->requestData[$field]], $params));

                    if($fenceUp && count($this->errors) > 0)
                        break 2;
                    else
                        continue;
                }

                $methodChainDetails($this->requestData, $this->errors);
            }
        }

        if(count($this->errors) > 0)
            return false;

        return true;
    }

    /******************************************************************************
     * Add various validation methods below this line:
     * We allow underscores in function names here due to how they are called from
     * lists of strings (the underscores make for better readability in child classes)
     ******************************************************************************/
    private function _setErrorMessage($method, $field, $defaultMsg)
    {
        $key = $field . '.' . $method;

        $msgToUse = $defaultMsg;
        if(array_key_exists($key, $this->messages))
            $msgToUse = $this->messages[$key];

        $this->errors[$field][] = $msgToUse;
    }

    /******************************************************************************
     * Method signatures are all an array where the following is true:
     * $input[0 => $fieldName, 1 => $value, 2 => (optional...), 3 => (...)]
     ******************************************************************************/

    private function required(array $params) : void
    {
        if($params[1])
            return;

        $this->_setErrorMessage('required', $params[0], $params[0] . ' is required');
    }

    private function min_char(array $params) : void
    {
        $valString = (string)$params[1];
        if(strlen($valString) > $params[2])
            return;

        $this->_setErrorMessage('min_char', $params[0], $params[0] . ' must be at least ' . $params[2] . ' characters');
    }

    private function max_char(array $params) : void
    {
        $valString = (string)$params[1];
        if(strlen($valString) <= $params[2])
            return;

        $this->_setErrorMessage('max_char', $params[0], $params[0] . ' must be less than or equal to ' . $params[2] . ' characters');
    }

    private function email(array $params) : void
    {
        if(filter_var($params[1], FILTER_VALIDATE_EMAIL) !== false)
            return;

        $this->_setErrorMessage('email', $params[0], $params[0] . ' must be an email address');
    }

    private function same(array $params) : void
    {
        if(key_exists($params[2], $this->requestData) && $this->requestData[$params[2]] == $params[1])
            return;

        $this->_setErrorMessage('same', $params[0], $params[0] . ' must match ' . $params[2]);
    }

    //!!!!! NOTE !!!!!!!
    // Some of these queries might look like sql injection vulnerabilities at first glance, however, note where user input
    // is handled, no user input is ever concatenated with the query string, only values provided by the application code
    // itself, user input is still always parameterized
    private function unique(array $params) : void
    {
        if(!App::get()->getDb()->query("SELECT * FROM " . $params[2] . " WHERE " . $params[3] . " = ?", [$params[1]])->singleAsObj()->exec())
            return;

        $this->_setErrorMessage('unique', $params[0], $params[0] . ' must be unique');
    }


}