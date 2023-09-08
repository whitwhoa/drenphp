<?php
declare(strict_types=1);


namespace Dren;


use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;


class ViewCompiler
{
    /** @var array<string, string> */
    private array $views;
    private ?SessionManager $sessionManager;


    public function __construct(string $privateDir, ?SessionManager $sessionManager = null)
    {
        $this->views = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($privateDir . '/views')) as $file) 
        {
            if ($file->isDir())
                continue;

            $path = $file->getPathname();

            $this->views[ltrim(str_replace(
                DIRECTORY_SEPARATOR, 
                '.',
                str_replace(
                    '.php', 
                    '',
                    explode($privateDir . '/views', $file->getPathname())[1])
                ), 
                '.'
                )
            ] = $path;
        }

        $this->sessionManager = $sessionManager;
    }


    /**
     *
     *
     * @param string $view
     * @param array<string, mixed> $data
     * @return string
     * @throws Exception
     */
    public function compile(string $view, array $data = []) : string
    {
        if(!array_key_exists($view, $this->views))
            throw new Exception('Given view name does not exist');

        if(array_key_exists('errors', $data))
            throw new Exception('Cannot use "errors" as data key for view. It is a reserved name.');

        if(array_key_exists('old', $data))
            throw new Exception('Cannot use "old" as data key for view. It is a reserved name.');

        if(array_key_exists('sessionManager', $data))
            throw new Exception('Cannot use "sessionManager" as data key for view. It is a reserved name.');

        if($this->sessionManager !== null)
        {
            // check if sessionManager contains validation errors, if it does...instantiate a ValidationErrorContainer
            $data['errors'] = new ValidationErrorContainer();
            if($this->sessionManager->getFlash('errors'))
                $data['errors']->import((array)$this->sessionManager->getFlash('errors'));
            $data['old'] = $this->sessionManager->getFlash('old');
            $data['sessionManager'] = $this->sessionManager; // add this so we can call getCsrf() when needed
        }

        extract($data);

        start_section();

        include $this->views[$view];

        if(isset($view_extends) && array_key_exists($view_extends, $this->views))
            include $this->views[$view_extends];

        return end_section();
    }

}