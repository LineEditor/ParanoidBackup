<?php

namespace Connectors;
/**
*  Connector to FTP
*/
class FTPConnector extends BaseConnector
{
    private $conn;
    private $work_mode;
    private $cur_mon_n;
    private $cur_year;
    private $prev_year;
            
    function __construct($logger, $config, $after = '')
    {
        parent::__construct($logger, $config, $after);

        $this->cur_mon_n = intval(date("m"));    
        $this->cur_year = intval(date("Y"));    
        $this->prev_year = $this->cur_year - 1;
    }
    
    public function connect()
    {

        $config = $this->config;

        $after = $this->after_name;

        if (!function_exists('ftp_login'))
            throw new \Exception("FTP module required. Please, edit \"Dynamic Extensions\" section in ".php_ini_loaded_file());


        if (!array_key_exists('host', $config))
            throw new \Exception("FTP: host not defined ($after)");

        if (!array_key_exists('login', $config))
            throw new \Exception("FTP: login not defined ($after)");

        if (!array_key_exists('password', $config))
            throw new \Exception("FTP: password not defined ($after)");

        if (!array_key_exists('timeout', $config))
            $config['timeout'] = 100;
                                            
        $parts = preg_split("/\:/", $config['host']);
        
        if (count($parts) > 1)
        {
            $config['host'] = $parts[0];
            $config['port'] = $parts[1];
        }
        else
        {
            if (!array_key_exists('port', $config))
                $config['port'] = 21;
        }
        
        $this->conn  =  ftp_connect($config['host'], $config['port'], $config['timeout']);
       
        if (!$this->conn || !@ftp_login($this->conn, $config['login'], $config['password']))
        {
            throw new \Exception("FTP: Cannot connect to ".$config['host']);
        }
        
        if (array_key_exists('passive', $config) && $config['passive'])
            ftp_pasv($this->conn, true);
            
        $this->logger->write("Connected to FTP ".$config['host'], 2);
        $this->logger->write("start_dir=".$this->start_dir, 2);
    }

    public function setStartDir($dir, $check = true)
    {
        $this->start_dir = trim($dir, '/');
        $this->cur_dir   = $this->start_dir;
        //$this->root_dir  = ftp_pwd($this->conn);   
        $this->root_dir  = '/';   
        $this->logger->write("create StartDir.  start_dir={$this->start_dir}, cur_dir={$this->cur_dir}, root_dir={$this->root_dir}", 3);
        $this->createDir($this->start_dir, true, 1);    
    }
    
    public function close()
    {
        ftp_close($this->conn);
        parent::close();
    }
    
    public function getFile($localfile, $remotefile)
    {
        $this->logger->write("copying {$remotefile} to {$localfile}", 3);
        ftp_get($this->conn, $localfile, $remotefile, FTP_BINARY); 
    }

    public function getFilesInDir($remote_dir)
    {
        $lines = ftp_rawlist($this->conn, $remote_dir);    

        if ($lines===false)
            return false;
            
        $result = array();
        foreach($lines as $line)    
        {
            $item = array();
            
            
            list($item['rights'], $item['nlink'], $item['user'], $item['group'], $item['size'], 
            $month, $day, $year, $item['name']) =  preg_split("/\s+/", $line, 9);
            
            $item['d'] = $item['rights'][0]=='d'?1:0;
            
            
            $tm = '';
     
            if (stripos($year, ':') > 0) //this is not year, but time
            {
               $tm = ' '.$year;  
               $file_mon_n = $this->month_arr[$month];
                        
               if ($file_mon_n <= $this->cur_mon_n)
                   $year = $this->cur_year;
               else
                   $year = $this->prev_year;
             }
             $item['fulltime'] =  strtotime($month.' '.$day.' '.$year.$tm);
             $result[] = $item;
        }
        
        return $result;
    }    
    
    
    function createDir($dir, $check = false, $isMain = false)
    {
        if ($isMain)
        {
            $dir = '/'.trim($dir, '/');
            if (!@ftp_chdir($this->conn, $dir))
            {
                $this->logger->write("CreateDir {$dir}", 3);            
                @ftp_chdir($this->conn, $this->root_dir);
                ftp_mkdir($this->conn, $dir);
            }
            return;    
        }
        
        $srcdir = trim($dir, '/');
     
        @ftp_chdir($this->conn, $this->root_dir.'/'.$this->start_dir);
      
        $parts = preg_split("/\//", $srcdir);
        $this->logger->write("createDir {$srcdir}, cur_dir={$this->cur_dir}", 3);

        $ix = 0;
        foreach($parts as $p)
        {
             if (!@ftp_chdir($this->conn, $p))
             {
                 ftp_mkdir($this->conn, $p);
                 ftp_chdir($this->conn, $p);
             }
             $ix++;
        }
    }
        
    public function uploadFile($file, $uploaddir, $check = false, $mode = 1)
    {
        if ($check && !file_exists($file))
            return false;
         
        //ftp_chdir($this->conn, $this->root_dir); 
        $uploaddir  = trim($uploaddir);
        
        $dlm = $uploaddir?'/':'';
        
        //mode = 1 - upload backup dir (call from BaseConnector::uploadDir)
        //mode = 2 - rotate remoted dir (call from remoteMoveFile)
        $prefix =  $mode==1?$this->root_dir.$this->cur_dir.'/':'';
        
        $newfn = $prefix.$uploaddir.$dlm.basename($file);
        ftp_put($this->conn, $newfn, $file, FTP_BINARY);
        $this->logger->write("root_dir={$this->root_dir}, cur_dir={$this->cur_dir}, upl={$uploaddir}", 3);        
        $this->logger->write("File {$file} uploaded to {$newfn}", 3);
    }    
    
    public function moveFile($srcfile, $dest)
    {
        $fname = basename($srcfile);
        $remote_dest_dir =  dirname($dest);
        $this->logger->write("remote move {$srcfile} to {$dest}", 3);
        
        $localfile = $this->config['tmp_dir'].'/'.$fname;
        
        //downloading from FTP to tmp dir
        $this->getFile($localfile, '/'.$srcfile);
        
        if (file_exists($localfile))
        {
            //$remote_dest_dir = str_replace($this->cur_dir, '', $remote_dest_dir);
            $this->uploadFile($localfile, $remote_dest_dir, false, 2);

            ftp_delete($this->conn, '/'.$srcfile); 
            unlink($localfile);
        }
    }            
    
    public function checkDir($dir)
    {
        $r = @ftp_chdir($this->conn, $dir);

        if ($r)
        {
            $r = 1;
            $fl = mb_substr($this->cur_dir, 0, 1, 'UTF-8');
            if ($fl != '/')
                $dir = '/'.$this->cur_dir;
            ftp_chdir($this->conn, $dir);
        }
        else
        {
            $r = 0;
        }

        return $r;
    }

    public function checkFile($file)
    {
        $file_size = ftp_size($this->conn, $file);

        if ($file_size == -1)
            return false;
        else
            return true;
    }
    
    public function deleteDir($dir)
    {
        $deldir  = '/'.ltrim($dir, '/');
        if (ftp_rmdir($this->conn, $deldir)) 
        {
            $this->logger->write("deleting remote dir {$deldir} \n", 3);
        }
        else
        {
            $this->logger->write("error deleting remote dir {$deldir} \n", 1);          
        }
    }

    public function deleteFile($fn)
    {
        $this->logger->write("deleting remote file {$fn} \n", 3);
        ftp_delete($this->conn, '/'.$fn);
    }
}

?>