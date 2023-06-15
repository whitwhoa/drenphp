<?php

namespace Dren;

use Exception;

class UploadedFile
{
    private string $clientName = '';
    private string $clientMime = '';
    private string $tmpPath = '';
    private string $errorMessage = '';
    private int $size = 0;
    private string $serverMime = '';
    private array $allowableMimes = [];
    private string $formName = '';


    public function __construct(array $am, string $fn, string $cn, string $cm, string $tp, int $ec, int $s)
    {
        $this->formName = $fn;
        $this->clientName = $cn; // for info purposes, we don't use this to create a file on our server
        $this->clientMime = $cm; // for info purposes, we don't use this to deduce actual mime
        $this->tmpPath = $tp;
        $this->size = $s;
        $this->allowableMimes = $am;

        if($ec === UPLOAD_ERR_INI_SIZE)
            $this->errorMessage = 'UPLOAD_ERR_INI_SIZE';
        elseif($ec === UPLOAD_ERR_FORM_SIZE)
            $this->errorMessage = 'UPLOAD_ERR_FORM_SIZE';
        elseif($ec === UPLOAD_ERR_PARTIAL)
            $this->errorMessage = 'UPLOAD_ERR_PARTIAL';
        elseif($ec === UPLOAD_ERR_NO_FILE)
            $this->errorMessage = 'UPLOAD_ERR_NO_FILE';
        elseif($ec === UPLOAD_ERR_NO_TMP_DIR)
            $this->errorMessage = 'UPLOAD_ERR_NO_TMP_DIR';
        elseif($ec === UPLOAD_ERR_CANT_WRITE)
            $this->errorMessage = 'UPLOAD_ERR_CANT_WRITE';
        elseif($ec === UPLOAD_ERR_EXTENSION)
            $this->errorMessage = 'UPLOAD_ERR_EXTENSION';

        if($this->errorMessage !== '')
            return;

        $this->serverMime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $this->tmpPath);

        if(!array_key_exists($this->serverMime, $this->allowableMimes))
        {
            $this->errorMessage = 'UPLOAD_ERR_UNSUPPORTED_MIME';
            return;
        }



    }

    public function getFormName() : string
    {
        return $this->formName;
    }

    public function getClientName() : string
    {
        return $this->clientName;
    }

    public function getClientMime() : string
    {
        return $this->clientMime;
    }

    public function getSize() : int
    {
        return $this->size;
    }

    public function hasError() : bool
    {
        return $this->errorMessage !== '';
    }

    public function getErrorMessage() : string
    {
        return $this->errorMessage;
    }

    public function getMime() : string
    {
        return $this->serverMime;
    }

    public function getExt() : string
    {
        return $this->allowableMimes[$this->getMime()];
    }

    public function storeAs(string $path, string $filename) : void
    {
        if(!is_dir($path))
            throw new Exception('Attempting to save file to directory which does not exist');

        if(!str_ends_with($path, '/'))
            $path .= '/';

        $fullPath = $path . $filename;

        rename($this->tmpPath, $fullPath);

        chmod($fullPath, 0664);
    }
}