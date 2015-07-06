<?php

class arrange_p2p {

    public $config;
    public $filters;
    public $file_type_text;
    public $content_text;
    public $op_choices;
    public $dry_run;

    function __construct() {

        $this->config = array(
            'db' => 'p2p.sqlite',
            'source_dir' => '/media/usb0/p2p/bt/downloads/',
            'target' => array(
                '1' => '/home/usagi/mnt/卡通/',
                '2' => '/home/usagi/mnt/音樂/',
                '3' => '/home/usagi/mnt/圖片/',
            ),
//            'target' => array(
//                '1' => '/media/usb0/tmp/deleteme/卡通/',
//                '2' => '/media/usb0/tmp/deleteme/音樂/',
//                '3' => '/media/usb0/tmp/deleteme/圖片/',
//            ),
        );

        $this->file_type_text = array(
            '1' => '資料夾',
            '2' => '檔案',
        );

        $this->content_text = array(
            '1' => '動畫',
            '2' => '音樂',
            '3' => '圖片',
            '0' => '其他',
        );
        $this->op_choices = array(
            '1' => '直接copy檔案或目錄',
            '2' => 'copy目錄下所有檔案',
            '3' => '分別處理目錄下所有檔案',
        );
    }

    public function ansi_color($msg, $hl = '0', $fg = '7', $bg = '0') {
        $ret = chr(27) . sprintf('[%d;3%d;4%dm', $hl, $fg, $bg) . $msg . chr(27). '[0m';
        return $ret;
    }

    public function out($msg) {
        echo($msg . PHP_EOL);
    }

    public function ask_a_string($prompt) {
        echo($prompt);
        $stdin = fopen('php://stdin', 'r');
        $response = fgets($stdin);
        return trim($response);
    }

    public function run_external_cmd($cmd) {
        system($cmd);
    }

    public function split_string($string) {
        $delimiter = ' []()＆（）「！」／【】-『』+._&!∕';
        $tmp = '';
        $result = array();
        for ($i = 0; $i <= mb_strlen($string, 'UTF-8'); $i++) {
            $part = mb_substr($string, $i, 1, 'UTF-8');
            if (strlen($part) > 0) {
                if (mb_strpos($delimiter, $part, 0, 'UTF-8') !== FALSE) {
                    if ($tmp <> '') {
                        $result[] = $tmp;
                        $tmp = '';
                    }
                } else {
                    $tmp .= $part;
                }
            }
        }
        if ($tmp <> '') {
            $result[] = $tmp;
        }
        return $result;
    }

    public function get_all_filters() {
        $result = array();

        $h = new SQLite3($this->config['db']);

        //大項分類部份(動畫或音樂)
        $query = $h->query("select * from content_filter order by length(keyword) desc, seq desc");
        $tmp = array();
        while($ret = $query->fetchArray(SQLITE3_ASSOC)) {
            $tmp[] = $ret;
        }
        $result['content'] = $tmp;

        //細項分類部份
        $query = $h->query("select * from filters order by length(keywords) desc, seq desc");
        $tmp = array();
        while($ret = $query->fetchArray(SQLITE3_ASSOC)) {
            $tmp[] = $ret;
        }
        $result['filter'] = $tmp;

        return $result;

    }

    public function get_all_files($dir) {

        $files = scandir($dir);

        $return = array();
        foreach($files as $v) {
            if (($v <> '.') && ($v <> '..')) {
                $return[] = $v;
            }
        }
        return $return;
    }

    public function is_filter_match($filename, $filter) {
        //拆解檔名
        $f = $this->split_string($filename);
        //拆解過濾字串
        $kw = explode(' ', $filter);
        //逐一比對檔名裡有沒有過濾字串
        $result = TRUE;
        foreach($kw as $k) {
            if (array_search($k, $f) === FALSE) {
                $result = FALSE;
                break;
            }
        }
        return $result;
    }

    public function choice_string($choices) {
        $ret = '';
        foreach($choices as $k => $v) {
            $ret .= $this->ansi_color($k, '1', '3', '4') . $v . ' ';
        }
        return $ret;
    }

    public function compact_array($array) {
        $ret = '';
        foreach($array as $v) {
            if ($ret <> '') {
                $ret .= ' ';
            }
            $ret .= $v;
        }
        if ($ret == '') {
            $ret = '無';
        }
        return $ret;
    }

    public function ask_for_keyword($filename) {
        //準備選項
        $choices = $this->split_string($filename);
        array_unshift($choices, '[結束選擇]');

        //處理關鍵字
        $keyword = array();
        while(count($keyword) <= 0) {
            $ret = 'orz';
            while ($ret <> '0') {
                $this->out(sprintf('目前已選的關鍵字: ' . $this->compact_array($keyword)));
                $ret = $this->ask_a_string('請選擇要加入或移除的關鍵字: ' . $this->choice_string($choices));
                if ($ret <> '0') {
                    $key = array_search($choices[intval($ret)], $keyword);
                    if ($key !== FALSE) {
                        unset($keyword[$key]);
                    } else {
                        $keyword[] = $choices[intval($ret)];
                    }
                }
                if (sizeof($keyword) <= 0) {
                    $this->out('尚未選擇任何關鍵字, 請再選擇.');
                }
            }
        }

        return $this->compact_array($keyword);

    }

    public function get_max_seq($table) {

        $h = new SQLite3($this->config['db']);

        //大項分類部份(動畫或音樂)
        $query = $h->query("select max(seq) seq from " . $table);
        $tmp = array();
        $ret = $query->fetchArray(SQLITE3_ASSOC);

        return $ret['seq'];

    }

    public function quote_field($f) {
        return "'" . $f . "'";
    }

    public function quote_value($v) {
        if (is_int($v) || is_float($v)) {
            return $v;
        } else {
            return "'" . $v . "'";
        }
    }

    public function add_new_filter_to_db($table, $data) {
        $maxseq = $this->get_max_seq($table);

        $data['seq'] = $maxseq + 1;

        $fields = '';
        $values = '';
        foreach($data as $k => $v) {
            if ($fields <> '') {
                $fields .= ',';
            }
            $fields .= $this->quote_field($k);

            if ($values <> '') {
                $values .= ',';
            }
            $values .= $this->quote_value($v);
        }

        $sql = sprintf("INSERT INTO %s (%s) VALUES (%s)", $this->quote_field($table), $fields, $values);

        $h = new SQLite3($this->config['db']);
        $ret = $h->query($sql);

        return $ret;

    }

    public function ask_for_new_filter($table, $filename, $data) {
        //先問條件類型
        if ($table == 'content') {
            $content = '';
            $choices = array(
                '1' => '動畫',
                '2' => '音樂',
                '3' => '圖片',
                '0' => '略過不處理',
            );
            while (!in_array($content, array('0', '1', '2', '3'))) {
                $content = $this->ask_a_string('請問這是? ' . $this->choice_string($choices) . ' : ');
            }
        } else if ($table == 'filter') {
            $title = '';
            while ($title == '') {
                $title = $this->ask_a_string('請問此作品的中文標題? ');
            }
        } else {
            return FALSE;
        }

        //再問根據的關鍵字
        $keyword = $this->ask_for_keyword($filename);
        //問處理方式
        if ($table == 'filter') {
            $operation = '';
            while (!in_array($operation, array('3', '1', '2'))) {
                $operation = $this->ask_a_string('處理方式? ' . $this->choice_string($this->op_choices) . ':');
            }
        }
        //寫入db
        if ($table == 'content') {
            $data = array(
                'content_type' => $content,
                'keyword' => $keyword,
            );
            $ret = $this->add_new_filter_to_db('content_filter', $data);
        } else if ($table == 'filter') {
            $data['keywords'] = $keyword;
            $data['target'] = $title;
            $data['operation'] = $operation;
            $ret = $this->add_new_filter_to_db('filters', $data);
        }
        //寫入現有條件
        $this->filters = $this->get_all_filters();

        if ($table == 'content') {
            return $content;
        } else {
            return $data;
        }
    }

    public function check_content($filename) {
        //與現有的規則比對
        foreach($this->filters['content'] as $filter) {
            $content_type = $filter['content_type'];
            $result = $this->is_filter_match($filename, $filter['keyword']);
            if ($result === TRUE) {
                break;
            }
        }
        //如果比對沒有結果, 請使用者回答問題
        if ($result === FALSE) {
            $result = $this->ask_for_new_filter('content', $filename, array());
        } else {
            $result = $content_type;
        }

        return $result;

    }

    public function check_title($filename, $file_type, $content) {
        //與現有的規則比對
        $result = FALSE;
        foreach($this->filters['filter'] as $filter) {
            if (($filter['file_type'] == $file_type) && ($filter['content_type'] == $content)) {
                $result = $this->is_filter_match($filename, $filter['keywords']);
                $data = $filter;
                if ($result === TRUE) {
                    break;
                }
            }
        }
        //如果比對沒有結果, 請使用者回答問題
        if ($result === FALSE) {
            $data = array(
                'file_type' => $file_type,
                'content_type' => $content,
            );
            $result = $this->ask_for_new_filter('filter', $filename, $data);
        } else {
            $result = $data;
        }

        return $result;

    }

    public function copy_to_target($file, $operation) {
        $file_color = $this->ansi_color($file, '1', '7', '0');
        $this->out(sprintf('%s 的中文標題為 %s', $file_color, $this->ansi_color($operation['target'], '1', '2', '0')));
        $this->out(sprintf('%s 的處理方式為 %s', $file_color, $this->ansi_color($this->op_choices[$operation['operation']], '1', '1', '0')));

        //組出處理檔案的指令
        $cmd = array();
        $dst_fullpath = $this->config['target'][strval($operation['content_type'])] . $operation['target'];
        $cmd[] = sprintf('[ -d "%s" ] || mkdir "%s" ', $dst_fullpath, $dst_fullpath);

        $src_fullpath = $this->config['source_dir'] . $file;

        if ($operation['file_type'] == '1') {
            if ($operation['operation'] == '1') {
                //$cmd[] = sprintf('cp -rvu "%s" "%s" || read -p "請按Enter繼續...."', $src_fullpath, $dst_fullpath);
                $cmd[] = sprintf('rsync --size-only -v --progress "%s" "%s" || read -p "請按Enter繼續...."', $src_fullpath, $dst_fullpath);
            } else if ($operation['operation'] == '2') {
                //$cmd[] = sprintf('cp -rvu "%s"/* "%s" || read -p "請按Enter繼續...."', $src_fullpath, $dst_fullpath);
                $cmd[] = sprintf('rsync --size-only -v --progress "%s"/* "%s" || read -p "請按Enter繼續...."', $src_fullpath, $dst_fullpath);
            } else if ($operation['operation'] == '3') {
                //取得檔案清單
                $files = $this->get_all_files($src_fullpath);
                //逐一辨識檔案
                foreach($files as $f) {
                    $this->process_this($file . '/' . $f);
                }
            }
        } else if ($operation['file_type'] == '2') {
            if ($operation['operation'] == '1') {
                //$cmd[] = sprintf('cp -vu "%s" "%s" || read -p "請按Enter繼續...."', $src_fullpath, $dst_fullpath);
                $cmd[] = sprintf('rsync --size-only -v --progress "%s" "%s" || read -p "請按Enter繼續...."', $src_fullpath, $dst_fullpath);
            } else if ($operation['operation'] == '2') {
                $cmd[] = 'read -p "不可能這樣處理, 請按Enter繼續...."';
            } else if ($operation['operation'] == '3') {
                $cmd[] = 'read -p "不可能這樣處理, 請按Enter繼續...."';
            }
        }

        //執行指令
        foreach($cmd as $c) {
            if ($this->dry_run == FALSE) {
                system($c);
            } else {
            }
        }


    }

    public function is_dir_imcomplete($f) {
        $found = FALSE;

        $files = scandir($f);
        foreach($files as $v) {
            if (($v <> '.') && ($v <> '..')) {
                $fullpath = $f . $v;
//                $this->out($fullpath);
                if (is_dir($fullpath)) {
                    if ($this->is_dir_imcomplete($fullpath . '/')){
                        $found = TRUE;
                        break;
                    }
                } else if (is_file($fullpath)) {
                    if (preg_match('/\.part$/', $v) > 0) {
                        $found = TRUE;
                        break;
                    }
                }
            }
        }

        return $found;
    }

    public function process_this($f) {
        $f_c = $this->ansi_color($f, '1', '7', '0');
        //是檔案還是資料夾?
        $fullpath = $this->config['source_dir'] . $f;
        if (is_dir($fullpath)) {
            $file_type = '1';
            //全下載完了嗎?
            if ($this->is_dir_imcomplete($fullpath . '/')) {
                $this->out(sprintf('%s 還沒全下載完', $f_c));
                return FALSE;
            }
        } else if (is_file($fullpath)) {
            $file_type = '2';
            //全下載完了嗎?
            if (preg_match('/\.part$/', $f) > 0) {
                $this->out(sprintf('%s 還沒全下載完', $f_c));
                return FALSE;
            }
        } else {
            $file_type = '0';
            $this->out(sprintf('%s 無法辨識', $f_c));
            return FALSE;
        }
        $this->out(sprintf('%s 是%s', $f_c, $this->ansi_color($this->file_type_text[$file_type], '1', '5', '0')));
        //是動畫還是音樂?
        $content = $this->check_content($f);
        $this->out(sprintf('%s 是%s', $f_c, $this->ansi_color($this->content_text[$content], '1', '6', '0')));
        if (in_array($content, array('1', '2', '3'))) {
            //與所有的過濾條件比對
            $operation = $this->check_title($f, $file_type, $content);
            //進行處理
            $this->copy_to_target($f, $operation);
        }

    }

    public function process_p2p_files() {
        //取得目前所有的過濾條件
        $this->filters = $this->get_all_filters();
        //取得檔案清單
        $files = $this->get_all_files($this->config['source_dir']);

        //逐一辨識檔案
        foreach($files as $f) {
            $this->process_this($f);
        }
    }

    public function ask_for_dry_run() {
        $answer = '';
        $choices = array(
            '1' => '建立規則並複製檔案',
            '2' => '只建立規則',
        );
        while (!in_array($answer, array('1', '2'))) {
            $answer = $this->ask_a_string('請問這次要? ' . $this->choice_string($choices) . ' : ');
        }
        if ($answer == '1') {
            $this->dry_run = FALSE;
        } else if ($answer == '2') {
            $this->dry_run = TRUE;
        }
    }

    public function main() {
//        var_dump($this->is_dir_imcomplete('/media/usb0/p2p/bt/downloads/Kurosaki Maon - Toaru Majutsu no Index ED Single - Magic∞world/'));
        $this->ask_for_dry_run();
        $this->process_p2p_files();
    //    run_external_cmd('uptime');
    //    var_dump(ask_a_string('hello'));
        /*
        $h = fopen('/home/usagi/Dropbox/temp/p2pls.txt', 'r');
        if ($h) {
            while (($line = fgets($h)) !== FALSE) {
                print_r(split_string($line));
            }
            fclose($h);
        } else {

        }
         */
    }
}

$a = new arrange_p2p;
$a->main();

