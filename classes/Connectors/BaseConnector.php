<?php

namespace Connectors;

/**
*  Base class-connector
*/
abstract class BaseConnector implements Connector
{

    protected $month_arr = array('Jan'=>1, 'Feb'=>2, 'Mar'=>3, 'Apr'=>4, 'May'=>5, 'Jun'=>6, 'Jul'=>7, 'Aug'=>8, 'Sep'=>9, 'Oct'=> 10, 'Nov'=>11, 'Dec'=>12);

    protected $tmp_dir;
    protected $start_dir;
    protected $cur_dir;
    protected $root_dir;
    public $config;
        
    protected $after_name;
    protected $logger;
    protected $updated_rotated_files;
    protected $delete_internal_dirs_when_rotating;
    
    function __construct($logger, $config,  $after = '')
    {
        $this->logger = $logger;
        $this->after_name = $after;   
        $this->config = $config;    
    
        if ($after)
        {
            $r = false;
            if (array_key_exists('tmp_dir', $config))
            {
                $this->tmp_dir = $config['tmp_dir'];
                if (!file_exists($this->tmp_dir))
                    $r = mkdir($this->tmp_dir);
            }
            
            if (!$r)
            {
                $this->tmp_dir = $config['backup_dir'].'/tmp';
                if (!file_exists($this->tmp_dir))
                    $r = mkdir($this->tmp_dir);
            }
        }
    }
    
    public function connect()
    {
    }

    public function close()
    {
    }
    
    function uploadDir($path)
    {
        $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::UNIX_PATHS | \FilesystemIterator::FOLLOW_SYMLINKS);
        $iterator = new \RecursiveIteratorIterator($directory);

        foreach ($iterator as $info)
        {
           $filename = $info->getBasename();
          
           if ($filename == '..')
               continue;

           $curdir = $info->getPath();  
           $clearpath = str_replace($path, '', $curdir);

           if ($filename == '.' && $clearpath)
           {
              $this->logger->write("uploadDir, createDir: {$clearpath}");
              $this->createDir($clearpath, true);
           }
           else
           {
              if (!$info->isDir())
              {           
                 $file = $info->getPathname();
                 $this->uploadFile($file, $clearpath, false, 1);
              }
           }   
        }   
    }      
    
    public function rotate($remote_dir, $rotate_days)
    {
        $this->logger->write("start remote rotate. startDir=".$this->start_dir, 2);
        $this->root_dir = rtrim($remote_dir, '/');
        
        //will create dirs and files in /base
        $this->cur_dir = $this->root_dir.'/base';
        
        if (!$this->checkDir($this->cur_dir))
            throw new \Exception("Directory {$this->cur_dir} not exists", 1);
        
        $dirlist = $this->getDirsList($remote_dir);

        $curtime = strtotime(date("Y-m-d"));
        
        $this->updated_rotated_files = array();
        $deltasec = $rotate_days*24*3600;

        //dirlist sorted by date desc. The newest dirs are first
        //So, we guarantee, that we process the newest versions of files first
        //The older versions of the same file would be deleted
        foreach ($dirlist as $d)
        {
            if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/",$d))
                continue;

            $dirtime = strtotime($d);
            if ($curtime - $dirtime > $deltasec)
            {
                $this->logger->write("rotating dir {$d}", 2);
                $this->start_dir = $remote_dir.'/'.$d;
                $this->parseRemoteDir($remote_dir.'/'.$d, 2);
                
                if (!$this->delete_internal_dirs_when_rotating)
                    $this->deleteDir($remote_dir.'/'.$d);
            }     
        }        
        $this->logger->write("end remote rotate", 2);        
    }
    
    public function getDirsList($remote_dir)
    {
        $files = $this->getFilesInDir($remote_dir);
        
        $res = array();
        foreach ($files as $f)
        {
            if ($f['d'])
                $res[] = $f['name'];
        }
        
        rsort($res); 
        return $res;
    }


    public function setStartDir($dir, $check = true)
    {
        $this->start_dir = rtrim($dir, '/');
        $this->cur_dir  = $this->start_dir;

        if ($check && !$this->checkDir($this->cur_dir))
            $this->createDir($this->cur_dir, true, 2);
    }

    public function checkBaseDir()
    {
        return $this->checkDir($this->start_dir.'/base');
    }

    protected function parseRemoteDir($remote_dir, $mode)
    {
        $files = $this->getFilesInDir($remote_dir);
        
        $res = array();
        $cleardir = str_replace($this->start_dir, '', $remote_dir);

        foreach ($files as $f)
        {
            if ($f['d'])
            {
                if ($mode == 2)
                {
                    if (!array_key_exists($cleardir, $this->updated_rotated_files) && !$this->checkDir($this->cur_dir.$cleardir.'/'.$f['name']) )
                    {
                        $this->createDir($cleardir.'/'.$f['name']);
                        $this->updated_rotated_files[$cleardir.'/'.$f['name']] = 1;
                    }
                    
                    $this->parseRemoteDir($remote_dir.'/'.$f['name'], $mode);    
                    $this->deleteDir($remote_dir.'/'.$f['name']);
                }
            }
            else
            {
                $locname = $cleardir.'/'.$f['name'];
                $srcpath = $this->start_dir.$locname;
                if ($mode==2)
                {
                    //moving file from one remote directory to another
                    if  (!array_key_exists($locname, $this->updated_rotated_files))
                    {
                        $this->moveFile($srcpath, $this->cur_dir . $cleardir . '/' . $f['name']);
                        $this->updated_rotated_files[$locname] = 1;
                    }
                    else //we have newer version of the file, so the older version would be deleted
                    {
                        $this->deleteFile($srcpath);
                    }
                }
            }
        }       

        if ($mode==2 && $this->delete_internal_dirs_when_rotating)
            $this->deleteDir($remote_dir);
    }    
}

?>