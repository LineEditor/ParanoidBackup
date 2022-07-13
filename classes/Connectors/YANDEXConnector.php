<?php

namespace Connectors;

/**
*  Connector to Yandex.disc
*/
class YANDEXConnector extends BaseConnector
{
    private $conn;
    private $free_space;

    public function __construct($logger, $config, $after = '')
    {     
        parent::__construct($logger, $config, $after);
        $this->delete_internal_dirs_when_rotating = false;
    }
        
    public function connect($work_mode = 0)
    {
        $config  = $this->config;
        if (!array_key_exists('tk', $config))
        {
           throw new \Exception("Field tk is not defined in {$this->after_name} in ini file");     
        }
        
        $this->config = $config;

        if (!function_exists('curl_init'))
            throw new \Exception("Curl module required. Please, edit \"Dynamic Extensions\" section in ".php_ini_loaded_file());

        //Establish connection
        $this->conn = curl_init();

        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/');
        curl_setopt($this->conn, CURLOPT_HTTPHEADER, array('Authorization: OAuth ' . $config['tk']));
        curl_setopt($this->conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->conn, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->conn, CURLOPT_HEADER, false);
        curl_setopt($this->conn, CURLOPT_UPLOAD, false);
        
        $res = curl_exec($this->conn);
        $res = json_decode($res, true);
        
        if (array_key_exists('error', $res))
        {
            $this->close();
            throw new \Exception("Error connection to Yandex.disk ({$this->after_name}): ".$res['error']); 
        }

        $this->free_space = intval($res['total_space']) - intval($res['trash_size']) - intval($res['used_space']); 
        $this->logger->write("Connected to Yandex.disk ", 2); 
    }
   

    public function uploadFile($file, $uploaddir, $check = false, $mode = 1)
    {
        if ($check && !file_exists($file))
            return false;
            
        // Get URL for upload            
        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources/upload?path=' . urlencode( $this->cur_dir . $uploaddir. '/'.
                        basename($file))."&overwrite=true");
        curl_setopt($this->conn, CURLOPT_HTTPGET, true);
        $res = curl_exec($this->conn);
        
        
        $res = json_decode($res, true);

        if (empty($res['error']))
        {
        	$fp = fopen($file, 'r');
     
     	    
            curl_setopt($this->conn, CURLOPT_URL, $res['href']);
                                     
        	curl_setopt($this->conn, CURLOPT_PUT, true);
	        curl_setopt($this->conn, CURLOPT_UPLOAD, true);
        	curl_setopt($this->conn, CURLOPT_INFILESIZE, filesize($file));
	        curl_setopt($this->conn, CURLOPT_INFILE, $fp);

	        curl_exec($this->conn);
        
        	$http_code = curl_getinfo($this->conn, CURLINFO_HTTP_CODE);
	        
            fclose($fp);
            curl_setopt($this->conn, CURLOPT_INFILE, STDIN);
     
        	if ($http_code == 201) 
            {
                //$this->free_space -= filesize($file);
                $fulld = $this->cur_dir . $uploaddir;
                $this->logger->write("File {$file} uploaded to {$fulld}", 3);
                return true;
            }
            else
            {
                $this->logger->write("Error upload file {$file} to Yandex.Disk, http_code={$http_code}");
                return false;
            }
                
        }   
        else
        {
            $this->logger->write("Error upload file {$file} to Yandex.Disk: ".$res['error']);
        }      
    }
    
    function checkDir($dir)
    {
      
        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources?path='.urlencode($dir));
        curl_setopt($this->conn, CURLOPT_HTTPGET, true);
        curl_setopt($this->conn, CURLOPT_UPLOAD, false);

        $res = curl_exec($this->conn);
        
        $res = json_decode($res, true);
        
        if (!$res || array_key_exists('error', $res))
        {
            return false;
        }        
        
        return true;
    }

    function checkFile($file)
    {
        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources?path='.urlencode($file));
        curl_setopt($this->conn, CURLOPT_HTTPGET, true);
        curl_setopt($this->conn, CURLOPT_UPLOAD, false);

        $res = curl_exec($this->conn);

        $res = json_decode($res, true);

        if (!$res || array_key_exists('error', $res))
        {
            return false;
        }

        return true;
    }

    function createDir($dir, $check = false, $isMain = false)
    {
        $this->logger->write("Creating directory {$dir} ", 3);

        curl_reset($this->conn); 
        curl_setopt($this->conn, CURLOPT_HTTPHEADER, array('Authorization: OAuth ' . $this->config['tk']));
        curl_setopt($this->conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->conn, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->conn, CURLOPT_HEADER, false);
        curl_setopt($this->conn, CURLOPT_UPLOAD, false);    
        
        $fp = '';
        if ($isMain)
        {
            curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources?path='.urlencode($dir));
            curl_setopt($this->conn, CURLOPT_PUT, true);
                        
            $res = curl_exec($this->conn);
            $res = json_decode($res, true);
 
            
            if (array_key_exists('error', $res))
            {
                if ($res['error'] == 'DiskPathPointsToExistentDirectoryError')
                    return;
                else    
                    throw new \Exception("Error creating directory {$fp} ({$dir}) in Yandex.disk ({$this->after_name}): ".$res['error']); 
            }  
            return;
        }
        
        $fp = $this->cur_dir;
        $srcdir = trim($dir, '/');

        $parts = preg_split("/\//", $srcdir);

        foreach($parts as $p)
        {
            if (!$p)
                continue;
            
            $dlm = $fp?'/':'';
            $fp .= $dlm.trim($p);
            
            if ($check && $this->checkDir($fp))
            {
                continue;
            }
            curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources?path='.urlencode($fp));
            curl_setopt($this->conn, CURLOPT_PUT, true);
                        
            $res = curl_exec($this->conn);
            $res = json_decode($res, true);
 
            
            if (array_key_exists('error', $res))
            {
                if ($res['error'] == 'DiskPathPointsToExistentDirectoryError')
                    continue;
                else    
                    throw new \Exception("Error creating directory {$fp} ({$dir}) in Yandex.disk ({$this->after_name}): ".$res['error']); 
            }               
        }
    }


    public function close()
    {
        curl_close($this->conn);
        parent::close();
    }
    
   
    public function getFile($localfile, $remotefile)
    {
        $this->logger->write("downloading {$remotefile} to {$localfile}", 3);

        curl_setopt($this->conn, CURLOPT_RETURNTRANSFER, true);        
        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources/download?path=' . urlencode($remotefile));
        curl_setopt($this->conn, CURLOPT_HEADER, false);
        $res = curl_exec($this->conn);

        $res = json_decode($res, true);
            
        if (empty($res['error'])) 
        {
           	$file = fopen($localfile, 'w+');


            curl_setopt($this->conn, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->conn, CURLOPT_FILE, $file);
            curl_setopt($this->conn, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($this->conn, CURLOPT_URL, $res['href']);

            curl_setopt($this->conn, CURLOPT_FAILONERROR, true);
    	
        
            $dload = curl_exec($this->conn);
            //$statusCode = curl_getinfo($this->conn, CURLINFO_HTTP_CODE);
        
            if (!$dload)
            {
                $this->logger->write("Error download file {$remotefile}: ".curl_error($this->conn));
            }

        }
        else
        {
            $this->logger->write("Error download file {$remotefile}: ".$res['error']);
        }
    }
    
    public function moveFile($srcfile, $dest)
    {
        $this->logger->write("remote move {$srcfile} to {$dest} \n", 3);

        curl_setopt($this->conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->conn, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->conn, CURLOPT_UPLOAD, false);
                
        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources/move?from='.urlencode($srcfile).'&path='.$dest.'&overwrite=true');
        curl_setopt($this->conn, CURLOPT_POST, true);
        curl_exec($this->conn);
    }

    public function deleteDir($dir)
    {
        $this->logger->write("deleting dir {$dir}", 3);
        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources?path='.urlencode($dir));
        curl_setopt($this->conn, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($this->conn, CURLOPT_UPLOAD, false);        
        curl_exec($this->conn);
    }
    
    public function deleteFile($file)
    {
        $this->logger->write("deleting file {$file}", 3);

        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources?path='.urlencode($file));
        curl_setopt($this->conn, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($this->conn, CURLOPT_UPLOAD, false);        
        curl_exec($this->conn);
    }
        
   
    public function getFilesInDir($remote_dir)
    {
        $limit = 10000;

        curl_setopt($this->conn, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->conn, CURLOPT_URL, 'https://cloud-api.yandex.net/v1/disk/resources?path='.urlencode($remote_dir).'&limit='.$limit);

        curl_setopt($this->conn, CURLOPT_HTTPGET, true);
        curl_setopt($this->conn, CURLOPT_UPLOAD, false);
        curl_setopt($this->conn, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->conn, CURLOPT_HEADER, false);

        $res = curl_exec($this->conn);
    
        $res = json_decode($res, true);
    
        if (isset($res['error']))
        {
            die($res['error']);    
        }
        
        $files = array();
        
        if (is_array($res) && array_key_exists('_embedded', $res))
        {
          foreach ($res['_embedded']['items']  as $v)
          {
            $item = array();
            
            $item['name'] = $v['name'];
            $item['d'] = $v['type']=='dir'?1:0;    
            $item['size']   = isset($v['size'])?$v['size']:0;     
            $item['fulltime'] = strtotime($v['modified']);
            $item['rights'] = '';
            $item['nlink'] = 1; 
            $item['user']   = 0;  
            $item['group']  = 0;   
                
            $files[] = $item;
          }                     
        }    
        return $files;    
    }    
}

?>