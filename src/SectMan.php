<?php
namespace dynoser\sectman;
use dynoser\HELML\HELMLdecoder as HELML;
use dynoser\HELML\fileHELMLsect;

class SectMan {
    public $ssrc_file;
    public $base_dir;
    
    public $sectman_prefix_str = '###';
    
    // SectMan header key name (SectMan root)
    public $sectman_header_key = 'SectMan';
    
    // parameter 'outdir' in SectMan
    public $out_dir_key = 'outdir';
    public $outdir_path;
    
    // parameter 'sections' in SectMan
    public $out_sections_key = 'sections';
    public $out_sections_arr;
    
    // parameter 'replaces' in SectMan
    public $replaces_key = 'replaces';
    public $replaces_arr = [];
    
    // parameter 'lastwrite' in SectMan
    public $do_auto_push_last_key = 'lastwrite';
    public $do_auto_push_last_buff = true;

    
    public $sect_lines_arr;
    public $sect_results_arr = [];
    
    
    public function __construct($file_name, $base_dir = null) {
        $this->ssrc_file = realpath($file_name);
        
        if (!$this->ssrc_file) {
            throw new \Exception("Not found sect-source file: $file_name");
        }
        
        // Calclualte BaseDir
        if (!$base_dir) {
            $base_dir = realpath(dirname($file_name));
        }

        $this->base_dir = realpath($base_dir);
        if (!$this->base_dir || !is_dir($this->base_dir)) {
            return "BaseDir folder must be specified corretly!";
        }

    }
    
    public function load() {        
        
        fileHELMLsect::$add_section_comments = false;
        $sect_lines_arr = fileHELMLsect::_LoadSections($this->ssrc_file, 0, false, $this->sectman_prefix_str);
        if (!$sect_lines_arr) {
            return "No sections found in this code. Search prefix: " . $this->sectman_prefix_str;
        }

        $header_arr = HELML::decode(implode("\n", $sect_lines_arr));
        
        if (!array_key_exists($this->sectman_header_key, $header_arr)) {
            return "Not found SectMan Header by key: " . $this->sectman_header_key;
        }
        $sectman_arr = $header_arr[$this->sectman_header_key];
        if (!is_array($sectman_arr)) {
            return "[{$$this->sectman_header_key}] must be array";
        }
        
        foreach([
            $this->out_dir_key => 1, // key required
            $this->out_sections_key => 1,// key required
            $this->do_auto_push_last_key => 0, // not required
            $this->replaces_key => 0, // not required
        ] as $key => $is_required) {
            if (array_key_exists($key, $sectman_arr)) {
                switch ($key) {
                    case $this->out_dir_key:
                        $out_dir = $sectman_arr[$key];
                        if (substr($out_dir, 1) === '.') {
                            $out_dir = $this->base_dir . '/' . $out_dir;
                        }
                        $this->outdir_path = realpath($out_dir);
                        if (!$this->outdir_path) {
                            return "[$key] path not exist: $out_dir";
                        }
                        if (!is_dir($this->outdir_path)) {
                            return "[$key] path must be folder: " . $sectman_arr[$key];
                        }
                        break;
                    case $this->out_sections_key:
                        $this->out_sections_arr = $sectman_arr[$key];
                        if (!is_array($this->out_sections_arr)) {
                            return "[$key] must be array";
                        }
                        break;
                    case $this->do_auto_push_last_key:
                        $this->do_auto_push_last_buff = $sectman_arr[$this->do_auto_push_last_key];
                        break;
                    case $this->replaces_key:
                        $this->replaces_arr = $sectman_arr[$key];
                        if (!is_array($this->replaces_arr)) {
                            return "[$key] must be array";
                        }
                        break;
                    default:
                        throw new \Exception("Code not complete");
                }
            } elseif ($is_required) {
                return "[{$$this->sectman_header_key}] must have key: $key";
            }
        }
        
        // Search ^ search for mentioned sections
        $all_ment_arr = ['*' => []];
        foreach($header_arr as $key => $value) {
            if (substr($key, 0, 1) !== '^') continue;
            foreach($this->extractSectArr(substr($key, 1)) as $sect_key) {
                $fc = $sect_key[0];
                if ($fc === '-') continue;
                if ($fc === '+' || $fc === '=') $sect_key = substr($sect_key, 1);
                if (!strlen($sect_key)) continue;
                if (!array_key_exists($sect_key, $all_ment_arr)) {
                    $all_ment_arr[$sect_key] = [];
                }
            }
            unset($header_arr[$key]);
        }
        
        // scan all mentoined sections in $this->sections_arr and in $sect_lines_arr
        foreach($all_ment_arr as $sect_key => $value) {
            if (!array_key_exists($sect_key, $this->out_sections_arr)) {
                return "Section undefined in header: $sect_key";
            }
            $this->sect_results_arr[$sect_key] = [];
        }
        
        $base_sect_arr = ['*' => 1];
        // push line numbers to section arrays
        foreach($sect_lines_arr as $line_num => $key) {
            if (substr($key, 0, 1) !== '^') continue;
            $set_mode = false;
            $add_arr = [];
            $sub_arr = [];
            foreach($this->extractSectArr(substr($key, 1)) as $sect_key) {
                $fc = $sect_key[0];
                if (strpos(' -+=', $fc)) $sect_key = substr($sect_key, 1);
                if ($fc === '=') $set_mode = true;
                if (!strlen($sect_key)) continue;                
                if ($fc === '-') {
                    $sub_arr[] = $sect_key;
                } else {
                    $add_arr[$sect_key] = 1;
                }
            }
            
            // make $sect_arr from $base_sect_arr, $add_arr, $sub_arr
            if ($set_mode) {
                $base_sect_arr = $add_arr;
                $sect_arr = $base_sect_arr;
            } else {
                $sect_arr = array_merge($base_sect_arr, $add_arr);
            }
            foreach($sub_arr as $sect_key) {
                if (array_key_exists($sect_key, $sect_arr)) {
                    unset($sect_arr[$sect_key]);
                }
            }
            
            // write $line_num to all $sect_arr keys
            foreach($sect_arr as $sect_key => $value) {           
                $all_ment_arr[$sect_key][$line_num] = $key;
            }
        }

        // add auto-push last buffer
        if ($this->do_auto_push_last_buff) {
            foreach($base_sect_arr as $sect_key => $value) {
                $all_ment_arr[$sect_key][fileHELMLsect::$last_line_num] = "# last_line autocomplete";
            }
        }

        $this->sect_keys_arr = array_keys($all_ment_arr);
        $this->sect_lines_arr = $all_ment_arr;
        $header_arr["sections"] = $all_ment_arr;
        
        $this->replacesPrepare();
        
        return $header_arr;
    }
    
    public function replacesPrepare() {
        if (!$this->replaces_arr) return;
        $replaces_arr = [];
        foreach($this->out_sections_arr as $sect_key => $value) {
            $replaces_arr[$sect_key] = [];
        }
        foreach($this->replaces_arr as $key => $repr_or_arr) {
            if (is_array($repr_or_arr)) {
                foreach($repr_or_arr as $sect_key => $rept) {
                    if (!array_key_exists($sect_key, $replaces_arr)) {
                        return "No output section for this replacement key [$key]";
                    }
                    $replaces_arr[$sect_key][$this->replaceKeyMake($key)] = $rept;
                }
            } else {
                foreach($this->out_sections_arr as $sect_key => $value) {
                    $replaces_arr[$sect_key][$this->replaceKeyMake($key)] = $repr_or_arr;
                }
            }
        }
        $this->replaces_arr = $replaces_arr;
    }
    public function replaceKeyMake($key) {
        return '{{' . $key . '}}';
    }

    public function extractSectArr($key) {
        if (substr($key, 0, 1) === '^') {
            $key = substr($key, 1);
        }
        $i = strpos($key, '#');
        if (false !== $i) {
            // remove comments after #
            $key = substr($key, 0, $i);
        }
        
        return preg_split('/\s+/', $key, -1, PREG_SPLIT_NO_EMPTY);
    }
    
    public function checkPrepareOutFiles() {
        foreach($this->out_sections_arr as $sect_key => $short_file_name) {
            if (!$short_file_name) continue;
            $out_full_name = $this->outdir_path . '/' . $short_file_name;
            if (!file_exists($out_full_name) && !touch($out_full_name)) {
                return "Can't touch this file: $out_full_name";
            }
            $out_full_name = realpath($out_full_name);
            if (!$out_full_name) {
                return "Can't access to file: $out_full_name";
            }
            if (!is_file($out_full_name)) {
                return "Not a file: $out_full_name";
            }
            $this->out_sections_arr[$sect_key] = $out_full_name;
        }

        return null;
    }
    
    public function divideToFiles() {
        // check-prepare out_files
        if ($err = $this->checkPrepareOutFiles()) {
            return $err;
        }

        // open all out-files for write
        foreach($this->out_sections_arr as $sect_key => $out_full_name) {
            if (!$out_full_name) continue;
            $this->sect_results_arr[$sect_key] = fopen($out_full_name, 'wb');
        }
        // walk source file and write sections to out-files
        $this->walkDivide(function($sect_key, $buff_arr) {
            if ($this->sect_results_arr[$sect_key]) {
                fwrite($this->sect_results_arr[$sect_key], implode('', $buff_arr));
            }
        });
        
        // close all opened out-files
        foreach($this->sect_results_arr as $sect_key => $fp) {
            if (!$fp) continue;
            fclose($fp);
        }
    }
    
    public function sectWriteToArr($sect_key, $buff_arr) {
        $this->sect_results_arr[$sect_key] = array_merge($this->sect_results_arr[$sect_key], $buff_arr);
    }
    
    public function walkDivide(?callable $sect_write_fn = null) {
        
        if (!$this->sect_lines_arr) {
            throw new \Exception("Empty sect_lines_arr, probably missed ->load(), nothing to do");
        }
        
        if (is_null($sect_write_fn)) {
            $sect_write_fn = \Closure::fromCallable([$this, 'sectWriteToArr']);
        }
        
        $prefix_str = $this->sectman_prefix_str;
        $prefix_len = strlen($prefix_str);
        
        // Open source file
        $f = fopen($this->ssrc_file, 'rb');
        if (!$f) {
            // Can't read file
            return "Can't read source file: " . $this->ssrc_file;
        }
        
        $buff_arr = [];
        
        $str_num = -1;
        while (!feof($f)) {
            $st_src = fgets($f);
            if (false === $st_src) break; // break on err
            
            $str_num++;

            $st = trim($st_src);

            if (substr($st, 0, $prefix_len) !== $prefix_str) {
                $buff_arr[] = $st_src;
                continue;
            }
            
            // control string
            $st = trim(substr($st, $prefix_len));
            
            // directly add string to buff_arr
            if (substr($st, 0, 1) === '!') {
                $buff_arr[] = substr($st_src, 1+strpos($st_src, '!'));
                continue;
            }
            
            if (substr($st, 0, 1) != '^') continue; // skip all control lines except sect-dividers
            
            // we have sect-divider

            //$sect_arr = $this->extractSectArr(substr($st, 1));
            
            foreach($this->sect_keys_arr as $sect_key) {
                if (array_key_exists($str_num, $this->sect_lines_arr[$sect_key])) {
                    // perform replaces if need
                    if ($this->replaces_arr[$sect_key]) {
                        $sect_buff_arr = [];
                        $from_arr = array_keys($this->replaces_arr[$sect_key]);
                        $to_arr = array_values($this->replaces_arr[$sect_key]);
                        $sect_buff_arr = array_map(function ($st) use ($from_arr, $to_arr) {
                            return str_replace($from_arr, $to_arr, $st);
                        }, $buff_arr);
                    } else {
                        $sect_buff_arr = $buff_arr;
                    }
                    
                    try {
                        call_user_func($sect_write_fn, $sect_key, $sect_buff_arr);
                    } catch (Exception $ex) {
                        // error break
                        break;
                    }
                }
            }
            $buff_arr = [];
        }
        fclose($f);
    }
}
