<?php
/*
Copyright 2009-2014 Guillaume Boudreau

This file is part of Greyhole.

Greyhole is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Greyhole is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once('includes/CLI/CliCommandDefinition.php');
require_once('includes/CLI/CliOptionDefinition.php');

// Runners
require_once('includes/CLI/BalanceCliRunner.php');
require_once('includes/CLI/CancelBalanceCliRunner.php');
require_once('includes/CLI/CancelFsckCliRunner.php');
require_once('includes/CLI/CreateMemSpoolRunner.php');
require_once('includes/CLI/DebugCliRunner.php');
require_once('includes/CLI/DeleteMetadataCliRunner.php');
require_once('includes/CLI/EmptyTrashCliRunner.php');
require_once('includes/CLI/FixSymlinksCliRunner.php');
require_once('includes/CLI/FsckCliRunner.php');
require_once('includes/CLI/GetGUIDCliRunner.php');
require_once('includes/CLI/GoingCliRunner.php');
require_once('includes/CLI/GoneCliRunner.php');
require_once('includes/CLI/IoStatsCliRunner.php');
require_once('includes/CLI/LogsCliRunner.php');
require_once('includes/CLI/MD5WorkerCliRunner.php');
require_once('includes/CLI/PauseCliRunner.php');
require_once('includes/CLI/RemoveShareCliRunner.php');
require_once('includes/CLI/ResumeCliRunner.php');
require_once('includes/CLI/ReplaceCliRunner.php');
require_once('includes/CLI/StatsCliRunner.php');
require_once('includes/CLI/StatusCliRunner.php');
require_once('includes/CLI/TestCliRunner.php');
require_once('includes/CLI/ThawCliRunner.php');
require_once('includes/CLI/ViewQueueCliRunner.php');
require_once('includes/CLI/WaitForCliRunner.php');

class CommandLineHelper {
    protected $actionCmd = null;
    protected $options = array();
    protected $cliCommandsDefinitions;
    protected $cliOptionsDefinitions;
    
    function __construct() {
        $this->cliCommandsDefinitions = array(
            new CliCommandDefinition('help',             '?',   null,          null,                      "Display this help and exit."),
            new CliCommandDefinition('daemon',           'D',   null,          'DaemonRunner',            "Start the daemon."),
            new CliCommandDefinition('pause',            'P',   null,          'PauseCliRunner',          "Pause the daemon."),
            new CliCommandDefinition('resume',           'M',   null,          'ResumeCliRunner',         "Resume a paused daemon."),
            new CliCommandDefinition('fsck',             'f',   null,          'FsckCliRunner',           "Schedule a fsck."),
            new CliCommandDefinition('cancel-fsck',      'C',   null,          'CancelFsckCliRunner',     "Cancel any ongoing or scheduled fsck operations."),
            new CliCommandDefinition('balance',          'l',   null,          'BalanceCliRunner',        "Balance available space on storage pool drives."),
            new CliCommandDefinition('cancel-balance',   'B',   null,          'CancelBalanceCliRunner',  "Cancel any ongoing or scheduled balance operations."),
            new CliCommandDefinition('stats',            's',   null,          'StatsCliRunner',          "Display storage pool statistics."),
            new CliCommandDefinition('iostat',           'i',   null,          'IoStatsCliRunner',        "I/O statistics for your storage pool drives."),
            new CliCommandDefinition('logs',             'L',   null,          'LogsCliRunner',           "Display new greyhole.log entries as they are logged."),
            new CliCommandDefinition('status',           'S',   null,          'StatusCliRunner',         "Display what the Greyhole daemon is currently doing."),
            new CliCommandDefinition('view-queue',       'q',   null,          'ViewQueueCliRunner',      "Display the current work queue."),
            new CliCommandDefinition('empty-trash',      'a',   null,          'EmptyTrashCliRunner',     "Empty the trash."),
            new CliCommandDefinition('debug:',           'b:',  '=filename',   'DebugCliRunner',          "Debug past file operations."),
            new CliCommandDefinition('thaw::',           't::', '[=path]',     'ThawCliRunner',           "Thaw a frozen directory. Greyhole will start working on files inside <path>. If you don't supply an option, the list of frozen directories will be displayed."),
            new CliCommandDefinition('wait-for::',       'w::', '[=path]',     'WaitForCliRunner',        "Tell Greyhole that the missing drive at <path> will return soon, and that it shouldn't re-create additional file copies to replace it. If you don't supply an option, the available options (paths) will be displayed."),
            new CliCommandDefinition('gone::',           'g::', '[=path]',     'GoneCliRunner',           "Tell Greyhole that the missing drive at <path> is gone for good. Greyhole will start replacing the missing file copies instantly. If you don't supply an option, the available options (paths) will be displayed."),
            new CliCommandDefinition('going::',          'n::', '[=path]',      'GoingCliRunner',         "Tell Greyhole that you want to remove a drive. Greyhole will then make sure you don't loose any files, and that the correct number of file copies are created to replace the missing drive. If you don't supply an option, the available options (paths) will be displayed."),
            new CliCommandDefinition('replace::',        'r::', '[=path]',     'ReplaceCliRunner',        "Tell Greyhole that you replaced the drive at <path>."),
            new CliCommandDefinition('fix-symlinks',     'X',   null,          'FixSymlinksCliRunner',    "Try to find a good file copy to point to for all broken symlinks found on your shares."),
            new CliCommandDefinition('delete-metadata:', 'p:',  '=path',       'DeleteMetadataCliRunner', "Delete all metadata files for <path>, which should be a share name, followed by the path to a file that is gone from your storage pool. Eg. 'Movies/HD/The Big Lebowski.mkv'"),
            new CliCommandDefinition('remove-share:',    'U:',  '=share_name', 'RemoveShareCliRunner',    "Move the files currently inside the specified share from the storage pool into the shared folder (landing zone), effectively removing the share from Greyhole's storage pool."),
            new CliCommandDefinition('md5-worker',       '',    null,          null,                      null),
            new CliCommandDefinition('getuid',           'G',   null,          'GetGUIDCliRunner',        null),
            new CliCommandDefinition('create-mem-spool', '',    null,          'CreateMemSpoolRunner',    null),
            new CliCommandDefinition('test-config',      '',    null,          'TestCliRunner',           null),
        );
        
        $this->cliOptionsDefinitions = array(
            // For view-queue & stats
            'json'                     => new CliOptionDefinition('json',                     'j',  null,    "Output the result as JSON, instead of human-readable text."),

            // For fsck
            'email-report'             => new CliOptionDefinition('email-report',             'e',  null,    "Send an email when fsck completes, to report on what was checked, and any error that was found."),
            'dont-walk-metadata-store' => new CliOptionDefinition('dont-walk-metadata-store', 'y',  null,    "Speed up fsck by skipping the scan of the metadata store directories. Scanning the metadata stores is only required to re-create symbolic links that might be missing from your shared directories."),
            'if-conf-changed'          => new CliOptionDefinition('if-conf-changed',          'c',  null,    "Only fsck if greyhole.conf or smb.conf paths changed since the last fsck.\nUsed in the daily cron to prevent unneccesary fsck runs."),
            'dir'                      => new CliOptionDefinition('dir:',                     'd:', '=path', "Only scan a specific directory, and all sub-directories. The specified directory should be a Samba share, a sub-directory of a Samba share, or any directory on a storage pool drive."),
            'find-orphaned-files'      => new CliOptionDefinition('find-orphaned-files',      'o',  null,    "Scan for files with no metadata in the storage pool drives. This will allow you to include existing files on a drive in your storage pool without having to copy them manually."),
            'checksums'                => new CliOptionDefinition('checksums',                'k',  null,    "Read ALL files in your storage pool, and check that file copies are identical. This will identify any problem you might have with your file-systems.\nNOTE: this can take a LONG time to complete, since it will read everything from all your drives!"),
            'delete-orphaned-metadata' => new CliOptionDefinition('delete-orphaned-metadata', 'm',  null,    "When fsck find metadata files with no file copies, delete those metadata files. If the file copies re-appear later, you'll need to run fsck with --find-orphaned-files to have them reappear in your shares."),
            'disk-usage-report'        => new CliOptionDefinition('disk-usage-report',        'u',  null,    null),
            'drive'                    => new CliOptionDefinition('drive:',                   'R:', '=path', null),
        );
    }
    
    public function processCommandLine() {
        $command_line_options = $this->getopt($this->getOpts(), $this->getLongOpts());
        $this->actionCmd = $this->getActionCommand($command_line_options);
        $this->options = $this->getOptions($command_line_options);
        return $this->getRunner();
    }

    private function getActionCommand($command_line_options) {
        foreach ($this->cliCommandsDefinitions as $def) {
            $param = $def->paramSpecified($command_line_options);
            if ($param !== FALSE) {
                if ($param !== TRUE) {
                    $this->options['cmd_param'] = $param;
                }
                return $def;
            }
        }
        return null;
    }
    
    private function getOptions($command_line_options) {
        $options = $this->options;
        foreach ($this->cliOptionsDefinitions as $opt_name => $def) {
            $param = $def->paramSpecified($command_line_options);
            if ($param !== FALSE) {
                $options[$opt_name] = $param;
            }
        }
        return $options;
    }

    private function getRunner() {
        // No action specified on the command line; print usage help and exit.
        if (empty($this->actionCmd) || $this->actionCmd->getOpt() == 'help') {
            $this->printUsage();
            exit(0);
        }
        
        if ($this->actionCmd->getLongOpt() == 'md5-worker') {
            // Any forking needs to happen before DB::connect(), or the parent exiting will close the child's DB connection!
            $cliRunner = new MD5WorkerCliRunner($this->options);
        }

        if ($this->actionCmd->getLongOpt() == 'create-mem-spool') {
            // This can be executed during Greyhole install, so it needs to run before the config parsing runs (and fails)
            $cliRunner = new CreateMemSpoolRunner($this->options);
        } else {
            if ($this->actionCmd->getLongOpt() != 'test-config') {
                // Those will be tested in TestCliRunner
                process_config();
                DB::connect();
            }

            if (!isset($cliRunner)) {
                $cliRunner = $this->actionCmd->getNewRunner($this->options);
                if ($cliRunner === FALSE) {
                    $this->printUsage();
                    exit(0);
                }
            }
        }
        
        if (!$cliRunner->canRun()) {
            echo "You need to execute this as root.\n";
            exit(1);
        }
        
        Log::setAction($this->actionCmd->getLongOpt());

        return $cliRunner;
    }
    
    private function printUsage() {
        echo "greyhole, version %VERSION%, for linux-gnu (noarch)\n";
        echo "This software comes with ABSOLUTELY NO WARRANTY. This is free software,\n";
        echo "and you are welcome to modify and redistribute it under the GPL v3 license.\n";
        echo "\n";

        echo "Usage: greyhole [ACTION] [OPTIONS]\n";
        echo "\n";

        echo "Where ACTION is one of:\n";
        foreach ($this->cliCommandsDefinitions as $def) {
            echo $def->getUsage();
        }
        echo "\n";

        echo "For --stats and --view-queue, the available OPTIONS are:\n";
        echo $this->cliOptionsDefinitions['json']->getUsage();
        echo "\n";

        echo "For --fsck, the available OPTIONS are:\n";
        foreach ($this->cliOptionsDefinitions as $opt_name => $def) {
            if ($opt_name != 'json') {
                echo $def->getUsage();
            }
        }
    }
    
    private function getOpts() {
        $opts = '';
        foreach ($this->cliCommandsDefinitions as $def) {
            $opts .= $def->getOpt();
        }
        foreach ($this->cliOptionsDefinitions as $def) {
            $opts .= $def->getOpt();
        }
        return $opts;
    }

    private function getLongOpts() {
        $long_opts = array();
        foreach ($this->cliCommandsDefinitions as $def) {
            $long_opts[] = $def->getLongOpt();
        }
        foreach ($this->cliOptionsDefinitions as $def) {
            $long_opts[] = $def->getLongOpt();
        }
        return $long_opts;
    }

    protected function getopt() {

    /* _getopt(): Ver. 1.3      2009/05/30
       My page: http://www.ntu.beautifulworldco.com/weblog/?p=526

    Usage: _getopt ( [$flag,] $short_option [, $long_option] );

    Note that another function split_para() is required, which can be found in the same
    page.

    _getopt() fully simulates getopt() which is described at
    http://us.php.net/manual/en/function.getopt.php , including long options for PHP
    version under 5.3.0. (Prior to 5.3.0, long options was only available on few systems)

    Besides legacy usage of getopt(), I also added a new option to manipulate your own
    argument lists instead of those from command lines. This new option can be a string
    or an array such as 

    $flag = "-f value_f -ab --required 9 --optional=PK --option -v test -k";
    or
    $flag = array ( "-f", "value_f", "-ab", "--required", "9", "--optional=PK", "--option" );

    So there are four ways to work with _getopt(),

    1. _getopt ( $short_option );

      it's a legacy usage, same as getopt ( $short_option ).

    2. _getopt ( $short_option, $long_option );

      it's a legacy usage, same as getopt ( $short_option, $long_option ).

    3. _getopt ( $flag, $short_option );

      use your own argument lists instead of command line arguments.

    4. _getopt ( $flag, $short_option, $long_option );

      use your own argument lists instead of command line arguments.

    */

      if ( func_num_args() == 1 ) {
         $flag =  $flag_array = $GLOBALS['argv'];
         $short_option = func_get_arg ( 0 );
         $long_option = array ();
         return getopt($short_option);
      } else if ( func_num_args() == 2 ) {
         if ( is_array ( func_get_arg ( 1 ) ) ) {
            $flag = $GLOBALS['argv'];
            $short_option = func_get_arg ( 0 );
            $long_option = func_get_arg ( 1 );
            // if (PHP_VERSION_ID >= 50300) { return getopt($short_option, $long_option); }
         } else {
            $flag = func_get_arg ( 0 );
            $short_option = func_get_arg ( 1 );
            $long_option = array ();
            return getopt($short_option);
         }
      } else if ( func_num_args() == 3 ) {
         $flag = func_get_arg ( 0 );
         $short_option = func_get_arg ( 1 );
         $long_option = func_get_arg ( 2 );
         // if (PHP_VERSION_ID >= 50300) { return getopt($short_option, $long_option); }
      } else {
         exit ( "wrong options\n" );
      }

      $short_option = trim ( $short_option );

      $short_no_value = array();
      $short_required_value = array();
      $short_optional_value = array();
      $long_no_value = array();
      $long_required_value = array();
      $long_optional_value = array();
      $options = array();

      for ( $i = 0; $i < strlen ( $short_option ); ) {
         if ( $short_option{$i} != ":" ) {
            if ( $i == strlen ( $short_option ) - 1 ) {
              $short_no_value[] = $short_option{$i};
              break;
            } else if ( $short_option{$i+1} != ":" ) {
              $short_no_value[] = $short_option{$i};
              $i++;
              continue;
            } else if ( $short_option{$i+1} == ":" && @$short_option{$i+2} != ":" ) {
              $short_required_value[] = $short_option{$i};
              $i += 2;
              continue;
            } else if ( $short_option{$i+1} == ":" && $short_option{$i+2} == ":" ) {
              $short_optional_value[] = $short_option{$i};
              $i += 3;
              continue;
            }
         } else {
            continue;
         }
      }

      foreach ( $long_option as $a ) {
         if ( substr( $a, -2 ) == "::" ) {
            $long_optional_value[] = substr( $a, 0, -2);
            continue;
         } else if ( substr( $a, -1 ) == ":" ) {
            $long_required_value[] = substr( $a, 0, -1 );
            continue;
         } else {
            $long_no_value[] = $a;
            continue;
         }
      }

      if ( is_array ( $flag ) )
         $flag_array = $flag;
      else {
         $flag = "- $flag";
         $flag_array = $this->split_para( $flag );
      }

      for ( $i = 0; $i < count( $flag_array ); ) {

         if ( $i >= count ( $flag_array ) )
            break;

         if ( ! $flag_array[$i] || $flag_array[$i] == "-" ) {
            $i++;
            continue;
         }

         if ( $flag_array[$i]{0} != "-" ) {
            $i++;
            continue;

         }

         if ( substr( $flag_array[$i], 0, 2 ) == "--" ) {

            if (strpos($flag_array[$i], '=') != false) {
              list($key, $value) = explode('=', substr($flag_array[$i], 2), 2);
              if ( in_array ( $key, $long_required_value ) || in_array ( $key, $long_optional_value ) )
                 $options[$key][] = $value;
              $i++;
              continue;
            }

            if (strpos($flag_array[$i], '=') == false) {
              $key = substr( $flag_array[$i], 2 );
              if ( in_array( substr( $flag_array[$i], 2 ), $long_required_value ) ) {
                 $options[$key][] = $flag_array[$i+1];
                 $i += 2;
                 continue;
              } else if ( in_array( substr( $flag_array[$i], 2 ), $long_optional_value ) ) {
                 if ( $flag_array[$i+1] != "" && $flag_array[$i+1]{0} != "-" ) {
                    $options[$key][] = $flag_array[$i+1];
                    $i += 2;
                 } else {
                    $options[$key][] = FALSE;
                    $i ++;
                 }
                 continue;
              } else if ( in_array( substr( $flag_array[$i], 2 ), $long_no_value ) ) {
                 $options[$key][] = FALSE;
                 $i++;
                 continue;
              } else {
                 $i++;
                 continue;
              }
            }

         } else if ( $flag_array[$i]{0} == "-" && $flag_array[$i]{1} != "-" ) {

            for ( $j=1; $j < strlen($flag_array[$i]); $j++ ) {
              if ( in_array( $flag_array[$i]{$j}, $short_required_value ) || in_array( $flag_array[$i]{$j}, $short_optional_value )) {

                 if ( $j == strlen($flag_array[$i]) - 1  ) {
                    if ( in_array( $flag_array[$i]{$j}, $short_required_value ) ) {
                      $options[$flag_array[$i]{$j}][] = $flag_array[$i+1];
                      $i += 2;
                    } else if ( in_array( $flag_array[$i]{$j}, $short_optional_value ) && $flag_array[$i+1] != "" && $flag_array[$i+1]{0} != "-" ) {
                      $options[$flag_array[$i]{$j}][] = $flag_array[$i+1];
                      $i += 2;
                    } else {
                      $options[$flag_array[$i]{$j}][] = FALSE;
                      $i ++;
                    }
                    $plus_i = 0;
                    break;
                 } else {
                    $options[$flag_array[$i]{$j}][] = substr ( $flag_array[$i], $j + 1 );
                    $i ++;
                    $plus_i = 0;
                    break;
                 }

              } else if ( in_array ( $flag_array[$i]{$j}, $short_no_value ) ) {

                 $options[$flag_array[$i]{$j}][] = FALSE;
                 $plus_i = 1;
                 continue;

              } else {
                 $plus_i = 1;
                 break;
              }
            }

            $i += $plus_i;
            continue;

         }

         $i++;
         continue;
      }

      foreach ( $options as $key => $value ) {
         if ( count ( $value ) == 1 ) {
            $options[ $key ] = $value[0];

         }

      }

      return $options;

    }

    protected function split_para ( $pattern ) {

    /* split_para() version 1.0      2008/08/19
       My page: http://www.ntu.beautifulworldco.com/weblog/?p=526

    This function is to parse parameters and split them into smaller pieces.
    preg_split() does similar thing but in our function, besides "space", we
    also take the three symbols " (double quote), '(single quote),
    and \ (backslash) into consideration because things in a pair of " or '
    should be grouped together.

    As an example, this parameter list

    -f "test 2" -ab --required "t\"est 1" --optional="te'st 3" --option -v 'test 4'

    will be splited into

    -f
    t"est 2
    -ab
    --required
    test 1
    --optional=te'st 3
    --option
    -v
    test 4

    see the code below,

    $pattern = "-f \"test 2\" -ab --required \"t\\\"est 1\" --optional=\"te'st 3\" --option -v 'test 4'";

    $result = split_para( $pattern );

    echo "ORIGINAL PATTERN: $pattern\n\n";

    var_dump( $result );

    */

      $begin=0;
      $backslash = 0;
      $quote = "";
      $cand1 = "";
      $quote_mark = array();
      $result = array();

      $pattern = trim ( $pattern );

      for ( $end = 0; $end < strlen ( $pattern ) ; ) {

         if ( ! in_array ( $pattern{$end}, array ( " ", "\"", "'", "\\" ) ) ) {
            $backslash = 0;
            $end ++;
            continue;
         }

         if ( $pattern{$end} == "\\" ) {
            $backslash++;
            $end ++;
            continue;
         } else if ( $pattern{$end} == "\"" ) {
            if ( $backslash % 2 == 1 || $quote == "'" ) {
              $backslash = 0;
              $end ++;
              continue;
            }

            if ( $quote == "" ) {
              $quote_mark[] = $end - $begin;
              $quote = "\"";
            } else if ( $quote == "\"" ) {
              $quote_mark[] = $end - $begin;
              $quote = "";
            }

            $backslash = 0;
            $end ++;
            continue;
         } else if ( $pattern{$end} == "'" ) {
            if ( $backslash % 2 == 1 || $quote == "\"" ) {
              $backslash = 0;
              $end ++;
              continue;
            }

            if ( $quote == "" ) {
              $quote_mark[] = $end - $begin;
              $quote = "'";
            } else if ( $quote == "'" ) {
              $quote_mark[] = $end - $begin;
              $quote = "";
            }

            $backslash = 0;
            $end ++;
            continue;
         } else if ( $pattern{$end} == " " ) {
            if ( $quote != "" ) {
              $backslash = 0;
              $end ++;
              continue;
            } else {
              $backslash = 0;
              $cand = substr( $pattern, $begin, $end-$begin );
              for ( $j = 0; $j < strlen ( $cand ); $j ++ ) {
                 if ( in_array ( $j, $quote_mark ) )
                    continue;

                 $cand1 .= $cand{$j};
              }
              if ( $cand1 ) {
                 eval( "\$cand1 = \"$cand1\";" );
                 $result[] = $cand1;
              }
              $quote_mark = array();
              $cand1 = "";
              $end ++;
              $begin = $end;
              continue;
           }
         }
      }

      $cand = substr( $pattern, $begin, $end-$begin );
      for ( $j = 0; $j < strlen ( $cand ); $j ++ ) {
         if ( in_array ( $j, $quote_mark ) )
            continue;

         $cand1 .= $cand{$j};
      }

      eval( "\$cand1 = \"$cand1\";" );

      if ( $cand1 )
         $result[] = $cand1;

      return $result;
    }
}

?>
