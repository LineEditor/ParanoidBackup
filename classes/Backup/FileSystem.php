<?php

namespace Backup;

class FileSystem
{
    private $backup_dirs;
    private $cur_backupdir;
    
    public function __construct($backupdirs = array(), $cur_backupdir = '')
    {
        $this->backup_dirs = $backupdirs;
        $this->cur_backupdir = $cur_backupdir;
    }        

    public function setBackup_dirs($backupdirs)
    {
        $this->backup_dirs = $backupdirs;
    } 
        
    function checkFile($localfile, $remote_ftime, $excludepath='', $log=0)
    {
        if ($excludepath)
            $excludepath = rtrim($excludepath, '/');
        
        foreach ($this->backup_dirs as $d)
        {
            if ($d['fullname'] == $excludepath)
                continue;
                
            if (substr($localfile, 0, 1) != '/')    
            {
                $localfile = '/'.$localfile;
            }
            
            if ($log)
            {
                echo "chF: ".$d['fullname'].$localfile."\n";    
            }
            
                
            if (file_exists($d['fullname'].$localfile))
            {
                if ($log)
                    echo "exists: ".$d['fullname'].$localfile."\n\n";
                    
                if ($remote_ftime > 0)
                {
                    $ftime = filemtime($d['fullname'].$localfile);
                
                    if ($remote_ftime > $ftime)
                        return 1;
                    else
                        return 0;    
                }
                else //file or directory exists
                    return 0;
            }
        }
        
        //file or directory was't found in another backup dirs, returning 1'
        //1 means, that file or directory must be saved
        return 1;
    }
    
    function file_date_sort($a, $b)
    {
        return $a['dateint'] >= $b['dateint']?-1:1;
    }

    
    function getFileList($dir, $mode = 1, $time_from_name = 1)
    {
        $files = array();
        
        if ($handle = opendir($dir))
        {
            $cnt = 0;
        
            while (false !== ($file = readdir($handle)))
            {
                $fullpath =  $dir.'/'.$file;
                

                if (($file=='.')  || ($file=='..'))
                    continue;

                if (is_dir($fullpath))
                    $type = 'd';
                else
                    $type = 'f';


                //mode = 0 - directory and files
                //mode = 1 - only directories
                //mode = 2 - only files
                
                if (( ($mode == 1 || $mode == 3)  && $type=='f') || ($mode == 2 && $type=='d'))
                    continue;
                    
                //$filetime = filemtime($fullpath);
                if ($mode==3)
                {
                    $files[] = $file;
                }
                else
                {
                    if ($type == 'd' && $time_from_name)
                        $filetime = strtotime($file);
                    else
                        $filetime = filemtime($fullpath);
                        
                    $files[] = array('n'=>'a', 'name'=>$file, 'date'=>date("Y-m-d H:i:s", $filetime), 'dateint' => $filetime,
                             'size'=>filesize($fullpath), 'type'=>$type, 'fullname' => $fullpath);
                }
            }                     
        }            
        closedir($handle);
        
        if ($mode != 3)
            usort($files, array($this, "file_date_sort"));
        
        return $files;
    }

    function recursiveRmDir($dir)
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $filename => $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($filename);
            } else {
                unlink($filename);
            }
        }

        rmdir($dir);
    }

    function copyDirs($from, $dest, $ignoredirs, $savetime = 0)
    {
        $dirs = $this->getFileList($from, 1);

        foreach ($dirs as $d)
        {
            if (!in_array($d['fullname'], $ignoredirs))
            {
                mkdir($dest.'/'.$d['name']);
                $this->recursiveCopy($d['fullname'], $dest.'/'.$d['name'], $ignoredirs, $savetime);
            }
        }
    }

    function recursiveCopy($from, $dest, $ignoredirs, $savetime = 0)
    {
        $ignore = false;
        $ignoredpath = '';
        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator($from, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator  as $item)
        {
            $pathname  = preg_replace("/\\\\/u", '/', $iterator->getPathName());

            if ($item->isDir())
            {
                if (in_array($pathname, $ignoredirs))
                {
                    $ignoredpath = $pathname;
                    continue;
                }
                else
                {
                    if ($ignoredpath && mb_stripos($pathname, $ignoredpath,  0, 'UTF-8')===FALSE)
                        $ignoredpath = '';

                }

                if (!$ignoredpath)
                {
                    mkdir($dest . '/'. $iterator->getSubPathName());
                }
            }
            else
            {
                if (!$ignoredpath)
                {

                    copy($item, $dest . '/'. $iterator->getSubPathName());

                    if ($savetime)
                    {
                        $filetime = filemtime($item);
                        $res =  touch($dest . '/'. $iterator->getSubPathName(), $filetime);
                    }
                }
            }
        }
    }
}
?>