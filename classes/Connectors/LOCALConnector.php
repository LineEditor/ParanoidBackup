<?php

namespace Connectors;

/**
*  Connector to local filesystem
*/
class LOCALConnector extends BaseConnector
{
    private $conn;
    
    public function getFilePerms($file)
    {
        $perms = fileperms($file);

        switch ($perms & 0xF000) 
        {
            case 0xC000: // socket
                $info = 's';
                break;
            case 0xA000: // symbolic link
                $info = 'l';
                break;
            case 0x8000: // common file
                $info = 'r';
                break;
            case 0x6000: // file of block device
                $info = 'b';
                break;
            case 0x4000: // directory
                $info = 'd';
                break;
            case 0x2000: // file of symbolic device
                $info = 'c';
                break;
            case 0x1000: // FIFO channel
                $info = 'p';
                break;
            default: // unknown
                $info = 'u';
        }

        // owner
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) :
                                      (($perms & 0x0800) ? 'S' : '-'));
    
        // group
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) :
                                      (($perms & 0x0400) ? 'S' : '-'));

        // others
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) :
                                      (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }

    public function getFile($localfile, $remotefile)
    {
        $this->logger->write("copying {$remotefile} to {$localfile}", 3);
        copy($remotefile, $localfile); 
    }
    
    public function getFilesInDir($remote_dir)
    {
        $files = array();
        
        if ($handle = opendir($remote_dir))
        {
            while (false !== ($file = readdir($handle)))
            {
                $fullpath =  $remote_dir.'/'.$file;

                $item = array();
                
                if (($file=='.')  || ($file=='..'))
                    continue;

                $filedata = stat($fullpath);
                
                $item['d'] = is_dir($fullpath)?1:0;    
                $item['name'] = $file;                
                $item['rights'] = $this->getFilePerms($fullpath);
                $item['nlink'] = $filedata['nlink']; 
                $item['user']   = $filedata['uid'];  
                $item['group']  = $filedata['gid'];   
                $item['size']   = $filedata['size'];
                $item['fulltime'] = $filedata['mtime'];
                
                $files[] = $item;
            }
            closedir($handle);

        }

        return $files;
    }

    public function connect()
    {
        if (!isset($this->config['remote_dir']))
        {
            throw new \Exception("remote_dir must be defined if LocalConnector used");
        }

        if (!is_dir($this->config['remote_dir']))
        {
            throw new \Exception("remote_dir ".$this->config['remote_dir']." not exists");
        }

    }

    public function checkDir($dir)
    {
        return is_dir($dir);
    }

    public function checkFile($file)
    {
        return file_exists($file);
    }

    function createDir($dir, $check = false, $isMain = false)
    {
        $srcdir = trim($dir, '/');
        $parts = preg_split("/\//", $srcdir);

        $this->logger->write("createDir dir={$dir}, cur_dir=".$this->cur_dir);

        if ($isMain)
        {
            $first = $parts[0];
            if (strpos($first, ':') !== 0)
            {
                $fp = $first;
                $six = 1;
            } else
            {
                $fp = '/';
                $six = 0;
            }
        }
        else
        {
            $fp = $this->cur_dir;
            $six = 0;
        }

        $dlm = '/';
        for ($i=$six; $i < count($parts); $i++)
        {
            $p = $parts[$i];
            $fp .= $dlm.trim($p);

            if ($this->checkDir($fp))
            {
                continue;
            }

            mkdir($fp);
        }
    }

    public function uploadFile($file, $uploaddir, $check = false, $mode = 1)
    {
        if ($check && !file_exists($file))
            return false;

        $dest = $this->cur_dir . $uploaddir . '/' . basename($file);
        $this->logger->write("copying {$file} to {$dest}", 3);

        copy($file, $dest);
    }

    public function moveFile($srcfile, $dest)
    {
        $this->logger->write("moving {$srcfile} to {$dest}", 3);
        rename($srcfile, $dest);
    }

    public function deleteDir($dir)
    {
        $this->logger->write("deleting {$dir}", 3);
        rmdir($dir);
    }

    public function deleteFile($file)
    {
        $this->logger->write("delete {$file}", 3);
        unlink($file);
    }
}

?>
