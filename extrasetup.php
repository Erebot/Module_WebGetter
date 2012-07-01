<?php
/**
 * This file is used to provide extra files/packages outside package.xml
 * More information: http://pear.php.net/manual/en/pyrus.commands.package.php#pyrus.commands.package.extrasetup
 */

$targets    =   (int) $options['phar']  +
                (int) $options['tgz']   +
                (int) $options['tar']   +
                (int) $options['zip'];

if ($targets != 1) {
    echo    "Don't try to be smart about creating multiple " .
            "types of packages at once, I won't let you!" . PHP_EOL;
    exit(-1);
}

$extrafiles = array();
include(
    __DIR__ .
    DIRECTORY_SEPARATOR . 'buildenv' .
    DIRECTORY_SEPARATOR . 'extrafiles.php'
);

// Only for ".phar" packages (ignored for other types).
$pearDeps = array(
    'pear.php.net/HTTP_Request2',
    'pear.php.net/Net_URL2',
);

if (!$options['phar'])
    return;

// Add (data & php) files from installed PEAR packages.
/// @FIXME: what about @*_dir@ substitutions?...
if (count($pearDeps)) {
    $prefixes = array();
    $types = array();
    foreach (array("php", "data") as $type) {
        $paddedType = str_pad($type, 5, " ", STR_PAD_RIGHT);
        $prefixes[$type] = exec("pear config-get ${type}_dir", $output, $status);
        if ($status != 0) {
            echo "Could not determine path for type '$type'" . PHP_EOL;
            exit($status);
        }
        $types[$type] = $paddedType;
    }

    foreach ($pearDeps as $pearDep) {
        echo  PHP_EOL . "Adding files from $pearDep" . PHP_EOL;
        exec("pear list-files $pearDep", $output, $status);
        list($channel, $package) = explode('/', $pearDep, 2);
        if ($status != 0) {
            echo "Could not list files for '$pearDep'" . PHP_EOL;
            exit($status);
        }

        foreach ($output as $line) {
            $type = array_search(substr($line, 0, 5), $types);
            if ($type === FALSE)
                continue;

            $file = substr($line, 5);
            $targetFile =
                $type . '/' .
                ($type == 'data' ? $channel . '/' : '') .
                str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    ltrim(
                        substr($file, strlen($prefixes[$type])),
                        DIRECTORY_SEPARATOR
                    )
                );

            $padding = str_repeat(" ", strlen($file) - strlen($targetFile) - 2);
            echo "\t$file" . PHP_EOL . "\t=>$padding$targetFile" . PHP_EOL;
            $extrafiles[$targetFile] = $file;
        }
    }
}

// PEAR Exception...
echo PHP_EOL . "Adding parts of PEAR" . PHP_EOL;
$php_dir = exec("pear config-get php_dir", $output, $status);
if ($status != 0) {
    echo "Could not determine php_dir" . PHP_EOL;
    exit($status);
}
$targetFile = 'php/PEAR/Exception.php';
$sourceFile =
    $php_dir .
    DIRECTORY_SEPARATOR . "PEAR" .
    DIRECTORY_SEPARATOR . "Exception.php";
echo "\t$sourceFile => $targetFile" . PHP_EOL;
$extrafiles[$targetFile] = $sourceFile;

