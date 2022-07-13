<?php

namespace Backup;

/**
* Main backup class
*/
class SiteBackup
{
    private $backup_dir;  //Current directory to save backuped files 
    private $available_connectors = array('FTP', 'SFTP', 'FTPS', 'CERT', 'LOCAL', 'YANDEX'); 
    private $config;
    private $connector;
    private $all_backup_dirs;
    private $base_backupdir; //path to 'base' directory 
    private $updated_files = array();
    private $project_name;
    private $cmdline_params;
    private $phpconfig;
    private $fs;
    private $zipname;
    private $zippassw;
    private $logger;
    private $zip_path;
    private $zip_list = array();
    private $tempdir_for_delete = array();

    public function __construct($project_name, $config, $cmdline_params, $phpconfig, $logger)
    {
        $this->connector = null;

        $enabled = array_key_exists('enabled', $config)? $config['enabled']:1;

        if (!$enabled)
            throw new \Exception("enabled=0 in backup.ini,  project \"{$project_name}\" skipped.");

        if (!array_key_exists('conn_type', $config))
            throw new \Exception('Parameter conn_type not defined in INI file');

        $config['conn_type'] = trim(strtoupper($config['conn_type']));
        if (!in_array($config['conn_type'], $this->available_connectors))
            throw new \Exception("Bad parameter conn_type = {$config['conn_type']} in INI file. 
                Available values of conn_type: ".implode(',', $this->available_connectors));

        if ($config['conn_type'] == 'LOCAL')
        {
            if (!array_key_exists('remote_dir', $config))
                throw new \Exception('Parameter remote_dir not defined in INI file');
        }

        if (in_array($config['conn_type'], array('YANDEX')))
        {
            if (!array_key_exists('tk', $config))
                throw new \Exception('Parameter tk not defined in INI file');
        }

        if (in_array($config['conn_type'], array('FTP', 'SFTP', 'FTPS')))
        {
            if (!array_key_exists('login', $config))
                throw new \Exception('Parameter login not defined in INI file');

            if (!array_key_exists('password', $config))
                throw new \Exception('Parameter password not defined in INI file');

            if (!array_key_exists('host', $config))
                throw new \Exception('Parameter host not defined in INI file');

            if (!array_key_exists('port', $config))
                throw new \Exception('Parameter port not defined in INI file');

        }

        $this->zip_path = '';

        if (!array_key_exists('zip', $config))
            $config['zip'] = 0;


        if (!array_key_exists('backup_dir', $config))
            throw new \Exception('Parameter backup_dir not defined in INI file');
        else
        {
            if (!file_exists($config['backup_dir']))
            {
                throw new \Exception('Backup directory '.$config['backup_dir'].' not exists');
            }
            else
            {
                if (!is_writable($config['backup_dir']))
                {
                    throw new \Exception('Backup directory '.$config['backup_dir'].' is not writable');
                }
            }
        }

        $config['backup_dir'] = trim($config['backup_dir'], '/\\');

        if (array_key_exists('exclude_dirs', $config))
        {
            $dstr = trim($config['exclude_dirs']);
            $dstr = ltrim( $dstr, '[');
            $dstr = rtrim( $dstr, ']');
            $config['exclude_dirs'] = preg_split("/,\s*/", $dstr);
        }
        else
        {
            $config['exclude_dirs'] = array();
        }

        if (array_key_exists('create_exclude_dirs', $config))
        {
            $config['create_exclude_dirs'] = intval($config['create_exclude_dirs']);
        }
        else
        {
            $config['create_exclude_dirs'] = 1;
        }

        if (array_key_exists('exclude_extensions', $config))
        {
            $dstr = trim($config['exclude_extensions']);
            $dstr = ltrim( $dstr, '[');
            $dstr = rtrim( $dstr, ']');
            $config['exclude_extensions'] = preg_split("/,\s*/", $dstr);
        }
        else
        {
            $config['exclude_extensions'] = array();
        }

        $this->config = $config;

        $this->fs = new FileSystem();
        $this->project_name = $project_name;

        $this->cmdline_params = $cmdline_params;
        $this->phpconfig = $phpconfig;

        $this->logger = $logger;

        $this->fs = new FileSystem();
        $this->all_backup_dirs = $this->fs->getFileList($this->config['backup_dir'], 1);

        //deleting temp directory from backup list
        if (array_key_exists('tmp_dir', $this->config))
        {
            for($i=0;$i<count($this->all_backup_dirs);$i++)
            {
                $curdir = $this->config['backup_dir'].'/'.$this->all_backup_dirs[$i]['name'];
                if ($curdir==$this->config['tmp_dir'])
                {
                    unset($this->all_backup_dirs[$i]);
                    break;
                }
            }
        }

        $this->fs->setBackup_dirs($this->all_backup_dirs);
        $this->base_backupdir = $this->config['backup_dir'].'/base';
    }

    private function splitDir($path, $isdir,  $dir_not_to_delete)
    {
         $parts = preg_split("/\//", $path);
         
         if (!$isdir)
             array_pop($parts);
                    
         $dwf = '';
         $dlm = '/';    
         foreach($parts as $p)
         {
             if (!$p)
                 continue;
                          
             $dwf .= $dlm.$p;

             if (!array_key_exists($dwf, $dir_not_to_delete))
                 $dir_not_to_delete[$dwf] = 1;
         }
         
         return $dir_not_to_delete;
    }
            
    public function parseBackupDir($path, $mode, $delete_dir, $save_date = false)
    {
        //mode = 1 - delete old empty dirs without files, which contains in older backup dirs
        //mode = 2 - rotate_backup: move files to base directory and delete $path
    	$directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::UNIX_PATHS | \FilesystemIterator::FOLLOW_SYMLINKS);
    
	    $iterator = new \RecursiveIteratorIterator($directory);
    	$files = array();

        $dir_not_to_delete = array();
        //echo "parseB = {$path}, mode={$mode}, delete_dir= $delete_dir\n";
	    foreach ($iterator as $info) 
    	{
            $fn = $info->getBasename();
            //echo "{$fn}\n";


            if ($fn == '..')
            {
                continue;
            }

      		if ($fn == '.') //this is directory
      		{
                $curdir = $info->getPath();
                $clearpath = str_replace($path, '', $curdir);
                if (!$clearpath)
                    continue;

                $log  = 0;
 
                if ($mode==1)
                {
                    if ($this->fs->checkFile($clearpath, 0, $path, $log))
                    {
                        $dir_not_to_delete = $this->splitDir($clearpath, 1,  $dir_not_to_delete);
                    }  
                } 
                else if ($mode==2)
                {
                    //create directory in base, if not exists
                    if (!file_exists($this->base_backupdir.$clearpath))
                    {
                        $fd = $this->base_backupdir.$clearpath;
                        mkdir($fd);
                    }
                }
                $files[] = array('n'=>$clearpath, 'd'=>true);
      		}
            else
            {
                $isdir = $info->isDir();

                if (!$isdir)
                {
                    if ($mode==1)
                    {                    
                        $curdir = $info->getPath();
                        $clearpath = str_replace($path, '', $curdir);
                        $dir_not_to_delete = $this->splitDir($clearpath, 1, $dir_not_to_delete);
                    }
                    else if ($mode==2)
                    {
                        $filename = $info->getPathname();
                        $clearpath = str_replace($path, '', $filename);

                        //if $filename already in $clearpath, it is the newest version,
                        //so continue whith next file
                        if (array_key_exists($clearpath, $this->updated_files))
                        {
                            $files[] = array('n'=>$clearpath, 'd'=>$isdir);
                            continue;
                        }
                        //check, if file from current backup dir exists in base,
                        //and if exists, it is newer or older than our current file
                        $copyfile = false;
                        $base_filetime = 0;
                        if (!file_exists($this->base_backupdir.$clearpath))
                        {
                            $copyfile = true;
                        }
                        else
                        {
                            $cur_filetime = filemtime($filename);
                            $base_filetime = filemtime($this->base_backupdir . $clearpath);

                             //echo "cur_filetime={$cur_filetime}  base_filetime={$base_filetime}\n";
                            if ($cur_filetime > $base_filetime)
                                 $copyfile = true;
                        }
                        
                        if ($copyfile)
                        {
                            //echo "copy to basedir: $filename TO ".$this->base_backupdir.$clearpath."\n";

                            copy($filename, $this->base_backupdir.$clearpath);

                            if ($save_date && $base_filetime)
                            {
                                touch($this->base_backupdir.$clearpath, $base_filetime);
                            }
                            $this->updated_files[$clearpath] = 1;
                        }    
                    }    
                    
                    $files[] = array('n'=>$clearpath, 'd'=>$isdir);
                }
            }
	    }

        //$this->logger->write("parseBackupDir {$path}, mode={$mode}, delete_dir={$delete_dir}\n");

        if (!$delete_dir)
            return;
            
        $cnt = count($files);

        //$this->logger->write($files);
        //All not empty dirs - in array $dir_not_to_delete
        //so, if $files[$i] not in $dir_not_to_delete, it must be deleted
        //for loop in reverse mode, because first we delete the files, then the directory in which they are located, otherwise rmdir will not work
        //(in $files arr, First comes the directories, then the files that are in it)

        //$this->logger->write(var_export($files, true));
        for($i = $cnt-1;$i>=0;$i--)
        {
           $f = $files[$i]; 

           if ($mode == 1) 
           {
               if ($f['d'])
               {
                   if (!array_key_exists($f['n'], $dir_not_to_delete))
                   {
                       if ($this->backup_dir != $path.$f['n'])
                       {
                           rmdir($path.$f['n']);
                       }
                   }
               }
           }
           else if ($mode == 2) //rotate backup mode - delete all
           {
               if ($f['d'])
               {
                  rmdir($path.$f['n']);
               }  
               else
               {
                  unlink($path.$f['n']);    
               } 
           }
        }

        if ($mode == 2)
            rmdir($path);
    }

    public function composeFullBackup()
    {
        $startdate = time();
        if (array_key_exists('-d', $this->cmdline_params))
        {
            $startdate = strtotime($this->cmdline_params['-d']);
        }

        if (array_key_exists('-zip', $this->cmdline_params))
        {
            if (isset($this->cmdline_params['-dir']) && $this->cmdline_params['-dir'])
            {
                $destdir = $this->cmdline_params['-dir'];
            }
            else
            {
                $destdir = $this->config['backup_dir'];
            }

            $destdir = rtrim($destdir, '\/');
            if (!is_dir($destdir))
            {
                printf("Directory %s not exists, please create it", $destdir);
                die();
            }
        }

        //directories in all_backup_dirs sorted by date descending
        //So, we guarantee, that we copy newest version of file to base

        $save_date = 1;
        foreach ($this->all_backup_dirs as $d)
        {
            if (strtolower($d['name'])=='base')
                continue;

            $dirtime  = strtotime($d['name']);

            if ($dirtime <= $startdate) //ignore all dirs, that are newer, then $startdate
                $this->parseBackupDir($d['fullname'], 2, 0, $save_date);
        }  
        
        if (array_key_exists('-zip', $this->cmdline_params))
        {
            if ($this->cmdline_params['-zip'] != '0')
            {
                $this->zipname = '';
                if ($this->cmdline_params['-zip'] != '1')
                {
                    $zipname = $this->cmdline_params['-zip'];    
                }
                else
                {
                    $zipname = '';
                }

                $passw = '';
                if (array_key_exists('-zippassw', $this->cmdline_params))
                {
                    $passw = $this->cmdline_params['-zippassw'];
                
                    if (!$this->phpconfig['zippassw'])
                    {
                       $passw = '';
                    }                
                }    
                $this->makeZip($this->base_backupdir, $destdir, $passw, $zipname);
            }    
        }
    }    
        
    public function rotateBackups($rd = 0)
    {
        $curtime = strtotime(date("Y-m-d"));

        if ($rd == 0)
        {
            if (array_key_exists('rotate_days', $this->config))
            {
                $rotate_days = intval($this->config['rotate_days']);
                $rotate_days = $rotate_days ? $rotate_days : 9;
            }
            else
                $rotate_days = 9;
        }
        else
        {
            $rotate_days = $rd;
        }


        
        $deltasec = $rotate_days*24*3600;
        foreach ($this->all_backup_dirs as $d)
        {
            if (strtolower($d['name'])=='base')
                continue;
            
            if ($curtime - $d['dateint'] > $deltasec)
            {
                $this->parseBackupDir($d['fullname'], 2, 1);
            }     
            
        }    
    }    
    

    public function run()
    {

        try
        {
           if (!file_exists($this->base_backupdir))
           {
               $mode = 1;
               $this->backup_dir = $this->base_backupdir;
               mkdir($this->backup_dir);    
           }
           else
           {
               $basedir_mtime = date("Y-m-d", filemtime($this->base_backupdir));

               if ($basedir_mtime != date("Y-m-d"))
               {
                   $dn = date("Y-m-d");
                
                   $this->backup_dir = $this->config['backup_dir'].'/'.$dn;
                   if (!file_exists($this->backup_dir))
                   {
                       mkdir($this->backup_dir);
                   }
                   $mode = 2;
               }
               else
               {
                   //running script at the same date, when base directory was created
                   $mode = 1;
                   $this->backup_dir =  $this->base_backupdir;
               }
           }

           $this->download($mode);
           if ($mode==2)
           {
               //When downloading, script always creates directories in $this->backup_dir
               //even if not copying files in this directories (if this is old files)
               //So, if directory created, and it's empty, and it is old directory (which
               //already exists in other backup dirs), it must be deleted
               $this->parseBackupDir($this->backup_dir, 1, 1);
               $this->rotateBackups();
           }


           $this->zippassw = '';
           
           if (array_key_exists('zippassword', $this->config))
           {
              $passw = trim($this->config['zippassword']);
           
              if ($passw && !$this->phpconfig['zippassw'])
              {
                  $this->logger("To use password for creating zip, you must have PHP version>=7.2 (your current virsion: ".PHP_VERSION."). The password will not be used for creating the archive", 1);           
                  $passw = '';
              }
                       
              $this->zippassw = $passw;
           }

           $files = $this->fs->getFileList($this->backup_dir);
           if (!$files)
                rmdir($this->backup_dir);

           $this->copyAfterBackup();
        }
        catch(\Exception $e)
        {
            throw new \Exception($e->getMessage());
        }

    }
    
    function createAfterConnector($after, $conn_config)
    {
       if (!array_key_exists('ct', $conn_config))
           throw new \Exception("Field ct not defined in {$after} in ini file");    
                
       $connector_type = trim(strtoupper($conn_config['ct']));
                
       if (!in_array($connector_type, $this->available_connectors))
          throw new \Exception("Bad parameter ct = {$conn_config['ct']} in {$after} in INI file. 
                                Available values of ct: ".implode(',', $this->available_connectors));
                     
       $connector_class = $connector_type.'Connector';        

       if (!array_key_exists('remote_dir', $conn_config))
          throw new \Exception("Upload dir not defined in {$after} in ini file");
          
       $m = intval($conn_config['m']);

       foreach ($conn_config as $key => $v)
       {
           $this->config[$key] = $v;
       }

       if (class_exists('Connectors\\'.$connector_class))
       {
           $conn = "Connectors\\{$connector_class}";
           try
           {
              $connector = new $conn($this->logger, $this->config, $after); 
              $connector->connect($conn_config, 1);
              
              return $connector;  
           }
           catch(\Exception $e)
           {
              throw new \Exception($e->getMessage());
           }           
        }
        else
           throw new \Exception("No connector class {$connector_class}");   
      
    }


    function rotateRemoteZip($connector)
    {
        $connector->setStartDir($connector->config['remote_dir']);
        $zipfiles = $connector->getFilesInDir($connector->config['remote_dir']);
        $rd = $connector->config['rd'];

        $this->logger->write("start rotateRemoteZip  for connector ".$connector->config['ct']);

        $deltasec = $rd * 3600*24;
        $prname = $this->project_name;

        $matches = array();
        $curtm = time();
        $has_to_rotate = 0;
        foreach ($zipfiles as $zp)
        {
            if (preg_match("/^{$prname}_([0-9]{4}-[0-1][0-9]-[0-3][0-9])\.zip$/", $zp['name'], $matches))
            {
                $dt = trim($matches[1]);

                $tm = strtotime($dt);
                if ( $curtm - $tm  > $deltasec )
                {
                    $connector->deleteFile($connector->config['remote_dir'].'/'.$zp['name']);
                    $has_to_rotate = 1;
                }

            }
        }

        $rdir = 'zip_'.$this->project_name.'_'.date("Y-m-d").'_'.$rd;

        if (!file_exists($this->config['tmp_dir']))
        {
            $r = mkdir($this->config['tmp_dir']);
            if (!$r)
            {
                throw new \Exception("Can't create temp dir ".$this->config['tmp_dir']);
            }
        }

        $full_temp_path = $this->config['tmp_dir'].'/'.$rdir;
        $zipname = $this->project_name.'_base.zip';
        $path_to_zip = $full_temp_path.'/'.$zipname;


        //$has_to_rotate = 1;
        if ($has_to_rotate)
        {
            if (!file_exists($path_to_zip)) //rotate
            {
                $this->logger->write("start rotate backup dir and zip ".$path_to_zip);

                $old_all_backup_dirs = $this->all_backup_dirs;
                $old_backup_dir = $this->backup_dir;
                $old_base_backupdir = $this->base_backupdir;

                $short_backup_dir_name = '';
                $short_base_dir_name = '';

                if ($old_backup_dir)
                {
                    $parts = preg_split("/\//", $old_backup_dir);
                    $short_backup_dir_name = array_pop($parts);
                }

                if ($old_base_backupdir)
                {
                    $parts = preg_split("/\//", $old_base_backupdir);
                    $short_base_dir_name = array_pop($parts);
                }

                if (!is_dir($full_temp_path))
                {
                    $res = mkdir($full_temp_path);
                    $this->tempdir_for_delete[] = $full_temp_path;

                    // Before creating zip, and rotate zip archives on remote host, we
                    // copy all backup directories to temp folder, and rotate directories in this temp folder,
                    // because rotate period on remote host can be different from rotate period in $this->config['backup_dir']

                    $this->fs->copyDirs($this->config['backup_dir'], $full_temp_path, array($this->config['tmp_dir']), 1);
                    $this->all_backup_dirs = $this->fs->getFileList($full_temp_path, 1);
                    $this->backup_dir =  $short_backup_dir_name?$full_temp_path.'/'.$short_backup_dir_name:'';
                    $this->base_backupdir = $full_temp_path.'/'.$short_base_dir_name;

                    $this->rotateBackups($rd);


                    $passw = $this->phpconfig['zippassw']?$this->config['zippassword']:'';

                    $this->makeZip($full_temp_path.'/base', $full_temp_path, $passw, $zipname);
                }

                $this->all_backup_dirs = $old_all_backup_dirs;
                $this->backup_dir = $old_backup_dir;
                $this->base_backup_dir = $old_base_backupdir;

            }

            $connector->uploadFile($path_to_zip, "");
        }

        $this->logger->write("finish rotateRemoteZip  for connector ".$connector->config['ct']);
        return;

    }

    function cmdlineRemoteZip($connlist)
    {
        $this->logger->write("start cmdlineRemoteZip");


        foreach ($connlist as $c)
        {
            $c = trim($c);

            $after = "after_{$c}";

            $this->logger->write('connector: '. $after, 2);

            if (array_key_exists($after, $this->config))
            {
                $conn_config = json_decode(trim($this->config[$after]), true);

                try
                {
                    $connector = $this->createAfterConnector($after, $conn_config);

                    if (!array_key_exists('remote_dir', $conn_config))
                        throw new \Exception("Field remote_dir not defined in {$after} in ini file");

                    $rd = array_key_exists('rd', $conn_config)?intval($conn_config['rd']):0;
                    if ($rd)
                    {
                        $this->rotateRemoteZip($connector);
                    }
                    $connector->close();
                }
                catch(\Exception $e)
                {
                    throw new \Exception($e->getMessage());
                }
            }
            else
            {
                $this->logger->write("No {$after} block in ini file");
            }
        }

        $this->clearTmpDirs();
    }

    function clearTmpDirs()
    {
        foreach ($this->tempdir_for_delete as $t)
        {
            $this->fs->recursiveRmDir($t);
        }
    }

    function rotateRemoteDirs($connlist)
    {
        foreach ($connlist as $c)
        {
            $c = trim($c);
            
            $after = "after_{$c}";
            
            $this->logger->write('connector: '. $after, 2);
            
            if (array_key_exists($after, $this->config))
            {            
                $conn_config = json_decode(trim($this->config[$after]), true);
                
                try
                {
                    $connector = $this->createAfterConnector($after, $conn_config);

                    $rd = array_key_exists('rd', $conn_config)?intval($conn_config['rd']):0;

                    if (!array_key_exists('remote_dir', $conn_config))
                        throw new \Exception("Field remote_dir not defined in {$after} in ini file");    


                    $connector->rotate($conn_config['remote_dir'], $rd);
        
                    $connector->close();
                }
                catch(\Exception $e)
                {
                    throw new \Exception($e->getMessage());
                }                          
            }
            else
            {
                $this->logger->write("No {$after} block in ini file");
            }
        }
        
    }

    function uploadBackupDirsWithConnector($connector, $conn_config, $all)
    {
        $connector->setStartDir($conn_config['remote_dir']);
        $remote_dirlist = array();
        if ($all==false)
        {
            $remote_dirlist = $connector->getDirsList($conn_config['remote_dir']);
        }

        //uploading all directories in main backup directory
        $ix =0;
        foreach ($this->all_backup_dirs as $d)
        {
            //if ix==0, $d['name'] is the latest backup directory,
            //we always upload all files from this directory
            //So, check if ($ix > 0) required

            if ($ix > 0 && !$all && in_array($d['name'], $remote_dirlist))
                continue;

            $this->logger->write('start uploading '. $d['name'], 2);

            $connector->setStartDir($conn_config['remote_dir'].'/'.$d['name']);
            $connector->uploadDir($d['fullname']);
            $ix++;
        }
    }

    function uploadBackupDirs($connlist, $all)
    {
        foreach ($connlist as $c)
        {
            $c = trim($c);
            
            $after = "after_{$c}";
            
            $this->logger->write('connector: '. $after, 2);
            if ($all)
                $this->logger->write('uploading all directories', 2);
            else            
                $this->logger->write('uploading only new directories', 2);
                
            if (array_key_exists($after, $this->config))
            {            
                $conn_config = json_decode(trim($this->config[$after]), true);
                
                try
                {
                    $connector = $this->createAfterConnector($after, $conn_config);
                    $this->uploadBackupDirsWithConnector($connector, $conn_config, $all);
                    $connector->close();
                }
                catch(\Exception $e)
                {
                    throw new \Exception($e->getMessage());
                }                          
            }
            else
            {
                $this->logger->write("No {$after} block in ini file");
            }
        }
    }
    
    function copyAfterBackup()
    {
        $i = 1;
        $pure_dir_name = str_replace($this->config['backup_dir'], '', $this->backup_dir);
        $pure_dir_name = trim($pure_dir_name, '/');
        
        $this->logger->write("Start copy after backup", 2);
        $this->zip_list = array();
        while(true)
        {
            $after = "after_{$i}";
            $i++;                
            if (array_key_exists($after, $this->config))
            {
                $conn_config = json_decode(trim($this->config[$after]), true);
                
                if (!array_key_exists('m', $conn_config))
                    throw new \Exception("Field m not defined in {$after} in ini file");    

                if (!array_key_exists('remote_dir', $conn_config))
                    throw new \Exception("Field remote_dir not defined in {$after} in ini file");    
                    
                $m = intval($conn_config['m']);

                if ($m == 0)
                    continue;
                
                try
                {
                    $this->connector = $this->createAfterConnector($after, $conn_config);
                    $this->logger->write("Using connector {$after}", 2);
                    
                    $conn_config['root_dir'] = $conn_config['remote_dir'];

                    $remote_dir = $conn_config['remote_dir'];
                    if ($m==1)
                        $remote_dir .= '/'.$pure_dir_name;
                    else if ($m==2)
                        $remote_dir .= '/base';
                                         
                    $rd = array_key_exists('rd', $conn_config)?intval($conn_config['rd']):0;
                    
                    if ($m == 3) 
                    {
                        $this->connector->setStartDir($remote_dir);

                        $zipbase = $this->project_name.'_base.zip';
                        $base_exists = $this->connector->checkFile($conn_config['remote_dir'].'/'.$zipbase);

                        if (!$base_exists)
                        {
                            if (!$this->zip_list)
                            {
                                foreach ($this->all_backup_dirs as $d)
                                {
                                    $zipname = $d['name'] . '.zip';
                                    $this->makeZip($d['fullname'], $this->config['tmp_dir'], $this->zippassw, $zipname);
                                    $this->zip_list[] = $this->config['tmp_dir'] . '/' . $zipname;
                                    $this->connector->uploadFile($this->config['tmp_dir'] . '/' . $zipname);
                                }
                            }
                            else
                            {
                                foreach ($this->zip_list as $z)
                                {
                                    $this->connector->uploadFile($z, '');
                                }
                            }
                        }
                        else
                        {
                            if (!$this->zip_path && file_exists($this->backup_dir))
                            {
                                $this->makeZip($this->backup_dir, $this->config['tmp_dir'], $this->zippassw, '');
                                $this->zip_path = $this->config['tmp_dir'] . '/' . $this->zipname;
                                $this->connector->uploadFile($this->zip_path, '');
                            }

                            if ($conn_config['rd'] > 0)
                                $this->rotateRemoteZip($this->connector);
                        }
                    }
                    else 
                    {
                        $hasbase = $this->connector->checkBaseDir();

                        if (!$hasbase) //No base dir (uploading first time?), so upload all backup dirs, include base
                        {
                            $this->uploadBackupDirsWithConnector($this->connector, $conn_config, 1);
                        }
                        else
                        {
                            $this->connector->uploadDir($this->backup_dir);

                            if ($rd && ($m == 1))
                                $this->connector->rotate($conn_config['root_dir'], $rd);
                        }
                    }
                    
                    $this->connector->close();
                    $this->logger->write("End upload to connector {$after}", 2);
                }
                catch(\Exception $e)
                {
                    throw new \Exception($e->getMessage());
                }                   
            }    
            else
                break;
        }

        $this->clearTmpDirs();
    }
    
    public function download($mode)
    {
        $connector_class = $this->config['conn_type'].'Connector';        
        
        if (class_exists('Connectors\\'.$connector_class))
        {
           if (!file_exists($this->config['backup_dir']))
           {
              throw new \Exception("Backup directory ".$this->config['backup_dir']." does't exists");    
           }
            
           $conn = "Connectors\\{$connector_class}";
           $this->connector = new $conn($this->logger, $this->config);
        }
        else
           throw new \Exception("No connector class {$connector_class}");   
                        
        $this->connector->connect($this->config);
        $start_dir = isset($this->config['remote_dir'])?$this->config['remote_dir']: '';        

        $this->getFilesFromRemoteDir($start_dir, '', $mode);
        $this->connector->close();
        $this->logger->write("Download of {$this->project_name} complete", 1);
    }    
        
    protected  function getFilesFromRemoteDir($remote_dir, $savedir, $mode)
    {
        $lines = $this->connector->getFilesInDir($remote_dir);

        $savedir = ltrim($savedir, '/');
        
        if ($lines === false)
        {
           throw new \Exception("Cannot list {$remote_dir}");
        }

        $result = array();

        foreach ($lines as $item)
        {
            $remote_filepath = $remote_dir . "/" . $item['name'];

            if ($item['d'] == 1) //this is Directory
            {
                $newsavedir = $savedir.'/'.$item['name'];

                $excluded_arr = in_array($item['name'], $this->config['exclude_dirs']);
                if (($excluded_arr && $this->config['create_exclude_dirs']) || !$excluded_arr)
                {
                      $d = $this->backup_dir . '/' . $newsavedir;
                      if (!file_exists($d))
                      {
                          $r = mkdir($d);
                          if (!$r)
                          {
                              throw new \Exception("Cannot create {$d}. Check if remote_dir in backup.ini is correct.");
                          }
                      }
                }

                if ($excluded_arr)
                {
                   continue;
                }

                $this->getFilesFromRemoteDir($remote_filepath, $newsavedir, $mode);
            }
            else
            {
                $parts = preg_split("/\./", $item['name']);
                $ext = $parts[count($parts)-1];
                if (in_array($ext, $this->config['exclude_extensions']))
                    continue;

                $getf = 0;
                $dlm = $savedir?'/':'';
                
                $localfile = $this->backup_dir.'/'.$savedir.$dlm.$item['name'];

                if ($mode==2) // check file date
                {
                    $log = 0;
                    $getf  = $this->fs->checkFile($savedir.$dlm.$item['name'], $item['fulltime'], '', $log);
                }

                if ($getf || $mode==1)
                {
                    $this->connector->getFile($localfile, $remote_filepath);
                }
            }
        }
    }
         
    public function makeZip($path, $savedir, $passw, $zipname='')
    {
        $zipname = $zipname?$zipname:$this->project_name.'_'.date("Y-m-d").".zip";
        
        $this->zipname = $zipname;
        
        $fullpath = $savedir.'/'.$zipname;
        if (file_exists($fullpath))
            unlink($fullpath);
            
        $this->logger->write("Start creating zip ".$fullpath, 2);    
        $zip = new \ZipArchive;
        $zip->open($fullpath, \ZipArchive::CREATE);
        
        $setpassw = 0;
        if ($passw)
        {
            $zip->setPassword($passw);    
            $setpassw = 1;
        }
        
        $files = new \RecursiveIteratorIterator (new \RecursiveDirectoryIterator($path), 
        \RecursiveIteratorIterator::LEAVES_ONLY);
 
        $rlen  = mb_strlen($path, 'UTF-8') + 1;
        
        $n = 0;
        foreach ($files as $name => $file) 
        {
	        $filePath = $file->getRealPath();
            $fn = $file->getBasename();
            if ($fn=='..')
                continue;
                
            if (stripos($filePath, 'templates_c')!==false)
                continue;
                
            $relativePath = mb_substr($filePath, $rlen, NULL, 'UTF-8');
            $relativePath = str_replace('\\', '/', $relativePath);
            
            
            if ($relativePath)
            {
                if ($file->isDir())
                    $zip->addEmptyDir($relativePath);
                else    
                {
                   $zip->addFile($filePath, $relativePath);                
                   if ($setpassw)
                       $zip->setEncryptionName($relativePath, \ZipArchive::EM_AES_256);
                }
                    
            }
        }
        
        if (!$zip->close()) 
        {
        	throw new \Exception('There was a problem writing the ZIP archive.');
        } 
        else 
        {
            $this->logger->write("Creating zip complete", 2);    
        }
        
    }
}
?>
