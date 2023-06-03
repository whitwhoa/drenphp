<?php

namespace Dren;



use Exception;
use Dren\Exceptions\Forbidden;
use Dren\Exceptions\NotFound;
use Dren\Exceptions\Unauthorized;
use Dren\Exceptions\UnprocessableEntity;


class Main
{
    /**
     *
     * @param string $privateDirectory
     */
    public function __construct(string $privateDirectory)
    {
        try{


            // Initialize all classes that are required throughout the application
            App::initialize($privateDirectory);


            // Execute each middleware. If the return type is Dren\Response, send the response
            foreach(App::$router->getMiddleware() as $m){
                $middlewareResponse = (new $m())->handle();
                if(gettype($middlewareResponse) === 'object' &&
                    get_class($middlewareResponse) === 'Dren\Response'){
                    $middlewareResponse->send();
                    return;
                }
            }


            // Execute request validator. If provided and validate() returns false,
            // return a redirect or json response depending on the set failureResponseType
            $rv = App::$router->getRequestValidator();
            if($rv !== ''){
                $rv = new $rv(App::$request);
                if(!$rv->validate()){

                    switch($rv->getFailureResponseType()){
                        case 'redirect':
                            App::$sm->flashSave('errors', $rv->getErrors());
                            App::$sm->flashSave('old', $rv->getParams());
                            (new Response())->redirect(App::$request->getReferrer())->send();
                            return;
                    }

                }
            }


            // Execute the given method for the given controller class and send it's
            // response (as every controller method should return a Response object)
            $class = App::$router->getControllerClassName();
            $method = App::$router->getControllerClassMethodName();
            (new $class(App::$request))->$method()->send();

        }
        catch(Forbidden|NotFound|Unauthorized|UnprocessableEntity $e){

            (new Response())->html(App::$vc->compile('errors.' . $e->getCode(),
                ['detailedMessage' => $e->getMessage()]))->send();

        }
        catch (Exception $e){

            $this->displayGenericErrorMessage($e);

        }

    }


    /**
     * Display a raw stack trace if display_errors is true, or a generic
     * one liner if display_errors is false
     *
     * @param \Exception $e
     */
    private function displayGenericErrorMessage(\Exception $e) : void
    {

        // If we made it here something is severely incorrect
        if(App::$config->display_errors){

            echo '<pre>';
            echo $e->getMessage() . "\n";
            echo $e->getTraceAsString();
            echo '<pre>';
            exit;

        }
        die('An error has occurred and we are unable to process your request');

    }

}