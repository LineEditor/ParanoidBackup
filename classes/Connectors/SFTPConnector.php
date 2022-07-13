<?php

namespace Connectors;

use phpseclib3\Net\SFTP;

/**
*  Connector to SFTP
*/
class SFTPConnector extends BaseConnector
{
    private $sftp;
    private $conn;
    private $homedir;
    
    public function connect()
    {
        $config = $this->config;
        $this->sftp = new SFTP($config['host']);
        if (!$this->sftp->login($config['login'], $config['password'])) 
        {
            throw new \Exception("SFTP: Cannot connect  to ".$config['host']);
        }
        $this->homedir = $this->sftp->pwd();
                            
        $this->logger->write("Connected to SFTP ".$config['host'], 2); 
    }

    public function setStartDir($dir, $check = true)
    {
       $this->start_dir = trim($dir,'/');
       $this->cur_dir  = $this->start_dir;   

     
       if ($check && !$this->checkDir($this->cur_dir))
           $this->createDir($this->cur_dir, true, 1); 
    }
    
    public function checkDir($dir)
    {
        $dir = ltrim($dir, '/');
        return $this->sftp->is_dir($this->homedir.'/'.$dir);
    }

    public function checkFile($file)
    {
        return $this->sftp->file_exists($this->homedir.'/'.$file);
    }

    public function getFile($localfile, $remotefile)
    {
        $remotefile = trim($remotefile, '/');
        $remotefile =  $this->homedir. '/'. $remotefile;
        
        $this->logger->write("SFT copy {$remotefile} | {$localfile}", 3);

        $this->sftp->get($remotefile, $localfile);
    }

    function createDir($dir, $check = false, $isMain = 0)
    {
        //If called from setStartDir, isMain = 1

        if ($isMain)
        {
            if(!$this->sftp->is_dir($this->homedir.'/'.$dir))
            {
                $this->logger->write("Creating {$dir}", 3);
                $this->sftp->mkdir($this->homedir.'/'.$dir);
                return;
            }
        }
        
        $dir = ltrim($dir, '/');
        $parts = preg_split("/\//", $dir);
        $curd = $this->homedir.'/'.$this->cur_dir;

        foreach($parts as $p)
        {
            $this->logger->write("p={$p}", 3);
            $curd .= '/'.$p;
            if(!$this->sftp->is_dir($curd))
            {
                $this->logger->write("Creating {$curd}", 3);
                $this->sftp->mkdir($curd);
            }
        }
    }
    
    public function uploadFile($file, $uploaddir, $check = false, $mode = 1)
    {
        if ($check && !file_exists($file))
            return false;
         
        $uploaddir  = trim($uploaddir,'/');
        
        $dlm = $uploaddir?'/':'';
        
        $newfn = $this->homedir.'/'.$this->start_dir.'/'.$uploaddir.$dlm.basename($file);

        $r = $this->sftp->put($newfn, $file, SFTP::SOURCE_LOCAL_FILE);

        if (!$r)
            $this->logger->write("Error uploading {$file} to {$newfn}", 1);
        else
            $this->logger->write("File {$file} uploaded to {$newfn}", 3);
    }         
    
    public function getFilePerms($perms)
    {
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
    
    public function getFilesInDir($remote_dir)
    {

        $remote_dir = trim($remote_dir,'/');
        //$this->sftp->chdir('/');
        $this->sftp->chdir($this->homedir.'/'.$remote_dir);
        $files0 = $this->sftp->rawlist();
        
        $files = array();
        foreach ($files0 as $f => $v)
        {
           $item = array();
               
           if (($f=='.')  || ($f=='..'))
                continue;

           $item['name'] = $f;
           $item['d'] = $v['type']==2?1:0;    
           $item['size']   = $v['size'];     
           $item['fulltime'] = $v['mtime'];
           $item['rights'] = $this->getFilePerms($v['mode']);
           $item['nlink'] = 1; 
           $item['user']   = $v['uid'];  
           $item['group']  = $v['gid'];   
                
           $files[] = $item;
        }                     

        return $files;
    }

    public function deleteDir($dir)
    {
        if ($this->sftp->rmdir($this->homedir.'/'.$dir))
        {
            $this->logger->write("deleting remote dir {$dir} \n", 3);
        }
        else
        {
            $this->logger->write("error deleting remote dir {$dir} \n", 1);
        }
    }

    public function deleteFile($fn)
    {
        $this->logger->write("deleting remote file {$fn} \n", 3);
        $this->sftp->delete($this->homedir.'/'.$fn);
    }

    public function moveFile($srcfile, $dest)
    {
        $this->sftp->delete($this->homedir.'/'.$dest);

        if ($this->sftp->rename($this->homedir.'/'.$srcfile, $this->homedir.'/'.$dest))
        {
            $this->logger->write("moving file {$srcfile} to {$dest} \n", 3);
        }
        else
        {
            $this->logger->write("error moving file {$srcfile} to {$dest} \n", 3);
        }
    }
}

?>