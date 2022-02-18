<?php

namespace App\Command\Stats;

use Minicli\App;
use Minicli\Command\CommandController;
use Minicli\Output\Filter\ColorOutputFilter;
use Minicli\Output\Helper\TableHelper;

class DefaultController extends CommandController
{
    protected $base_path;

    public function __construct()
    {
        $this->base_path = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
    }

    public function handle()
    {
        date_default_timezone_set('Asia/Tehran');

        $files = array_filter(glob("{$this->base_path}*.log", GLOB_BRACE), 'is_file');
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $table = new TableHelper();
        $table->addHeader([
            'Index',
            'File Name',
            'Video ID',
            'Title',
            'Bandwidth',
            'Resolution',
            'Current File Size',
            'Last Speed',
            'Progress',
            'last Modified Date',
        ]);

        foreach ($files as $key => $val) {

            $dirname = pathinfo($val, PATHINFO_DIRNAME);
            $filename = pathinfo($val, PATHINFO_FILENAME);

            $filename_log = $dirname . DIRECTORY_SEPARATOR . $filename . '.log';
            $filename_video = $dirname . DIRECTORY_SEPARATOR . $filename . '.mp4';
            $filename_info = $dirname . DIRECTORY_SEPARATOR . $filename . '.info';

            $filesize = is_file($filename_video) ? $this->filesizeFormatted($filename_video) : 0;

            $modified_date = date('Y-m-d H:i:s', filemtime($filename_log));

            $content = @file_get_contents($filename_info);
            $info = json_decode($content);

            $content = @file_get_contents($filename_log);

            preg_match("/Duration: (.*?), start:/", $content, $matches);

            $rawDuration = $matches[1];

            $ar = array_reverse(explode(":", $rawDuration));
            $duration = floatval($ar[0]);
            if (!empty($ar[1])) $duration += intval($ar[1]) * 60;
            if (!empty($ar[2])) $duration += intval($ar[2]) * 60 * 60;

            preg_match_all("/time=(.*?) bitrate/", $content, $matches);

            $rawTime = array_pop($matches);

            if (is_array($rawTime)) {
                $rawTime = array_pop($rawTime);
            }

            $ar = array_reverse(explode(":", $rawTime));
            $time = floatval($ar[0]);
            if (!empty($ar[1])) $time += intval($ar[1]) * 60;
            if (!empty($ar[2])) $time += intval($ar[2]) * 60 * 60;

            //calculate the progress
            $progress = round(($time / $duration) * 100, 2);

            preg_match_all("/ speed=(.*?)x/", $content, $matches);
            $last_speed = array_pop($matches);
            $last_speed = array_pop($last_speed);


            $table->addRow([
                ++$key,
                "{$filename}.mp4",
                $info->video_id,
                $info->title,
                $info->bandwidth,
                $info->resolution,
                $filesize,
                $last_speed,
                $progress,
                $modified_date
            ]);
        }

        $this->getPrinter()->newline();
        $this->getPrinter()->rawOutput($table->getFormattedTable(new ColorOutputFilter()));
        $this->getPrinter()->newline();
    }

    public function teardown()
    {
        $this->getPrinter()->newline();
        $this->getPrinter()->newline();
    }

    protected function filesizeFormatted($path): string
    {
        $size = filesize($path);
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
        $power = $size > 0 ? floor(log($size, 1024)) : 0;
        return number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
    }
}