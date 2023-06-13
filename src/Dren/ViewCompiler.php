<?php


namespace Dren;


use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;


class ViewCompiler
{
    private $views = [];
    private $sessionManager;


    public function __construct($privateDir, $sessionManager)
    {
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
     * @param array $data
     * @return string
     * @throws Exception
     */
    public function compile(string $view, array $data = []) : string
    {
        if(!array_key_exists($view, $this->views))
            throw new Exception('Given view name does not exist');

        // check if sessionManager contains validation errors, if it does...instantiate a ValidationErrorContainer
        $data['errors'] = new ValidationErrorContainer();
        if($this->sessionManager && $this->sessionManager->get('errors'))
            $data['errors']->import((array)$this->sessionManager->get('errors'));

        $data['old'] = ($this->sessionManager && $this->sessionManager->get('old')) ? $this->sessionManager->get('old') : NULL;

        extract($data);

        start_section();

        include $this->views[$view];

        if(isset($view_extends) && array_key_exists($view_extends, $this->views))
            include $this->views[$view_extends];

        return end_section();
    }

}