<?php
declare(strict_types=1);

namespace Dren;

use Exception;

abstract class FormDataValidator
{
    abstract protected function setRules() : void;
    protected Request $request;
    protected SessionManager $sessionManager;


    /**
     * @var array<string, string[]|string|callable[]>
     */
    protected array $rules;

    /** @var array<string, mixed> */
    private array $requestData;

    /** @var array<string, string> */
    protected array $messages;

    private ValidationErrorContainer $errors;

    /** @var array<array{string, mixed, int}> */
    private array $expandedFields;

    /** @var array<int, string|array<string|callable>> */
    private array $methodChains;

    private string $valueNotPresentToken;

    protected bool $requireValidSession;

    protected bool $requireCsrfToken;

    public function __construct(Request $request, SessionManager $sessionManager)
    {
        $this->request = $request;
        $this->sessionManager = $sessionManager;
        $this->rules = [];
        $this->requestData = array_merge(
            (array)$request->getGetData(),
            (array)$request->getPostData(),
            $request->allFilesByFormName()
        );
        $this->messages = [];
        $this->errors = new ValidationErrorContainer();
        $this->expandedFields = [];
        $this->methodChains = [];
        $this->valueNotPresentToken = md5(mt_rand(1, 1000) . time());
        $this->requireValidSession = true;
        $this->requireCsrfToken = true;
    }

    public function getErrors() : ValidationErrorContainer
    {
        return $this->errors;
    }

    /*
    Validate functionality has been defaulted to exit validation for a field once the first
    failed method is hit. If you want to run every method regardless whether the
    previous method was successful, prefix 'run_all' to the method chain

    TODO: This method has become a near unmaintainable monstrosity which needs to be refactored to
        better reflect what's going on

    */
    public function validate() : bool
    {
        $this->setRules();

        /*********************************************************************************************
         * Default FormDataValidator rules
         ********************************************************************************************/

        if($this->requireValidSession)
        {
            // if you're submitting a form, you had better have a session token...
            $sessionVerificationRule = ['valid_session' => [function(array &$requestData, ValidationErrorContainer &$errors, bool &$fenceUp){
                $fenceUp = true;
                if(!$this->sessionManager->getSessionId())
                    $errors->add('invalid_session_token', "Session token was invalid or not provided");
            }]];

            $this->rules = array_merge($sessionVerificationRule, $this->rules);
        }

        if($this->requireCsrfToken)
        {
            // ...and a valid csrf token
            $csrfRule = ['csrf' => ['#required', function(array &$requestData, ValidationErrorContainer &$errors, bool &$fenceUp){
                $fenceUp = true;
                if($this->sessionManager->getCsrf() != $requestData['csrf'])
                    $errors->add('csrf', "CSRF token was invalid or not provided");
            }]];

            $this->rules = array_merge($csrfRule, $this->rules);
        }

        /*********************************************************************************************
         * END default FormDataValidator rules
         ********************************************************************************************/

        $this->expandFields();

        foreach($this->expandedFields as $ef)
        {
            // ef = [the.*.field.*.name, requestData, methodChain]

            $methodChain = $this->methodChains[$ef[2]];
            if(is_string($methodChain))
                $methodChain = explode('|', $methodChain);


            /*
             * "required_with" logic. When required_with rule is present, we need to check if one of the with fields (as
             * they are submitted as an array) is present and if it has a value, and if so, then we remove the
             * required_with rule from the chain and continue processing the following rules. If no "with" fields are
             * present, then we remove all validation rules and ignore the field
             */
            foreach($methodChain as $k => $mc)
            {
                if(!is_string($mc))
                    continue;

                ///////////////////////////////////////////////////////////////////////////////////
                /// REQUIRED_WITH
                ///////////////////////////////////////////////////////////////////////////////////
                if(str_contains($mc, 'required_with:'))
                {
                    $withs = explode(',', explode(':', $mc)[1]);

                    foreach($withs as $fieldName)
                    {
                        if(array_key_exists($fieldName, $this->requestData) && $this->has_value($this->requestData[$fieldName]))
                        {
                            $methodChain[$k] = 'required';
                            if(array_key_exists($ef[0] . '.required_with', $this->messages))
                                $this->messages[$ef[0] . '.required'] = $this->messages[$ef[0] . '.required_with'];
                            break 2;
                        }
                    }

                    $methodChain[$k] = 'nullable';
                }

                ///////////////////////////////////////////////////////////////////////////////////
                /// REQUIRED_WITHOUT
                ///////////////////////////////////////////////////////////////////////////////////
                if(str_contains($mc, 'required_without:'))
                {
                    $withOuts = explode(',', explode(':', $mc)[1]);

                    foreach($withOuts as $fieldName)
                    {
                        if(array_key_exists($fieldName, $this->requestData) && $this->has_value($this->requestData[$fieldName]))
                        {
                            $methodChain[$k] = 'nullable';
                            break 2;
                        }
                    }

                    $methodChain[$k] = 'required';

                    if(array_key_exists($ef[0] . '.required_without', $this->messages))
                        $this->messages[$ef[0] . '.required'] = $this->messages[$ef[0] . '.required_without'];
                }

                ///////////////////////////////////////////////////////////////////////////////////
                /// REQUIRED_WHEN
                ///////////////////////////////////////////////////////////////////////////////////
                if(str_contains($mc, 'required_when:'))
                {
                    $when = explode(',', explode(':', $mc)[1]);

                    if(array_key_exists($when[0], $this->requestData) &&
                        $this->has_value($this->requestData[$when[0]]) &&
                        $this->requestData[$when[0]] == $when[1])
                    {
                        $methodChain[$k] = 'required';

                        $customMessageKey = $this->findErrorMessage($ef[0], 'required_when');

                        if($customMessageKey !== null)
                        {
                            $updatedCustomMessageKey = str_replace('required_when', 'required', $customMessageKey);
                            $this->messages[$updatedCustomMessageKey] = $this->messages[$customMessageKey];
                        }

                        break;
                    }

                    $methodChain[$k] = 'nullable';
                }

            } // end of required* check loop

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

            /*
             * Insure all methods are run. By default, we stop processing methods on the chain after the first failure
             */
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

                        /**
                         * Callback obtains variable by reference and can manipulate its value, we need to tell
                         * phpstan to ignore this line because the value is not always false
                         * @phpstan-ignore-next-line
                         */
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

    private function expandFields() : void
    {
        // expand fields such that when array syntax provided .*.etc.*....
        // each individual element is expanded into its own "field"

        // [the.*.field.*.name, requestData, methodChain]
        // TODO: i don't thing that .* syntax is accurate anymore? ^^^

        foreach($this->rules as $field => $methodChain)
        {
            $this->methodChains[] = $methodChain;

            $keys = explode('.', $field);
            $index = 0;
            $this->expandFieldsRecursiveLoop($this->requestData, $keys, $index, []);
        }
    }

    /**
     * @param mixed $data
     * @param array<string> $keys
     * @param int $index
     * @param array<int|string> $path
     * @return void
     */
    private function expandFieldsRecursiveLoop(mixed $data, array &$keys, int &$index, array $path) : void
    {
        if ($index >= count($keys))
            return;

        $currentKey = $keys[$index];
        $path[] = $currentKey;

        if ($currentKey === '*')
        {
            $itemIndex = 0;

            //Logger::debug(var_export($data, true));

            if($data && \is_array($data))
            {
                foreach ($data as $dk => $item)
                {
//                    $path[count($path) - 1] = $itemIndex++;
//                    $this->expandFieldsProcessItem($item, $keys, $index, $path);
//                    $path[count($path) - 1] = '*';

                    $path[count($path) - 1] = $dk;
                    $this->expandFieldsProcessItem($item, $keys, $index, $path);
                    $path[count($path) - 1] = '*';

                }
            }
            else
            {
                $this->expandFieldsProcessItem($this->valueNotPresentToken, $keys, $index, $path);
            }

        }
        else if (isset($data[$currentKey]))
        {
            $this->expandFieldsProcessItem($data[$currentKey], $keys, $index, $path);
        }
        else
        {
            $this->expandFieldsProcessItem($this->valueNotPresentToken, $keys, $index, $path);
        }

        array_pop($path);
    }

    /**
     * @param mixed $item
     * @param array<string> $keys
     * @param int $index
     * @param array<int|string> $path
     * @return void
     */
    private function expandFieldsProcessItem(mixed $item, array &$keys, int &$index, array $path) : void
    {
        if ($index < count($keys) - 1)
        {
            $index++;
            $this->expandFieldsRecursiveLoop($item, $keys, $index, $path);
            $index--;
        }
        else
        {
            $firstPathElement = array_shift($path); // separate first element
            if($firstPathElement === null)
                return;

            $newPath = (string)$firstPathElement;
            foreach($path as $p)
                $newPath .= '[' . $p . ']';  // add remaining elements with brackets

            $this->expandedFields[] = [$newPath, $item, (count($this->methodChains) - 1)];
        }
    }

    private function getElementCountFromString(string $i) : int
    {
        $pattern = '/\[[^\]]*\]/';
        preg_match_all($pattern, $i, $matches);
        return count($matches[0]);
    }

    private function extractFieldsForErrorMessageMatching($i)
    {
        $pattern = '/^([^\[]+)|\[(.*?)\]/';

        preg_match_all($pattern, $i, $matches);

        $mergedResults = array_merge($matches[1], $matches[2]);
        $filteredResults = array_filter($mergedResults, function($value) {
            return $value !== '';
        });

        return array_values($filteredResults);
    }

    /**
     * return key which matches or null if no key match located
     *
     * @param string $field
     * @param string $method
     * @return string|null
     */
    private function findErrorMessage(string $field, string $method) : ?string
    {
        $extractedFields = $this->extractFieldsForErrorMessageMatching($field);

        if(count($extractedFields) <= 1)
        {
            $key = $field . '.' . $method;

            if(array_key_exists($key, $this->messages))
                return $key;

            return null;
        }

        foreach($this->messages as $k => $v)
        {
            $ma = explode('.', $k);

            $foundMatch = true;
            for($i = 0; $i < count($ma) - 1; $i++)
            {
                if(!(isset($extractedFields[$i]) && ($ma[$i] == $extractedFields[$i] || $ma[$i] === '*')))
                {
                    $foundMatch = false;
                    break;
                }
            }

            if($foundMatch && ($ma[count($ma) - 1] == $method))
                return $k;
        }

        return null;
    }

    private function setErrorMessage(string $method, string $field, string $defaultMsg) : void
    {
        $customMessageKey = $this->findErrorMessage($field, $method);

        if($customMessageKey !== null)
            $this->errors->add($field, $this->messages[$customMessageKey]);
        else
            $this->errors->add($field, $defaultMsg);
    }

    /**
     *
     *
     * @param mixed $var
     * @return bool
     */
    private function has_value(mixed &$var) : bool
    {
        // Countable values
        if
        (
            // if is an array, we must contain at least one element
            (is_array($var) && count($var) === 0)

            // if file upload then we must not contain any upload errors
            || (is_object($var) && get_class($var) === 'Dren\UploadedFile' && $var->hasError())

            // must be a value other than null
            || ($var === null)

            // must be a value other than empty string
            || ($var === '')

            // must be a value not equal to the valueNotPresentToken value
            || ($var === $this->valueNotPresentToken)
        )
        {
            return false;
        }

        return true;
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

    /**
     * @param array<int, mixed> $params
     */
    private function required(array $params) : void
    {
        if(!$this->has_value($params[1]))
            $this->setErrorMessage('required', $params[0], $params[0] . ' is required');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function numeric(array $params) : void
    {
        if(is_numeric($params[1]))
            return;

        $this->setErrorMessage('numeric', $params[0], $params[0] . ' must be numeric');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function alpha_numeric(array $params) : void
    {
        if(ctype_alnum($params[1]))
            return;

        $this->setErrorMessage('alpha_numeric', $params[0], $params[0] . ' must be alpha numeric');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function min_char(array $params) : void
    {
        $valString = (string)$params[1];
        if(strlen($valString) >= $params[2])
            return;

        $this->setErrorMessage('min_char', $params[0], $params[0] . ' must be at least ' . $params[2] . ' characters');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function max_char(array $params) : void
    {
        $valString = (string)$params[1];
        if(strlen($valString) <= $params[2])
            return;

        $this->setErrorMessage('max_char', $params[0], $params[0] . ' must be less than or equal to ' . $params[2] . ' characters');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function email(array $params) : void
    {
        if(filter_var($params[1], FILTER_VALIDATE_EMAIL) !== false)
            return;

        $this->setErrorMessage('email', $params[0], $params[0] . ' must be an email address');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function same(array $params) : void
    {
        if(key_exists($params[2], $this->requestData) && $this->requestData[$params[2]] == $params[1])
            return;

        $this->setErrorMessage('same', $params[0], $params[0] . ' must match ' . $params[2]);
    }

    //!!!!! NOTE !!!!!!!
    // Some of these queries might look like sql injection vulnerabilities at first glance, however, note where user input
    // is handled, no user input is ever concatenated with the query string, only values provided by the application code
    // itself, user input is still always parameterized

    /**
     * @param array<int, mixed> $params
     * @throws Exception
     */
    private function unique(array $params) : void
    {
        if(!App::get()->getDb()->query("SELECT '1' FROM " . $params[2] . " WHERE " . $params[3] . " = ?", [$params[1]])->singleAsObj()->exec())
            return;

        $this->setErrorMessage('unique', $params[0], $params[0] . ' must be unique');
    }

    /**
     * @param array<int, mixed> $params
     * @throws Exception
     */
    private function exists(array $params) : void
    {
        if(App::get()->getDb()->query("SELECT '1' FROM " . $params[2] . " WHERE " . $params[3] . " = ?", [$params[1]])->singleAsObj()->exec())
            return;

        $this->setErrorMessage('exists', $params[0], $params[0] . ' does not exist');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function is_array(array $params) : void
    {
        if(\is_array($params[1]))
            return;

        $this->setErrorMessage('is_array', $params[0], $params[0] . ' must be an array');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function min_array_elements(array $params) : void
    {
        if(count($params[1]) >= $params[2])
            return;

        $this->setErrorMessage('min_array_elements', $params[0], $params[0] . ' must contain at least ' . $params[2] . ' elements');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function max_array_elements(array $params) : void
    {
        if(count($params[1]) <= $params[2])
            return;

        $this->setErrorMessage('max_array_elements', $params[0], $params[0] . ' must contain at no more than ' . $params[2] . ' elements');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function is_file(array $params) : void
    {
        if(is_object($params[1]) && get_class($params[1]) === 'Dren\UploadedFile' && !$params[1]->hasError())
            return;

        $this->setErrorMessage('is_file', $params[0], $params[0] . ' must be a valid file');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function max_file_size(array $params) : void
    {
        $uploadedFileSizeInKB = $params[1]->getSize() * 0.001;
        $maxFileSize = (int)$params[2];

        if($uploadedFileSizeInKB <= $maxFileSize)
            return;

        $this->setErrorMessage('max_file_size', $params[0], $params[0] . ' must be less than or equal to the following size: ' . $params[2] . 'kb');
    }

    /**
     * @param array<int, mixed> $params
     */
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

        $this->setErrorMessage('mimetypes', $params[0], $params[0] . ' must be one of the following file types: ' . implode(',', $allowableMimesForThisFile));
    }

    /**
     * @param array<int, mixed> $params
     */
    private function in(array $params) : void
    {
        $allowableValues = [];
        for($i = 2; $i < count($params); $i++)
            $allowableValues[] = $params[$i];

        if(in_array($params[1], $allowableValues))
            return;

        $this->setErrorMessage('in', $params[0], $params[0] . ' must be one of the following values: ' . implode(',', $allowableValues));
    }

    /**
     * @param array<int, mixed> $params
     */
    private function url(array $params) : void
    {
        $url = $params[1];

        if($url !== '' && $url !== null)
        {
            $isValidUrl = filter_var($url, FILTER_VALIDATE_URL);

            $hasValidTld = preg_match('/\.[a-z]{2,}$/i', parse_url($url, PHP_URL_HOST));

            if ($isValidUrl && str_starts_with($url, 'https://') && $hasValidTld)
                return;
        }

        $this->setErrorMessage('url', $params[0], $params[0] . ' must be a valid URL starting with https:// and containing a top-level domain');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function is_date(array $params) : void
    {
        if(!strtotime($params[1]))
            $this->setErrorMessage('is_date', $params[0], $params[0] . ' must be a valid date');
    }

    /**
     * @param array<int, mixed> $params
     */
    private function state_abbr(array $params) : void
    {
        if(!array_key_exists(strtoupper($params[1]), get_states()))
            $this->setErrorMessage('state_abbr', $params[0], $params[0] . ' must be a valid state abbreviation');
    }

    /**
     * Insure date value of $params[1] is greater than date value of $params[2]
     *
     * if $params[2] is not a key within $this->requestData, attempt to parse it as date string and run the comparison
     * using that value (allows user to do comparison against static date)
     *
     * @param array<int, mixed> $params
     */
    private function date_greater_than(array $params) : void
    {
        if(strtotime($params[1]))
        {
            $compVal = null;

            if(!array_key_exists($params[2], $this->requestData))
            {
                if(strtotime($params[2]) !== false)
                    $compVal = strtotime($params[2]);
            }
            else
            {
                if(strtotime($this->requestData[$params[2]]) !== false)
                    $compVal = strtotime($this->requestData[$params[2]]);
            }

            if($compVal !== null)
                if(strtotime($params[1]) > $compVal)
                    return;
        }

        $this->setErrorMessage('date_greater_than', $params[0], $params[0] . ' must be a date greater than the date provided for: ' . $params[2]);
    }

    /**
     *
     *
     * @param array $params
     * @return void
     */
    private function date_less_than(array $params) : void
    {
        if(strtotime($params[1]))
        {
            $compVal = null;

            if(!array_key_exists($params[2], $this->requestData))
            {
                if(strtotime($params[2]) !== false)
                    $compVal = strtotime($params[2]);
            }
            else
            {
                if(strtotime($this->requestData[$params[2]]) !== false)
                    $compVal = strtotime($this->requestData[$params[2]]);
            }

            if($compVal !== null)
                if(strtotime($params[1]) < $compVal)
                    return;
        }

        $this->setErrorMessage('date_less_than', $params[0], $params[0] . ' must be a date less than the date provided for: ' . $params[2]);
    }

    //TODO: all other comparison functions need functionality as implemented in the number_greater_than and number_less_than functions
    // that allows for comparing against form elements at the same array level.

    /**
     *
     * @param array<int, mixed> $params
     */
    private function number_greater_than(array $params) : void
    {
        if(is_numeric($params[1]))
        {
            $compVal = null;

            // user provided ,* meaning they want to compare this value with the value of the element at the same array index level
            if(isset($params[3]) && $params[3] === '*')
            {
                $keys = $this->extractFieldsForErrorMessageMatching($params[0]);

                $keys[count($keys) - 1] = $params[2];

                $currentElement = $this->requestData;

                $found = true;
                foreach($keys as $key)
                {
                    if(isset($currentElement[$key]))
                        $currentElement = $currentElement[$key];
                    else
                        $found = false;
                }

                if($found && $params[1] > $currentElement)
                    return;
            }
            else
            {
                if(!array_key_exists($params[2], $this->requestData))
                {
                    if(is_numeric($params[2]))
                        $compVal = $params[2];
                }
                else
                {
                    if(is_numeric($this->requestData[$params[2]]))
                        $compVal = $this->requestData[$params[2]];
                }

                if($compVal !== null)
                    if($params[1] > $compVal)
                        return;
            }


        }

        $this->setErrorMessage('number_greater_than', $params[0], $params[0] . ' must be a number greater than the number provided for: ' . $params[2]);
    }

    /**
     *
     * @param array<int, mixed> $params
     */
    private function number_less_than(array $params) : void
    {
        if(is_numeric($params[1]))
        {
            $compVal = null;

            // user provided ,* meaning they want to compare this value with the value of the element at the same array index level
            if(isset($params[3]) && $params[3] === '*')
            {
                $keys = $this->extractFieldsForErrorMessageMatching($params[0]);

                $keys[count($keys) - 1] = $params[2];

                $currentElement = $this->requestData;

                $found = true;
                foreach($keys as $key)
                {
                    if(isset($currentElement[$key]))
                        $currentElement = $currentElement[$key];
                    else
                        $found = false;
                }

                if($found && $params[1] < $currentElement)
                    return;
            }
            else
            {
                if(!array_key_exists($params[2], $this->requestData))
                {
                    if(is_numeric($params[2]))
                        $compVal = $params[2];
                }
                else
                {
                    if(is_numeric($this->requestData[$params[2]]))
                        $compVal = $this->requestData[$params[2]];
                }

                if($compVal !== null)
                    if($params[1] < $compVal)
                        return;
            }


        }

        $this->setErrorMessage('number_less_than', $params[0], $params[0] . ' must be a number less than the number provided for: ' . $params[2]);
    }

    /**
     *
     * @param mixed $v
     * @return bool
     */
    private function _is_integer(mixed $v) : bool
    {
        if((is_numeric($v)) && (!str_contains($v, '.')) && ((float)$v == (int)$v))
            return true;

        return false;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function is_integer(array $params) : void
    {
        if(!$this->_is_integer($params[1]))
            $this->setErrorMessage('is_integer', $params[0], $params[0] . ' must be an integer');
    }

}