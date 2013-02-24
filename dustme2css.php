<?php
/* Copyright (c) 2013, Vo Xuan Tien
 * All rights reserved.
 *
 * This software may be used and distributed according to the terms of
 * the New BSD License, which is incorporated herein by reference.
 */

$arguments = getopt("i:o:h", array("input:", "output:", "help"));

$help = isset($arguments["h"]) || isset($arguments["help"]);
if ($help) {
    print_help();
    die;
}

$input_files = array();
if (isset($arguments["i"])) {
    $input_files = array_merge($input_files, explode(",", $arguments["i"]));
}
if (isset($arguments["input"])) {
    $input_files = array_merge($input_files, explode(",", $arguments["input"]));
}

if (!isset($arguments["i"]) && !isset($arguments["input"])) {
    print_help();
    die;
}

foreach ($input_files as $file) {
    if (!file_exists($file)) {
        echo "Error: Input file ($file) does not exist\n";
        die;
    }
}

if (isset($arguments["o"])) {
    $output_folder =  $arguments["o"];
} else if (isset($arguments["output"])) {
    $output_folder = $arguments["output"];
} else {
    $output_folder = ".";
}

if (!file_exists($output_folder)) {
    echo "Error: Output folder ($output_folder) does not exist\n";
    die;
}

function print_help() {
    echo " Usage: php dustme2css.php <option>

      --help, -h,              to get this help.
      --input, -i [file]       input file (can be multiple file, seprate by ',').
      --output, -o [folder]    output folder (option, current folder is used if no folder is specified).\n";
}

foreach ($input_files as $input_file) {
    echo "Processing $input_file ...\n";
    process_input($input_file, $output_folder);
    echo "Completed $input_file\n";
}

function process_input($input_file, $output_folder) {
    $handle = fopen($input_file, "r");
    $index = -3;
    $output = array();
    $filecount = 0;
    $mode = "unknown";
    $used_selectors = array();

    // loop each line of csv file
    while ($line = fgetcsv($handle)) {
        if ($index == -3) {
            // css files
            $files = $line;
            foreach ($files as $file) {
                $output[] = read_css_file($file);
            }
            $filecount = count($output);
        }
        if ($index == -2) {
            // get mode: used or unused
            $metadata = $line;
            foreach ($metadata as $data) {
                if(preg_match("/\sunused selectors/", $data)) {
                    $mode = "unused";
                    break;
                } elseif (preg_match("/\sused selectors/", $data)) {
                    $mode = "used";
                    break;
                }
            }
            if ($mode == "unknown") {
                echo "This input file ($input_file) is not support\n";
                return;
            }
        }
        if ($index == -1) {
            // empty line
            // skip
        }
        if ($index >= 0) {
            // css used or unused selectors;
            $selectors = $line;
            // Begin clean css for mode unused
            if ($mode == "unused") {
                for ($i = 0; $i < $filecount; $i++) {
                    if ($output[$i]["data"] && $selectors[$i]) {
                        $output[$i]["data"] = clean_unused_css($selectors[$i], $output[$i]["data"]);
                    }
                }
            } elseif ($mode == "used") {
                for ($i = 0; $i < $filecount; $i++) {
                    if (!isset($used_selectors[$i])) {
                        $used_selectors[$i] = array();
                    }
                    if ($selectors[$i]) {
                        $used_selectors[$i][] = $selectors[$i];
                    }
                }
            }
        }
        $index++;
    }
    
    if ($mode == "used") {
        $all_selectors = array();
        // Begin clean css for mode used
        for ($i = 0; $i < $filecount; $i++) {
            if (!isset($all_selectors[$i])) {
                $all_selectors[$i] = array();
            }
            if ($output[$i]["data"]) {
                $all_selectors[$i] = get_all_selectors($output[$i]["data"]);
            }
            foreach ($all_selectors[$i] as $selector) {
                if (!in_array($selector, $used_selectors[$i])) {
                    // Unused selector, remove it
                    $output[$i]["data"] = clean_unused_css($selector, $output[$i]["data"]);
                }
            }
        }
    }

    export_output($output, $output_folder);
}

function url_exists($url) {
    $hdrs = @get_headers($url);
    return is_array($hdrs) ? preg_match('/^HTTP\\/\\d+\\.\\d+\\s+2\\d\\d\\s+.*$/', $hdrs[0]) : false;
}

function read_css_file($file) {
    $info = pathinfo($file);
    $filename = rawurlencode($info["basename"]);
    if (url_exists($file)) {
        $data = file_get_contents($file);
        return array("filename" => $filename, "data" => $data);
    } else {
        return array("filename" => $filename, "data" => "");
    }
}

function clean_unused_css($selector, $text) {
    $sameMeaningSelectors = array();
    $sameMeaningSelectors[] = $selector;
    // odd
    if (preg_match("/nth-child\(2n\+1\)/", $selector)) {
        $sameMeaningSelectors[] = preg_replace("/nth-child\(2n\+1\)/", "nth-child(odd)", $selector);
    } elseif (preg_match("/nth-child\(odd\)/", $selector)) {
        $sameMeaningSelectors[] = preg_replace("/nth-child\(odd\)/", "nth-child(2n+1)", $selector);
    }
    // even
    if (preg_match("/nth-child\(2n\)/", $selector)) {
        $sameMeaningSelectors[] = preg_replace("/nth-child\(2n\)/", "nth-child(even)", $selector);
    } elseif (preg_match("/nth-child\(even\)/", $selector)) {
        $sameMeaningSelectors[] = preg_replace("/nth-child\(even\)/", "nth-child(2n)", $selector);
    }
    // input type
    if (preg_match("/(input)([^[]*)(\[type=\"\w*\"\])/", $selector)) {
        $sameMeaningSelectors[] = preg_replace("/(input)([^[]*)(\[type=\"\w*\"\])/", "$1$3$2", $selector);
    } elseif (preg_match("/(input)(\[type=\"\w*\"\])([^\s|:]*)/", $selector)) {
        $sameMeaningSelectors[] = preg_replace("/(input)(\[type=\"\w*\"\])([^\s|:]*)/", "$1$3$2", $selector);
    }
    // class star
    if (preg_match("/(\.[^\s|\[]*)(\[class\*=\"\w*\"\])/", $selector)) {
        $sameMeaningSelectors[] = preg_replace("/(\.[^\s|\[]*)(\[class\*=\"\w*\"\])/", "$2$1", $selector);
    } elseif (preg_match("/(\[class\*=\"\w*\"\])(\.[^\s|:]*)/", $selector)) {
        $sameMeaningSelectors[] = preg_replace("/(\[class\*=\"\w*\"\])(\.[^\s|:]*)/", "$2$1", $selector);
    }
    
    //$selector = preg_replace("/nth-child\(2n\+1\)/", "nth-child(odd)", $selector);
    //$selector = preg_replace("/nth-child(2n)/", "nth-child(even)", $selector);
    //$selector = preg_replace("/(input)([^[]*)(\[type=\"\w*\"\])/", "$1$3$2", $selector);
    //$selector = preg_replace("/(\.[^\s|\[]*)(\[class\*=\"\w*\"\])/", "$2$1", $selector);

    foreach ($sameMeaningSelectors as $selector) {
        $text = remove_selector($selector, $text);
    }

    return $text;
}

function get_all_selectors($text) {
    $selectors = array();

    preg_match_all("/([,{}]\\s*)([^,{};'@]*?)(\\s*[{])/", $text, $selectors1);
    $selectors = array_merge($selectors, $selectors1[2]);//Get second part
    
    preg_match_all("/([}]\\s*)([^,{};'@]*?)(\\s*[,])/", $text, $selectors2);
    $selectors = array_merge($selectors, $selectors2[2]);//Get second part
    
    preg_match_all("/([{]\\s*)([^,{};'@]*?)(\\s*[,])([^,{};'@]*?)([{])/", $text, $selectors3);
    $selectors = array_merge($selectors, $selectors3[2]);//Get second part
    
    preg_match_all("/([,]\\s*)([^,{};'@]*?)(\\s*[,])([^,{};'@]*?)([{])/", $text, $selectors4);
    $selectors = array_merge($selectors, $selectors4[2]);//Get second part

    preg_match_all("/(^\\s*)([^,{};'@]*?)(\\s*[,{])/", $text, $selectors5);
    $selectors = array_merge($selectors, $selectors5[2]);//Get second part
    
    return $selectors;
}

function remove_selector($selector, $text) {
    // escape special characters
    $selector = preg_replace("/[][{}()*?\\^$|=#]/", "\\\\$0", $selector);
    $selector = preg_replace("/[.:+~>]/", "\\s*\\\\$0\\s*", $selector);

    //$selector = preg_replace("/([\*\-\[\]\"\(\)\=])/", "\\\\$1",$selector);
    //$selector = preg_replace("/([\.\:\+\~\>])/", "\\s*\\\\$1\\s*", $selector);
    //$selector = preg_replace("/[][{}()*+?.\\^$|]/", "\\\\$0", $selector);

    /* $test_string = "   abc , abc, abc {abc=abc;}

      abc{abc=abc;}def, abc, def, def,def{}abc, def{} def, abc{}abc{}def{}"; */

    $text = preg_replace("/(?<=[,}{])\\s*(" . $selector . ")\\s*,/", "", $text);
    $text = preg_replace("/([,])\\s*(" . $selector . ")\\s*(?=[{])/", "", $text);
    $text = preg_replace("/}\\s*(" . $selector . ")\\s*{([^}]*)/", "", $text);
    $text = preg_replace("/(?<=[{])\\s*(" . $selector . ")\\s*{([^}]*)}/", "", $text);
    $text = preg_replace("/^\\s*" . $selector . "\\s*,/", "", $text);
    $text = preg_replace("/(^\\s*" . $selector . "\\s*{([^}]*))([}])/", "", $text);

    return $text;
}

function export_output($output, $output_folder) {
    foreach ($output as $file) {
        file_put_contents($output_folder . "/" . $file["filename"], $file["data"]);
    }
}

?>
