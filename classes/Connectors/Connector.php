<?php
namespace Connectors;

interface Connector
{
    public function connect();
    public function getFile($localfile, $remotefile);
    public function getFilesInDir($remote_dir);
    public function createDir($dir, $check = false, $isMain = false);
    public function uploadFile($file, $uploaddir, $check = false, $mode = 1);
    public function moveFile($srcfile, $dest);
    public function checkDir($dir);
    public function checkFile($file);
    public function deleteDir($dir);
    public function deleteFile($file);
}
