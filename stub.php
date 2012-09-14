<?php
/*
    This file is part of Erebot.

    Erebot is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Erebot is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Erebot.  If not, see <http://www.gnu.org/licenses/>.
*/

if (version_compare(phpversion(), '5.3.1', '<')) {
    if (substr(phpversion(), 0, 5) != '5.3.1') {
        // this small hack is because of running RCs of 5.3.1
        echo "@PACKAGE_NAME@ requires PHP 5.3.1 or newer." . PHP_EOL;
        exit(1);
    }
}
foreach (array('phar', 'spl', 'pcre', 'simplexml') as $ext) {
    if (!extension_loaded($ext)) {
        echo "Extension $ext is required." . PHP_EOL;
        exit(1);
    }
}
try {
    Phar::mapPhar();
} catch (Exception $e) {
    echo "Cannot process @PACKAGE_NAME@ phar:" . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;
    exit(1);
}

/* HTTP_Request2's CookieJar emits warnings because it cannot include
   public-suffix-list.php (it tries to load it using PEAR's data_dir
   from the machine where the phar was built). We handle that manually. */
require_once(
    "phar://" . __FILE__ .
    DIRECTORY_SEPARATOR . "@PACKAGE_NAME@-@PACKAGE_VERSION@" .
    DIRECTORY_SEPARATOR . "php" .
    DIRECTORY_SEPARATOR . "HTTP" .
    DIRECTORY_SEPARATOR . "Request2" .
    DIRECTORY_SEPARATOR . "CookieJar.php"
);
if (!class_exists('Erebot_Stub_CookieJar')) {
    class   Erebot_Stub_CookieJar
    extends HTTP_Request2_CookieJar
    {
        static public function setPSL($psl)
        {
            parent::$psl = $psl;
        }
    }
}
Erebot_Stub_CookieJar::setPSL(
    require_once(
        "phar://" . __FILE__ .
        DIRECTORY_SEPARATOR . "@PACKAGE_NAME@-@PACKAGE_VERSION@" .
        DIRECTORY_SEPARATOR . "data" .
        DIRECTORY_SEPARATOR . "pear.php.net" .
        DIRECTORY_SEPARATOR . "HTTP_Request2" .
        DIRECTORY_SEPARATOR . "public-suffix-list.php"
    )
);


// Return metadata about this package.
$packageName = '@PACKAGE_NAME@';
$packageVersion = '@PACKAGE_VERSION@';
$metadata = array(
    'pear.erebot.net/@PACKAGE_NAME@' => array(
        'version' => '@PACKAGE_VERSION@',
        'path' =>
            "phar://" . __FILE__ .
            DIRECTORY_SEPARATOR . "@PACKAGE_NAME@-@PACKAGE_VERSION@" .
            DIRECTORY_SEPARATOR . "php",
    )
);

require(
    "phar://" . __FILE__ .
    DIRECTORY_SEPARATOR . "@PACKAGE_NAME@-@PACKAGE_VERSION@" .
    DIRECTORY_SEPARATOR . "data" .
    DIRECTORY_SEPARATOR . "pear.erebot.net" .
    DIRECTORY_SEPARATOR . "@PACKAGE_NAME@" .
    DIRECTORY_SEPARATOR . "package.php"
);
return $metadata;

__HALT_COMPILER();
