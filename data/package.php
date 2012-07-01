<?php

/* Return metadata about what this package:
 * - "requires"
 * - "provides"
 * - "suggests"
 * - "conflicts" with
 * - "replaces"
 * You may optionally provide version constraints.
 * See http://getcomposer.org/doc/01-basic-usage.md#package-versions
 * for examples of valid version constraints.
 *
 * "php" refers to PHP itself while "pear2.php.net/pyrus"
 * refers to Pyrus.
 *
 * You may also pass a string with the name of the license
 * used by this module or an array with a single key/value,
 * the name of the license and an URI for that license.
 */
$metadata['pear.erebot.net/' . $packageName] += array(
    'requires' => array(
        'php' => '>= 5.2.0',
        'pear2.php.net/pyrus' => '> 2.0.0alpha3',
        'virt-Erebot_API' => '0.2.*',
        'pear.erebot.net/Erebot',
        'pear.php.net/HTTP_Request2' => '>= 2.1.1',
    ),
    'license' => array(
        'GPL' => 'http://www.gnu.org/licenses/gpl-3.0.txt',
    ),
);

$metadata['pear.php.net/HTTP_Request2'] = array(
    'version' => '2.1.1',
    'requires' => array(
        'php' => '>= 5.2.0',
        'pear.php.net/Net_URL2' => '>= 2.0.0',
    ),
    'suggests' => array(
        'pecl.php.net/curl',
        'pecl.php.net/fileinfo',
        'pecl.php.net/zlib',
        'pecl.php.net/openssl',
    ),
);

$metadata['pear.php.net/Net_URL2'] = array(
    'version' => '2.0.0',
    'requires' => array(
        'php' => '>= 5.0.0',
    ),
);

