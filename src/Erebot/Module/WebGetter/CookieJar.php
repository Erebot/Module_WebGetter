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

/**
 * Stores cookies and passes them between HTTP requests
 * This class is only used to workaround a limitation of HTTP_Request2
 * when running from a PHAR archive.
 */
class   Erebot_Module_WebGetter_CookieJar
extends HTTP_Request2_CookieJar
{
    /**
     * Class constructor, sets various options
     *
     * \param bool $serializeSessionCookies
     *      Controls serializing session cookies.
     *
     * \param bool $usePublicSuffixList
     *      Controls using Public Suffix List.
     */
    public function __construct(
        $serializeSessionCookies    = FALSE,
        $usePublicSuffixList        = TRUE
    )
    {
        parent::__construct($serializeSessionCookies, $usePublicSuffixList);
        if (!$usePublicSuffixList || strncasecmp(__FILE__, 'phar://', 7))
            return;

        // Load the public suffix list when running as a phar.
        parent::$psl = require_once(
            dirname(dirname(dirname(dirname(dirname(__FILE__))))) .
            DIRECTORY_SEPARATOR . "data" .
            DIRECTORY_SEPARATOR . "pear.php.net" .
            DIRECTORY_SEPARATOR . "HTTP_Request2" .
            DIRECTORY_SEPARATOR . "public-suffix-list.php"
        );
    }
}

