<?php

namespace Backup;

/**
*  Processing of CLI 
*/
class ParanoidBackup
{
    private $maindir = '';
    private $cmdline_params = array(); 
    private $fs;
    private $phpconfig = array('zippassw'=>0);
    private $logger;
    private $version  = '1.0';

    public function __construct($maindir)
    {
        $this->maindir = $maindir;
        $this->fs = new FileSystem();
        $this->logger = new Logger($this->maindir);
        
        if (!defined('PHP_VERSION_ID')) 
        {
            $phpversion = explode('.', PHP_VERSION);

            define('PHP_VERSION_ID', ($phpversion[0] * 10000 + $phpversion[1] * 100 + $phpversion[2]));
        }
        
        if (PHP_VERSION_ID >= 70200)
        {
            $this->phpconfig['zippassw'] = 1;
        }
    }
  
    public function exec()
    {
        global $argv;


        $arg_len = count($argv);

        for($i=1;$i < $arg_len; $i++)
        {
           $parts = preg_split("/=/", $argv[$i]);
           if (!array_key_exists($parts[0], $this->cmdline_params))
           {
              $this->cmdline_params[$parts[0]] = count($parts)>1?$parts[1]:1;
           }
        }
        if (array_key_exists('-v', $this->cmdline_params))
        {
            $this->showVersion();
        }
        else if (array_key_exists('-h', $this->cmdline_params) || array_key_exists('-help', $this->cmdline_params))
        {
            $this->showHelp();
        }

        if (array_key_exists('-c', $this->cmdline_params))
        {

            switch($this->cmdline_params['-c'])
            {
                case 'cfb':
                    $this->composeFullBackup();
                    break;
                case 'upl':
                    $this->uploadBackupDirs();
                    break;                    
                case 'rotr':
                    $this->rotateRemote();
                    break;
                case 'rotr_zip':
                    $this->rotateRemoteZip();
                    break;

                default:
                    $this->showParamError();
            }    
        }
        else
        {
            $this->defaultAction();
        }
    }

    function showParamError()
    {
        $script = basename($_SERVER['SCRIPT_NAME']);
        printf(  "Invalid value (%s) of -c parameter.\n", $this->cmdline_params['-c']);
        printf ( "To see the help page, run command:\nphp %s -h\n", $script);

        die();
    }

    function showHelp()
    {
        $script = basename($_SERVER['SCRIPT_NAME']);
        printf("Usage: php %s [-v] [-help] [-c=<command>] [-p=<projectlist>] [-d=<date>]\n\n", $script);
        printf(  "  %-60s %s\n", "no arguments", "run backup for all projects in ./conf directory");
        printf(  "  %-60s %s\n", "-p", "run backup for specified projects. Projects must be separated by comma");
        printf(  "  %-60s %s\n", "-h, -help", "this page");
        printf(  "  %-60s %s\n", "-v", "show version");
        printf(  "\n");
        printf(  "Possible  values of -c parameter:\n");
        printf(  "(note: if -p parameter not specified, all projects in ./conf directory would be processed)\n");
        printf(  "  %-60s %s\n","-c=cfb", "compose full backup from latest date and make zip archive of full backup");
        printf(  "  %-60s %s\n","-c=cfb -zip -zippassw=<passw>", "compose full backup and create zip file, encrypted with zippassw (zippasw is optional parameter) in backup directory");
        printf(  "  %-60s %s\n","-c=cfb -zip=<name> -zippassw=<passw>", "compose full backup and create zip file named as <name>, encrypted with zippassw in backup directory");
        printf(  "  %-60s %s\n","-c=cfb -zip=<name> -zippassw=<passw> -dir=<dir_to_zip>", "compose full backup and create zip file named as <name> in directory <dir_to_zip>, encrypted with zippassw");
        printf(  "  %-60s %s\n","-c=cfb -d=<YYYY-MM-DD>", "compose full backup from <YYYY-MM-DD> date and earlear and make zip archive of full backup");
        printf(  "  %-60s %s\n","-c=upl", "upload only new backup dirs to servers described in [afterbackup] block");
        printf(  "  %-60s %s\n","-c=upl -all", "upload ALL backup dirs to servers described in [afterbackup] block");
        printf(  "  %-60s %s\n","-c=rotr", "remote rotate of backup dirs on servers described in [afterbackup] block");
        printf(  "  %-60s %s\n","-c=rotr_zip", "remote rotate of zip archives on servers described  in [afterbackup] block");
        die();

    }

    function showVersion()
    {
        echo "ParanoidBackup v".$this->version."\n";
        die();
    }

    function formConnList($backup_config)
    {
       $i  = 1;
       $connlist = array();
       while(true)
       {
           $after = "after_{$i}";
       
           if (array_key_exists($after, $backup_config))
               $connlist[] = $i;
           else
               break;

           $i++;
       }    
       
       return $connlist;
    }

    function rotateRemoteZip()
    {
        if (array_key_exists('-p', $this->cmdline_params))
            $backuplist =  preg_split("/\,/", $this->cmdline_params['-p']);
        else
            $backuplist = $this->fs->getFileList($this->maindir.'/conf', 3);

        if (array_key_exists('-conn', $this->cmdline_params))
            $connlist =  preg_split("/\,/", $this->cmdline_params['-conn']);
        else
            $connlist = array();

        $this->logger->write("\n\nStart remote rotate zip", 2);

        foreach ($backuplist as $b)
        {
            try
            {
                $backup_config = $this->initBackup($b);

                $site_backup = new SiteBackup($b, $backup_config, $this->cmdline_params, $this->phpconfig, $this->logger);
                $this->logger->write('Starting project '.$b, 2);
                $connlist = $connlist?$connlist:$this->formConnList($backup_config);
                $site_backup->cmdlineRemoteZip($connlist);

            }
            catch (\Exception $e)
            {
                $this->logger->write($e->getMessage());
            }
        }
    }

    function rotateRemote()
    {
        
        if (array_key_exists('-p', $this->cmdline_params))
           $backuplist =  preg_split("/\,/", $this->cmdline_params['-p']);    
        else
           $backuplist = $this->fs->getFileList($this->maindir.'/conf', 3);

        if (array_key_exists('-conn', $this->cmdline_params))
           $connlist =  preg_split("/\,/", $this->cmdline_params['-conn']);    
        else
           $connlist = array();


        $this->logger->write("\n\nStart remote rotate", 2);        

        foreach ($backuplist as $b)
        {
            try
            {
                $backup_config = $this->initBackup($b);                


                $site_backup = new SiteBackup($b, $backup_config, $this->cmdline_params, $this->phpconfig, $this->logger);
                $this->logger->write('Starting project '.$b, 2);
                $connlist = $connlist?$connlist:$this->formConnList($backup_config);
                $site_backup->rotateRemoteDirs($connlist); 
            }
            catch (\Exception $e)
            {
                $this->logger->write($e->getMessage());
            }
        }    
    }
    
    function uploadBackupDirs()
    {
        if (array_key_exists('-p', $this->cmdline_params))
           $backuplist =  preg_split("/\,/", $this->cmdline_params['-p']);    
        else
           $backuplist = $this->fs->getFileList($this->maindir.'/conf', 3);

        if (array_key_exists('-conn', $this->cmdline_params))
           $connlist =  preg_split("/\,/", $this->cmdline_params['-conn']);    
        else
           $connlist = array();

        $all = array_key_exists('-all', $this->cmdline_params)?true:false;

        $this->logger->write("\n\nStart upload backup directories", 2);
        foreach ($backuplist as $b)
        {
            try
            {
                $backup_config = $this->initBackup($b);                
                    
                $site_backup = new SiteBackup($b, $backup_config, $this->cmdline_params, $this->phpconfig, $this->logger);

                $this->logger->write('Starting project '.$b, 2);  
                $connlist = $connlist?$connlist:$this->formConnList($backup_config);            
                $site_backup->uploadBackupDirs($connlist, $all); 
            }
            catch (\Exception $e)
            {
                $this->logger->write($e->getMessage());
            }
        }
    }

    function composeFullBackup()
    {
        if (array_key_exists('-p', $this->cmdline_params))
        {
           $backuplist =  preg_split("/\,/", $this->cmdline_params['-p']);    
        }
        else
        {
           $backuplist = $this->fs->getFileList($this->maindir.'/conf', 3);
        }    
        
        foreach ($backuplist as $b)
        {
            try
            {
                $backup_config = $this->initBackup($b);                
                $this->logger->setLogFile($backup_config, $b);
                $this->logger->write('Starting creating full backup of '.$b, 2);    
                
                $site_backup = new SiteBackup($b, $backup_config, $this->cmdline_params, $this->phpconfig, $this->logger);
                $site_backup->composeFullBackup(); 
            }
            catch (\Exception $e)
            {
                $this->logger->write($e->getMessage(), 1);
            }
        }
    }
    
    function defaultAction()
    {
        if (array_key_exists('-p', $this->cmdline_params))
        {
            $backuplist =  preg_split("/\,/", $this->cmdline_params['-p']);
        }
        else
        {
            $backuplist = $this->fs->getFileList($this->maindir.'/conf', 3);
        }


        foreach ($backuplist as $p)
        {
            try
            {
                $backup_config = $this->initBackup($p);
                
                $this->logger->setLogFile($backup_config, $p);
                $this->logger->write("\n\nStarting backup of ".$p, 2);
                $site_backup = new SiteBackup($p, $backup_config, $this->cmdline_params, $this->phpconfig, $this->logger);
                $site_backup->run(); 
            }
            catch (\Exception $e)
            {
                $this->logger->write($e->getMessage());
            }
        }
    }
    
    function initBackup($name)
    {
        $project_dir = $this->maindir."/conf/{$name}";
        
        if (!file_exists($project_dir))
        {
            throw new \Exception("Directory conf/{$name} not exists");            
        }
        
        $inifile = $project_dir."/backup.ini";
        if (file_exists($inifile))
        {
            $config = parse_ini_file($inifile, false, INI_SCANNER_RAW);
            return $config;
        }
        else
            throw new \Exception('NO ini file '.$inifile);
    }
}
    

    
?>
