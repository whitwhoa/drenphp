<?php

namespace Dren;

use stdClass;

abstract class RequestValidator
{
    abstract protected function setRules() : void;
    protected Request $request;

    protected array $rules = [];

    private array $requestData = [];

    protected array $messages = [];

    private ValidationErrorContainer $errors;

    private array $expandedFields = [];

    private array $methodChains = [];

    private string $valueNotPresentToken;

    public function __construct(Request $request)
    {
        $this->request = $request;

        $this->requestData = array_merge(
            ($request->getGetData() ? (array)$request->getGetData() : []),
            ($request->getPostData() ? (array)$request->getPostData() : []),
            ($request->allFilesByFormName())
        );

        $this->errors = new ValidationErrorContainer();

        $this->valueNotPresentToken = md5(mt_rand(1, 1000) . time());
    }

    public function getErrors() : ValidationErrorContainer
    {
        return $this->errors;
    }

    /*
    Validate functionality has been defaulted to exit validation for a field once the first
    failed method is hit. If you want to run every method regardless as to whether or not the
    previous method was successful, prefix 'run_all' to the method chain
    */
    public function validate() : bool
    {
        $this->setRules();

        $this->_expandFields();

        //dad($this->expandedFields);
        //dad($this->request);

        foreach($this->expandedFields as $ef)
        {
            // ef = [the.*.field.*.name, requestData, methodChain]

            $methodChain = $this->methodChains[$ef[2]];
            if(is_string($methodChain))
                $methodChain = explode('|', $methodChain);

            /*
             *
             * elements are nullable if form element is:
                > not present - ie form submission did not send the element at all
                    > in this case the element will exist within expandedFields and it's value will be null
                > present but does not contain a value - form sent element but provided no value:
                    > what does "no value" mean in which contexts?
                        > if value is array but has no elements
                        > if value is object and of class UploadedFile but has error (no uploaded data viewed as error)
                        > if value is null
                        > if value is empty string
             *
             */
            if(\in_array('nullable', $methodChain))
            {
                if
                (
                    (is_array($ef[1]) && count($ef[1]) === 0) ||
                    (is_object($ef[1]) && get_class($ef[1]) === 'Dren\UploadedFile' && $ef[1]->hasError()) ||
                    ($ef[1] === null) ||
                    ($ef[1] === '') ||
                    ($ef[1] == $this->valueNotPresentToken)
                ){
                    // skip over running validation method chain for this element as it was "null"
                    continue;
                }

                // remove nullable from methodChain
                $updatedMethodChain = [];
                foreach($methodChain as $m)
                    if($m !== 'nullable')
                        $updatedMethodChain[] = $m;

                $methodChain = $updatedMethodChain;
            }

            /*
             * "sometimes" works exactly like nullable only if an element is provided it must NOT be null, so either
             * the form contains the element and the element is not nothing and the rest of the validation rules will
             * execute, OR the form does not contain the element and none of the validation rules are run
             */
            if(\in_array('sometimes', $methodChain))
            {
                if($ef[1] == $this->valueNotPresentToken)
                    continue;

                if
                (
                    (is_array($ef[1]) && count($ef[1]) > 0 ) ||
                    (is_object($ef[1]) && get_class($ef[1]) === 'Dren\UploadedFile' && !$ef[1]->hasError()) ||
                    ($ef[1] !== null && $ef[1] !== '')
                ){
                    // remove sometimes from methodChain
                    $updatedMethodChain = [];
                    foreach($methodChain as $m)
                        if($m !== 'sometimes')
                            $updatedMethodChain[] = $m;

                    $methodChain = $updatedMethodChain;
                }
                else
                {
                    $methodChain = [];
                    $methodChain[] = 'required';
                }
            }

            $runAll = \in_array('run_all', $methodChain);
            if($runAll)
            {
                // remove run_all from methodChain
                $updatedMethodChain = [];
                foreach($methodChain as $m)
                    if($m !== 'run_all')
                        $updatedMethodChain[] = $m;

                $methodChain = $updatedMethodChain;
            }

            foreach($methodChain as $methodChainDetails)
            {
                $preMethodCallErrorsCount = $this->errors->count();
                $fenceUp = false;

                // If $methodChainDetails is not a string then it is a user defined callable. Run callable,
                // and continue to next method in chain
                if(!is_string($methodChainDetails))
                {
                    $methodChainDetails($this->requestData, $this->errors, $fenceUp);

                    if($this->errors->count() > $preMethodCallErrorsCount)
                    {
                        if($fenceUp)
                            break 2;

                        if(!$runAll)
                            break;
                    }

                    continue;
                }

                // parse method chain details string to get our method and any parameters
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

                // run previously deduced method passing in parameters
                $this->$method(array_merge([$ef[0], $ef[1]], $params));

                // do checks to determine what we should do if the previous method call added an error to the
                // error list
                if($this->errors->count() > $preMethodCallErrorsCount)
                {
                    if($fenceUp)
                        break 2;

                    if(!$runAll)
                        break;
                }
            }
        }

        return !($this->errors->count() > 0);
    }

    private function _expandFields() : void
    {
        // expand fields such that when array syntax provided .*.etc.*....
        // each individual element is expanded into its own "field"

        // [the.*.field.*.name, requestData, methodChain]

        foreach($this->rules as $field => $methodChain)
        {
            $this->methodChains[] = $methodChain;

            $keys = explode('.', $field);
            $index = 0;
            $this->_expandFieldsRecursiveLoop($this->requestData, $keys, $index, []);
        }
    }

    private function _expandFieldsRecursiveLoop($data, &$keys, &$index, $path) : void
    {
//        echo '-------------------------------<br/>';
//        echo '<pre>';
//        echo '$data: ' . var_export($data, true) . '<br/>';
//        echo '$keys: ' . var_export($keys, true) . '<br/>';
//        echo '$index: ' . var_export($index, true) . '<br/>';
//        echo '$path: ' . var_export($path, true) . '<br/>';
//        echo '</pre>';

        if ($index >= count($keys))
            return;

        $currentKey = $keys[$index];
        $path[] = $currentKey;

        if ($currentKey === '*')
        {
            $itemIndex = 0;
            if($data && \is_array($data) && count($data) > 0)
            {
                foreach ($data as $item)
                {
                    $path[count($path) - 1] = $itemIndex++;
                    $this->_expandFieldsProcessItem($item, $keys, $index, $path);
                    $path[count($path) - 1] = '*';
                }
            }
            else
            {
                $this->_expandFieldsProcessItem($this->valueNotPresentToken, $keys, $index, $path);
            }

        }
        else if (isset($data[$currentKey]))
        {
            $this->_expandFieldsProcessItem($data[$currentKey], $keys, $index, $path);
        }
        else
        {
            $this->_expandFieldsProcessItem($this->valueNotPresentToken, $keys, $index, $path);
        }

        array_pop($path);
    }

    private function _expandFieldsProcessItem($item, &$keys, &$index, $path) : void
    {
        if ($index < count($keys) - 1)
        {
            $index++;
            $this->_expandFieldsRecursiveLoop($item, $keys, $index, $path);
            $index--;
        }
        else
        {
            $newPath = array_shift($path); // separate first element
            foreach($path as $p){
                $newPath .= '[' . $p . ']';  // add remaining elements with brackets
            }

            $this->expandedFields[] = [$newPath, $item, (count($this->methodChains) - 1)];
        }
    }


    private function _setErrorMessage($method, $field, $defaultMsg) : void
    {
        $explodedField = explode('.', $field);

        if(count($explodedField) > 1)
        {
            $key = '';
            foreach($explodedField as $v)
            {
                if(is_numeric($v))
                    $key .= '*';
                else
                    $key .= $v;

                $key .= '.';
            }
            $key .= $method;
        }
        else
        {
            $key = $field . '.' . $method;
        }

        $msgToUse = $defaultMsg;
        if(array_key_exists($key, $this->messages))
            $msgToUse = $this->messages[$key];

        $this->errors->add($field, $msgToUse);
    }

    /******************************************************************************
     * Add various validation methods below this line:
     * We allow underscores in function names here due to how they are called from
     * lists of strings (the underscores make for better readability in child classes)
     ******************************************************************************/


    /******************************************************************************
     * Method signatures are all an array where the following is true:
     * $input[0 => $fieldName, 1 => $value, 2 => (optional...), 3 => (optional...etc)]
     ******************************************************************************/

    private function required(array $params) : void
    {
//        echo '<pre>';
//        echo var_export($params, true);
//        echo '</pre>';

        // if a form element is listed in $this->rules, then the data that is being validated will always contain
        // an element of that name. If an element with that name was not provided with the form submission, it's value
        // will be null

        // Countable values
        if(is_array($params[1]))
        {
            if(count($params[1]) === 0)
                $this->_setErrorMessage('required', $params[0], $params[0] . ' is required');

            return;
        }

        // File uploads
        if(is_object($params[1]) && get_class($params[1]) === 'Dren\UploadedFile')
        {
            if($params[1]->hasError())
                $this->_setErrorMessage('required', $params[0], $params[0] . ' is required');

            return;
        }

        // Everything else
        if($params[1] !== null && $params[1] !== '')
            return;

        $this->_setErrorMessage('required', $params[0], $params[0] . ' is required');
    }

    private function numeric(array $params) : void
    {
        if(is_numeric($params[1]))
            return;

        $this->_setErrorMessage('numeric', $params[0], $params[0] . ' must be numeric');
    }

    private function min_char(array $params) : void
    {
        $valString = (string)$params[1];
        if(strlen($valString) >= $params[2])
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

    private function is_array(array $params) : void
    {
        if(\is_array($params[1]))
            return;

        $this->_setErrorMessage('is_array', $params[0], $params[0] . ' must be an array');
    }

    private function min_array_elements(array $params) : void
    {
        if(count($params[1]) >= $params[2])
            return;

        $this->_setErrorMessage('min_array_elements', $params[0], $params[0] . ' must contain at least ' . $params[2] . ' elements');
    }

    private function max_array_elements(array $params) : void
    {
        if(count($params[1]) <= $params[2])
            return;

        $this->_setErrorMessage('max_array_elements', $params[0], $params[0] . ' must contain at no more than ' . $params[2] . ' elements');
    }

    private function is_file(array $params) : void
    {
        if(is_object($params[1]) && get_class($params[1]) === 'Dren\UploadedFile' && !$params[1]->hasError())
            return;

        $this->_setErrorMessage('is_file', $params[0], $params[0] . ' must be a valid file');
    }

    private function max_file_size(array $params) : void
    {
        $uploadedFileSizeInKB = $params[1]->getSize() * 0.001;
        $maxFileSize = (int)$params[2];

        if($uploadedFileSizeInKB <= $maxFileSize)
            return;

        $this->_setErrorMessage('max_file_size', $params[0], $params[0] . ' must be less than or equal to the following size: ' . $params[2] . 'kb');
    }

    private function mimetypes(array $params) : void
    {
        // if we've made it to this function, we already know the file is of an overall allowable mimetype (is of one
        // of the values provided within the config file for allowed_file_upload_mimes), otherwise it would contain an
        // error...so this validation rule is used for insuring that specific files are of specific mimes
        $allowableMimesForThisFile = [];
        for($i = 2; $i < count($params); $i++)
            $allowableMimesForThisFile[] = $params[$i];

        if(in_array($params[1]->getMime(), $allowableMimesForThisFile))
            return;

        $this->_setErrorMessage('mimetypes', $params[0], $params[0] . ' must be one of the following file types: ' . implode(',', $allowableMimesForThisFile));
    }

    private function in(array $params) : void
    {
        $allowableValues = [];
        for($i = 2; $i < count($params); $i++)
            $allowableValues[] = $params[$i];

        if(in_array($params[1], $allowableValues))
            return;

        $this->_setErrorMessage('in', $params[0], $params[0] . ' must be one of the following values: ' . implode(',', $allowableValues));
    }

}