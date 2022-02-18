<?php

namespace App\Command\Download;

use Minicli\App;
use Minicli\Command\CommandController;
use Minicli\Output\Filter\ColorOutputFilter;
use Minicli\Output\Helper\TableHelper;

class DefaultController extends CommandController
{
    protected $qualities = [];

    protected $base_url;

    protected $base_path;

    public function __construct()
    {
        $this->base_path = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
    }

    public function handle()
    {
        $config_file = $this->hasParam('config') ? $this->getParam('config') : null;
        if ($config_file === null) {
            $this->getPrinter()->error("ERROR: Please enter a config address.");
            exit;
        }

        $domain = $this->getDomain($config_file);
        if ($domain !== 'arvanvod.com') {
            $this->getPrinter()->error("ERROR: The config address entered is invalid.");
            exit;
        }

        $extension = pathinfo($config_file, PATHINFO_EXTENSION);
        if ($extension !== 'json') {
            $this->getPrinter()->error("ERROR: The config file extension is invalid.");
            exit;
        }

        $config_content = $this->getContents($config_file);
        $config_content = json_decode($config_content, true);

        $file_url = $this->getM3U8Source($config_content['source']);
        $this->base_url = $this->getBaseUrlQuality($file_url);

        $file_content = $this->getContents($file_url);
        $this->extractQualities($file_content);

        $this->getPrinter()->display("Input Option Number: ");
        $file_selected = trim(fgets(STDIN));

        if (!is_numeric($file_selected)) {
            $this->getPrinter()->error('ERROR: The selected option is invalid, please enter an integer.');
            exit;
        }

        if (!array_key_exists($file_selected, $this->qualities)) {
            $this->getPrinter()->error('ERROR: The selected option is invalid.');
            exit;
        }

        @mkdir($this->base_path);

        $file_name = str_replace(' ', '-', $config_content['title']) . '_' . $this->qualities[$file_selected]['bandwidth'];
        $file_path = $this->base_path . $file_name . '.mp4';

        $cmd = 'ffmpeg -i "' . $this->qualities[$file_selected]['url'] . '" -c copy -y "' . $file_path . '"';
        $log_file = $this->base_path . $file_name . '.log';
        $info_file = $this->base_path . $file_name . '.info';

        $info = [
            'video_id' => $config_content['mediaid'],
            'title' => $config_content['title'],
            'bandwidth' => $this->qualities[$file_selected]['bandwidth'],
            'resolution' => $this->qualities[$file_selected]['resolution'],
        ];
        $info = json_encode($info);
        file_put_contents($info_file, $info);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B ' . $cmd . '<nul >nul 2>"' . $log_file . '"', 'r'));
        } else {
            shell_exec($cmd . '</dev/null >/dev/null 2>"' . $log_file . '" &');
        }

        $this->getPrinter()->info("Start downloading in the background...");
        $this->getPrinter()->info("You can see stats download with run \"./arvan stats\" command.");
        $this->getPrinter()->info("For stop process, kill it.");
    }

    public function teardown()
    {
        $this->getPrinter()->display('Bye!');
    }

    protected function getDomain(string $url): string
    {
        $info = parse_url($url);
        $host = $info['host'];
        $host_names = explode('.', $host);

        return $host_names[count($host_names) - 2] . "." . $host_names[count($host_names) - 1];
    }

    protected function getFullDomain(string $url)
    {
        $info = parse_url($url);

        return $info['host'];
    }

    protected function getM3U8Source(array $sources): string
    {
        $link = null;

        foreach ($sources as $source) {
            if ($source['type'] == 'application/x-mpegURL') {
                $link = $source['src'];
                break;
            }
        }

        return $link;
    }

    protected function getBaseUrlQuality(string $url): string
    {
        $url = explode('/', $url);
        array_pop($url);

        return implode('/', $url);
    }

    protected function extractQualities($content)
    {
        preg_match_all('/#.*BANDWIDTH=(.*?),RESOLUTION=(.*?),.*\n(.*?)\n/', $content, $matches);

        $qualities = [];
        foreach ($matches[1] as $key => $value) {
            $qualities[] = [
                'bandwidth' => $matches[1][$key],
                'resolution' => $matches[2][$key],
                'url' => $this->base_url . '/' . $matches[3][$key],
            ];
        }

        foreach ($qualities as $key => $row) {
            $dates[$key] = $row['bandwidth'];
        }

        array_multisort($dates, SORT_ASC, $qualities);
        $qualities = array_combine(range(1, count($qualities)), array_values($qualities));

        $this->qualities = $qualities;

        $this->getPrinter()->display('Select Quality:');

        $table = new TableHelper();
        $table->addHeader(['Option', 'Bandwidth', 'Resolution']);

        foreach ($qualities as $key => $val) {
            $table->addRow([
                $key,
                $val['bandwidth'],
                $val['resolution']
            ]);
        }

        $this->getPrinter()->newline();
        $this->getPrinter()->rawOutput($table->getFormattedTable(new ColorOutputFilter()));
        $this->getPrinter()->newline();
    }

    protected function getContents(string $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authority: {$this->getFullDomain($url)}",
            'Accept: application/json, text/plain, */*',
            'Accept-Encoding: gzip, deflate, br',
            'Accept-Language: en-US,en;q=0.9,fa;q=0.8',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.88 Safari/537.36',
            'Content-Type: application/json;charset=UTF-8',
            'Origin: https://player.arvancloud.com',
            'Referer: https://player.arvancloud.com/',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-Mode: cors',
        ]);

        return curl_exec($ch);
    }
}