<?php


namespace Dren;


use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use Dren\SessionManager as SM;


class ViewCompiler
{

    private $views = null;


    public function __construct()
    {
        foreach (new RecursiveIteratorIterator(
                     new RecursiveDirectoryIterator(App::$privateDir . '/views')) as $file) {

            if ($file->isDir()){
                continue;
            }

            $path = $file->getPathname();
            $this->views[ltrim(str_replace(DIRECTORY_SEPARATOR, '.',
                str_replace('.php', '',
                    explode(App::$privateDir . '/views', $file->getPathname())[1])), '.')] = $path;

        }
    }


    /**
     *
     *
     * @param string $view
     * @param array $data
     * @return string
     * @throws \Exception
     */
    public function compile(string $view, array $data = []) : string
    {
        if(!array_key_exists($view, $this->views)){
            throw new \Exception('Given view name does not exist');
        }

        $data['errors'] = (App::$sm && App::$sm->get('errors')) ? App::$sm->get('errors') : NULL;
        $data['old'] = (App::$sm && App::$sm->get('old')) ? App::$sm->get('old') : NULL;

        extract($data);

        start_section();
        include $this->views[$view];
        if(isset($view_extends) && array_key_exists($view_extends, $this->views)){
            include $this->views[$view_extends];
        }
        return end_section();

    }

}