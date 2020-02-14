<?php
/**
 * php nginx parser mysql
 * @author Kevin MULLER https://github.com/kiki67100/php-mysql-nginx-access-log
 * @copyright Copyright (c) 2020, Kevin MULLER
 * @license GPL
 *
 */

if (!isset($argv)) {
    die('Cli ONLY');
}

if (count($argv) !== 3) {
    $base = basename($argv[0]);
    echo "Usage: " . $base . " domain nginxlogfile\nExample : $base test.com access_log\n\n";
}

var_dump($argv);
$domain = $argv[1];
$file = $argv[2];
$type = 'file';

if (isset($argv[2]) && $argv[2] == "-") {
    $type = "stdin";
}

if ($type == "file" && !file_exists($file)) die($file . ' not found...');
$total_line = 0;
if ($type == "file") {
    printf("Count line number ... ");
    $line = exec(sprintf("/usr/bin/wc -l %s", $file));
    $total_line = explode(' ', $line)[0];
    printf(": %s\n", $total_line);
}


$database = 'nginxlog';
$user = 'nginxlog';
$pass = 'nginxlog';
$table = 'nginxlog';

$pdo = new PDO('mysql:host=localhost;dbname=' . $database, $user, $pass);
if (!$pdo) die('error');

$check_table = $pdo->query('DESC ' . $table);
if ($check_table === false) {
    echo $table . "not exist create it ....\n";
    $pdo->query('CREATE TABLE `' . $table . '` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `domain` varchar(100) NOT NULL,
        `ip` varchar(100) NOT NULL,
        `remote` varchar(100) NOT NULL,
        `time` datetime NOT NULL,
        `request` varchar(2000) NOT NULL,
        `statut` varchar(10) NOT NULL,
        `byte` varchar(10) NOT NULL,
        `referrer` varchar(1000) NOT NULL,
        `useragent` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8');
}

$clean = false;

if ($type != 'file') {
    echo "stdin mode ...\n";
    $f = fopen('php://stdin', 'r');
} else {
    $f = fopen($file, 'r');
    echo "file mode...\n";
}
$i = 0;
$error = 0;
$bypass = 0;
if ($clean) {
    echo "Clean $table\n";
    $pdo->query('DELETE FROM ' . $table . ' where domain="' . $domain . '" ');
}

$sql = "INSERT INTO nginxlog (ip,domain,remote, time, request,statut,byte,referrer,useragent) VALUES (:ip,:domain,:remote, :time, :request,:statut,:byte,:referrer,:useragent)";
$stmt = $pdo->prepare($sql);
$avg = 0.00;
$modulo = 1000;
$transaction = false;
$bypass = 0;
while ($line = fgets($f, 4096)) {
    if (!$transaction) {
        $pdo->beginTransaction();
        $transaction = true;
    }

    $start = microtime(true);
    $ret = preg_match('#^(?P<ip>.[^ ]+) - (?P<remote>.[^ ]*) \[(?P<time>.[^\]]+)\] "(?P<request>.[^\"]+)" (?P<statut>.[^ ]+) (?P<byte>\d+) "(?P<referrer>.[^ "]*)" "(?P<useragent>.[^"]*)"#', $line, $matches);
    $i++;
    if ($ret == 1) {
        $ret2 = preg_match('#(?P<verb>.[^ ]*) (?<path>.[^ ]*) (?P<version>.*)#', $matches['request'], $matches2);
        if ($ret2 == 1) {
            $url = parse_url($matches2['path']);
            if (isset($url['path'])) {
                $extension = substr($url['path'], -3);
                if (in_array($extension, ['png', '.js', 'css', 'ico', 'jpg', 'svg', 'gif']) || strpos("/xmlrpc.php", $matches2['path']) === FALSE) {
                    $bypass++;
                    if($bypass%1000){
//                        echo "bypass ".$url['path']."\n";
                    }
                    continue;
                } else {
#				echo "pass $extension \n";
                }
            }
        }

        $time = strtotime($matches['time']);
        $time = date('Y-m-d H:m:s', $time);
        $ret = $stmt->execute([
            'ip' => $matches['ip'],
            'remote' => $matches['remote'],
            'time' => $time,
            'request' => $matches2['path'],
            'statut' => $matches['statut'],
            'byte' => $matches['byte'],
            'referrer' => $matches['referrer'],
            'useragent' => $matches['useragent'],
            'domain' => $domain
        ]);
        if (!$ret) {
            echo "ERROR INSERT\n";
            $error++;
        }
        if ($total_line != 0) {
            $pourcentage = round(($i / $total_line) * 100, 2);
        } else {
            $pourcentage = "n/a";
        }

        if ($i % $modulo == 0) {
            $total_avg = round(($avg / $modulo) * 1000, 4);
            $avg = 0.00;
            echo "AVG $total_avg #################\n$pourcentage% ( $i / $total_line) error = $error bypass=$bypass\n###################\n\n";
            if ($transaction) {
                echo "COMIT\n";
                $pdo->commit();
                $transaction = false;
            }
        }
    } else {
        $error++;
    }
    $end = microtime(true);
    $time = round($end - $start, 2);
    $avg += $time;
    #echo "Duree $time\n";
}
$pdo->commit();
fclose($f);
