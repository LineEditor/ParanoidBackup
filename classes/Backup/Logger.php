<?php

namespace Backup;

class Logger
{
    private $maindir;
    private $logfile;

    private $write_f; //write to file or not
    private $write_d; //write to display or not
    private $log_level;
            
    public function __construct($maindir)
    {
        $this->maindir = $maindir;
        $this->log_level = 3; 
        $this->write_f = 0;
        $this->write_d = 1;
    }        
    
    public function setLogFile($config, $project_name)
    {
        $logfile = '';
        
        $error = '';
        $fe = 0;
        if (array_key_exists('log_file', $config) && trim($config['log_file']))
        {
            $logfile = trim($config['log_file']);
            
            if (!file_exists($logfile))
            {
                $res = @file_put_contents($logfile, "1");
                if ($res===false)
                   $logfile = '';   
                else
                   unlink($logfile);      
            }
            else
            {
                $fe = 1;
                if (!is_writable($logfile))
                {
                    $error = "Logfile {$logfile} from backup.ini for project {$project_name} not writable, trying using default log conf/{$project_name}/backup.log\n";
                    $logfile = "";           
                }
            }
        }


        if (!$logfile)
        {
            $logfile = $this->maindir."/conf/{$project_name}/backup.log";    

            if (!file_exists($logfile))
            {
                $res = @file_put_contents($logfile, "1");
                if ($res===false)
                   $logfile = '';   
                else
                   unlink($logfile);      
            }
            else
            {
                $fe = 1;
                if (!is_writable($logfile))
                {
                    $error .= "Logfile {$logfile}  for project {$project_name} not writable. Logging to file disabled \n";
                    $logfile = "";           
                }
            }
        }        
        
        $this->logfile = $logfile;
        
        $log_mode  = array_key_exists('log_mode', $config)?intval($config['log_mode']):3;
        $log_level = array_key_exists('log_level', $config)?intval($config['log_level']):1;
        
        $this->log_level = $log_level?$log_level:1;
        
        $this->write_f =  (!$logfile || $log_mode < 2)?0:1;
        $this->write_d = ($this->log_level==1 || $this->log_level==3)?1:0;
        
        if ($this->write_f && $fe)
        {
            $max_logsize = array_key_exists('max_logsize', $config)?intval($config['max_logsize']):10000000;
            
            if ($max_logsize > 0 && filesize($logfile) >= $max_logsize) 
                unlink($logfile);
        }
        
        if ($error)
           $this->write($error, 1);
    }
    
    public function write($msg, $logl = 1)
    {
        if ($logl <= $this->log_level)
        {
           if (is_array($msg))
               $msg = var_export($msg, true);

           if ($logl < 3) 
               $msg = date("Y-m-d H:i:s ").' '.$msg;
               
           if ($this->write_f)
           {
              file_put_contents($this->logfile, $msg."\n", FILE_APPEND); 
           }
        
           if ($this->write_d)
           {
              print($msg."\n");   
           }
        }
    }    
}

?>