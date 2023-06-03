<?php


// TODO: this is broken until we rewrite to work without third party valitron library

namespace Dren;





class RequestValidator
{


    protected $request;

    protected $failureResponseType = 'redirect'; // or json

    protected $valitron;

    private $params;
    private $errors = [];

    public function __construct(Request $request)
    {

        $this->request = $request;
        $this->params = array_merge(
            ($request->getGetData() ? (array)$request->getGetData() : []),
            ($request->getPostData() ? (array)$request->getPostData() : [])
        );
        $this->valitron = new Validator($this->params);

    }

    public function getErrors() : array
    {
        return $this->errors;
    }

    public function getParams() : array
    {
        return $this->params;
    }

    public function getFailureResponseType() : string
    {
        return $this->failureResponseType;
    }

    /**
     * Perform validation.
     *
     * @return bool
     */
    public function validate() : bool
    {

        $this->rules();

        if($this->valitron->validate()){
            return true;
        }

        $this->errors = $this->valitron->errors();
        return false;

    }

}