### ParanoidBackup v1.0
https://github.com/LineEditor/ParanoidBackup

# About

ParanoidBackup is an open-source backup system written in PHP. 
It requires PHP version 7.0 or higher. The curl and ftp modules are required.   

# System features 
- Copying files from such sources as local directory, FTP, SFTP (SSH File Transfer Protocol), Yandex.disk and further
  copying of the saved backups to an unlimited number of storages that support these protocols.
- Incremental backup. At the first start on the current day, a directory is created with a name equal to the current date in the YYYY-MM-DD format.
  And only new files and files that were changed since the previous day's launch are added to this directory.
- Ability to ignore files in the source by specifying the directory where they are located and/or their extensions  
- Rotation of directories storing versions of backup files with a specified periodicity 
 
# Getting started
- Create a directory with your project name in the conf directory (one project - one conf directory).
- Copy the backup.ini file from conf/example into it. Delete the conf/example directory, or set enabled=0 in conf/example/backup.ini. 
- Specify in backup.ini all the necessary settings. This file has all the necessary explanations.
- Run the script with the command *php pbackup.php* or with the command php pbackup.php -p=<your_directory_in_folder_conf> if you want
  create a backup for just this project.

 

# How ParanoidBackup works   
  At the very first run, a base directory is created in the backup_dir directory specified in backup.ini, where all the files from the remote_dir are copied to. 
  If you run ParanoidBackup again on the same day, all files are copied to the base again in their entirety.
  In order to have ParanoidBackup run on schedule, you should add a call to *php pbackup.php* in the task scheduler. 
  So base is the main directory where all the files that were in the project when ParanoidBackup was first started are stored.
   
    
  If the base directory already exists and the start date of ParanoidBackup is different from the date the base directory was created, then the **create
  YYYY-mm-dd** directory is created and only new or changed files from remote_dir are copied into it.
  Thus, every new startup day ParanoidBackup creates a new directory whose name equals the startup date in the format
  YYYY-mm-dd, and this directory contains only new or changed files. **If there are no new or changed files, the directory
  is not created**.
   
  
  If the [afterbackup] section is filled, then after the files are copied to the backup directory, they will be sent to the storages specified in this section.  
  
  That is, the following scenario is possible:
  Downloaded the files from FTP to your computer or backup server, and then sent them to another FTP or Yandex.Disk, 
  or to another directory on the same server. If the m parameter in the connection settings is set to three, the files will be uploaded to the receiver
  as a zip archive. If zippassword is specified, the archive will be locked with that password. 
  

  All directories older than the current ParanoidBackup start date minus rotate_days (specified in the same backup.ini) will be deleted 
  and their contents will be copied to base, replacing the files. 
  Thus, versions of files created before *current date minus rotate_days*, and the directories named yyyyy-mm-dd contain everything created after *current date minus rotate_days*. 
  
# Creating a full backup  

To get all the recent versions of the files in the base directory without removing YYYYY-MM-DD directories, run   

php pbackup.php -c=cfb 

possible additional parameters

-p - names of projects (directories) from the conf folder, which will be processed, separated by commas.
If not specified, then all will be processed

-d -date, till which backup directories, named by dates, will be counted. 
For example, if we have directories

base
2022-01-10
2022-01-15
2022-01-16
2022-01-18
2022-01-20

then the command
php pbackup.php -c=cfb -p=site.ru -d=2022-01-16

will create a complete backup of the project site.ru, directories 2022-01-10, 2022-01-15, 2022-01-16 will be taken into account, and directories
2022-01-18 and 2022-01-20 will not be included.

Thus, in the base there will be no files, which date of creation is newer than 2022-01-16.

### Examples
If you want to also create an archive with these files:

* To create an archive in the project directory with the name project_YYYY_MM_DD.zip:

  php pbackup.php -c=cfb -p=site.ru -zip

* To create an archive in the project directory with the specified name
  
  php pbackup.php -c=cfb -p=site.ru -zip=myname.zip

* To create the archive in the specified directory with the specified name
  
  php pbackup.php -c=cfb -p=site.ru -zip=myname.zip -dir=c:/backup
  
* To create an archive in the specified directory, with the specified name and a password.
  
  php pbackup.php -c=cfb -p=site.ru -zip=myname.zip -zippassw=12345 -dir=c:/backup
  
## Other options for starting ParanoidBackup

* Show version of the program:

  php pbackup.php -v 

* Call help: 

  php pbackup.php -h 
 

If for some reason you don't want to run ParanoidBackup in the standard mode with executing all scenarios described in backup.ini, you can run it in a mode that allows you to run only some of them.

### Loading the backup directories in the stores prescribed in the afterbackup section

php pbackup.php -c=upl [-all] [-p=<project list>]

Possible parameters

-all - copy all directories, including base

**If parameter -all is not given: **.

In this case, only directories that are not in the repository are copied.

In this case, files from the last directory with a backup are always loaded, even if there is already such a directory in the repository.

Suppose your actions are as follows:
- You ran the backup script today, it created a directory, the name of which is equal to the current date -
for example 2022-01-22. 
- After that you ran the command *php pbackup.php -c=upl*.
- That same day we ran ParanoidBackup in backup mode again and it added the new files in the directory 2022-01-22
- Run *php pbackup.php -c=upl* again on the same day - all the files in directory 2022-01-22 will be uploaded to the repository.

Another option:
- Today is 2022-01-21, you have the 2022-01-21 backup directory and you uploaded it to the repository 
- Then you run ParanoidBackup again in normal mode and you have new files in the 2022-01-21 directory
- The next day is 2022-01-22, you have created directory 2022-01-22 with new files.
- You start *php pbackup.php -c=upl* - this will not upload the new files from the previous 2022-01-21 directory to the server, 
  only files from 2022-01-22 will be loaded

## Directory rotation in the remote repository

If you want to force directory rotation in remote storage, as configured in [afterbackup] block,
then you have to run the following command:

php pbackup.php -c=rotr -p=<project list> -conn=<connector numbers, comma separated without a space

Here, "connectors" are connection settings in the after_1, after_2, etc. variables.

Each connector has a parameter rd which is how many days the directories will be kept. After this number of days directories, whose age is already more than number of days from parameter rd are removed, and files from them transferred to /base.

Example. 

Today is 2022-01-23.
We have backup directories with the following names:

2022-01-10, 2022-01-12, 2022-01-13, 2022-01-14, 2022-01-15, 2022-01-16, 2022-01-18, 2022-01-20, 2022-01-22

Parameter rd = 5. That is, since today is the 23rd day, we leave only directories that are created from the 2022-01-18 and later.

So during rotation, directories 2022-01-18, 2022-01-20, 2022-01-22 will remain, and the rest will be deleted, and the files from them will be transferred to the base

## Rotation of zip files in the remote repository

php pbackup.php -c=rotr_zip -p=<project list> -conn=<connector numbers, comma separated without a space

This operation creates a copy of backup_dir in a temporary directory, rotates files inside this temporary directory
(copying files whose age is greater than the specified one from directories YYYYY-MM-DD to the directory base), creating the archive containing the base directory, and uploads this archive to the remote storage. 
Preliminarily the zip-archives containing the directories YYYY-MM-DD are deleted from the remote storage whose age is greater than the current date minus the number of days specified in the connector rd parameter.   



 

