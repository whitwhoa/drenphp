<?php

namespace Dren;

class FileUpload
{
    private string $clientName = '';
    private string $clientMime = '';
    private string $tmpPath = '';
    private string $errorMessage = '';
    private int $size = 0;
    private string $serverMime = '';
    private array $allowableMimes = [];

    public function __construct(string $cn, string $cm, string $tp, int $ec, int $s)
    {
        $this->clientName = $cn; // for info purposes, we don't use this to create a file on our server
        $this->clientMime = $cm; // for info purposes, we don't use this to deduce actual mime
        $this->tmpPath = $tp;
        $this->size = $s;
        $this->allowableMimes = App::get()->getConfig()->allowed_file_upload_mimes;

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
}