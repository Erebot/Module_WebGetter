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
 * \brief
 *      A module that fetches content off
 *      an internet website.
 *
 * This module can be configured to respond to certain
 * commands by fetching content from an internet website,
 * grabbing some pieces of information using XPath expressions
 * and then outputting those information to an IRC channel
 * using a pre-configured format.
 */
class   Erebot_Module_WebGetter
extends Erebot_Module_Base
{
    /// Maps triggers to their index in the configuration.
    protected $_indexes;

    /**
     * \copydoc Erebot_Module_Base::_reload()
     */
    public function _reload($flags)
    {
        if ($flags & self::RELOAD_MEMBERS) {
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $registry   = $this->_connection->getModule(
                'Erebot_Module_TriggerRegistry'
            );

            $config         = $this->_connection->getConfig($this->_channel);
            $matchAny       = Erebot_Utils::getVStatic($registry, 'MATCH_ANY');
            $moduleConfig   = $config->getModule(get_class($this));
            $params         = $moduleConfig->getParamsNames();
            $filter         = new Erebot_Event_Match_Any();

            $this->_indexes = array();
            for ($index = 1; in_array($index.'.trigger', $params); $index++) {
                if (!in_array($index.'.url', $params))
                    throw new Erebot_InvalidValueException(
                        'Missing URL #'.$index
                    );

                if (!in_array($index.'.format', $params))
                    throw new Erebot_InvalidValueException(
                        'Missing format #'.$index
                    );

                $trigger    = trim($this->parseString($index.'.trigger'));
                $token = $registry->registerTriggers($trigger, $matchAny);
                if ($token === NULL) {
                    $fmt = $this->getFormatter(FALSE);
                    throw new Exception(
                        $fmt->_(
                            'Could not register trigger #<var name="index"/> '.
                            '(<var name="trigger"/>)',
                            array(
                                'index' => $index,
                                'trigger' => $trigger,
                            )
                        )
                    );
                }

                $filter->add(
                    new Erebot_Event_Match_TextWildcard($trigger.' *', FALSE)
                );
                $this->_indexes[strtolower($trigger)] = $index;
            }

            $handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleRequest')),
                new Erebot_Event_Match_All(
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Base_TextMessage'
                    ),
                    $filter
                )
            );
            $this->_connection->addEventHandler($handler);
            $this->registerHelpMethod(array($this, 'getHelp'));
        }
    }

    /// \copydoc Erebot_Module_Base::_unload()
    protected function _unload()
    {
    }

    /**
     * Sends help about this module.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Event that generated an help request.
     *
     * \param array $words
     *      Contents of the help request. The first element
     *      that makes up $words must be the module's name.
     *      While the other elements should be any extra
     *      arguments that make up the help request (such as
     *      a command name).
     *
     *  \retval bool
     *      TRUE to indicate the help request was handled successfully
     *      or FALSE if this module was unable to provide any help
     *      (such as when extra arguments were passed after a
     *      command name and those arguments could not be parsed).
     *
     *  \note
     *      If $words contains only one element, this method returns
     *      general information on this module (such as a list of
     *      all registered commands).
     */
    public function getHelp(
        Erebot_Interface_Event_Base_TextMessage $event,
        array                                   $words
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $trigger    = $this->parseString('trigger', 'tv');

        $bot        = $this->_connection->getBot();
        $moduleName = strtolower(get_class());
        $nbArgs     = count($words);
        $fmt        = $this->getFormatter($chan);

        if ($nbArgs == 1 && $words[0] == $moduleName) {
            $msg = $fmt->_(
                'Provides the <b><var name="trigger"/></b> command which '.
                'retrieves information about TV schedules off the internet.',
                array('trigger' => $trigger)
            );
            $this->sendMessage($target, $msg);
            return TRUE;
        }

        if (count($nbArgs) < 2)
            return FALSE;

        if ($words[1] == $trigger) {
            $msg = $fmt->_(
                "<b>Usage:</b> !<var name='trigger'/> [<u>time</u>] ".
                "[<u>channels</u>]. Returns TV schedules for the given ".
                "channels at the given time. [<u>time</u>] can be expressed ".
                "using either 12h or 24h notation. [<u>channels</u>] can be ".
                "a single channel name, a list of channels (separated by ".
                "commas) or one of the pre-defined groups of channels.",
                array('trigger' => $trigger)
            );
            $this->sendMessage($target, $msg);

            $msg = $fmt->_(
                "If none is given, the default group (<b><var ".
                "name='default'/></b>) is used. The following ".
                "groups are available: <for from='groups' key='group' ".
                "item='dummy'><b><var name='group'/></b></for>.",
                array(
                    'default' => $this->_defaultGroup,
                    'groups' => $this->_customMappings,
                )
            );
            $this->sendMessage($target, $msg);
            return TRUE;
        }
    }

    /**
     * Prefixes a string so it can be used to replace
     * Python-like substitutions with sprintf-like ones.
     *
     * \param string $a
     *      Original string to prefix.
     *
     * \retval string
     *      Prefixed string.
     */
    static private function _pctPrefix($a)
    {
        return '$('.$a.')';
    }

    /**
     * Turn an index into an sprintf positional
     * argument reference.
     *
     * \param int $a
     *      Numeric index to some argument in an array.
     *
     * \retval string
     *      Positional argument reference for use with
     *      sprintf.
     */
    static private function _increment($a)
    {
        return '%'.($a + 1).'$';
    }

    /**
     * Python-like formatting, compatible
     * with dictionary-based formats (%(foo)s).
     *
     * \param string $msg
     *
     * \param mixed $args
     *      Either NULL or a scalar type or an array,
     *      possibly containing the values that will
     *      make up the substitutions in $msg.
     *
     * \retval string
     *      Resulting string after all substitutions
     *      have been performed.
     *
     * \note
     *      If $args is NULL, $msg is returned untouched.
     *      If a scalar was passed, this method basically
     *      returns the same thing as sprintf($msg, $args).
     *      In an array was used, this does a job similar to Python's
     *      "%(foo)s %(bar)d %(baz)f" % dictionary, except that only
     *      the "s", "d" & "f" formats are supported and extra specifiers
     *      (eg. width specification for float formats) are not supported.
     *
     * \note
     *      Copy/pasted from PLOP :)
     */
    static protected function _injectContext($msg, $args)
    {
        if ($args === NULL || (is_array($args) && !count($args)))
            return $msg;

        if (!is_array($args))
            return sprintf($msg, $args);

        // Mapping = array(name => index)
        $msg        = strtr($msg, array('%' => '%%'));
        $keys       = array_keys($args);
        $mapping    = array_flip($keys);
        $keys       = array_map(array('self', '_pctPrefix'), $keys);
        $values     = array_map(array('self', '_increment'), $mapping);
        $mapping    = array_combine($keys, $values);
        $msg        = strtr($msg, $mapping);
        return vsprintf($msg, array_values($args));
    }

    /**
     * Returns the contents of some DOM node.
     *
     * \param DOMNode $node
     *      DOM node from which the content will be extracted.
     *
     * \retval string
     *      Contents of the node.
     */
    static protected function _getNodeContent($node)
    {
        return $node->textContent;
    }

    /**
     * Handles a request to fetch some content
     * off an internet website.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler responsible for triggering this method.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Actual request to fetch some content.
     *
     * \retval NULL
     *      This method does not return any value.
     */
    public function handleRequest(
        Erebot_Interface_EventHandler           $handler,
        Erebot_Interface_Event_Base_TextMessage $event
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $logging        = Plop::getInstance();
        $logger         = $logging->getLogger(__FILE__);
        $config         = $this->_connection->getConfig($this->_channel);
        $moduleConfig   = $config->getModule(get_class($this));
        $params         = $moduleConfig->getParamsNames();
        $fmt            = $this->getFormatter($chan);
        $botFmt         = $this->getFormatter(NULL);
        $locale         = $botFmt->getTranslator()->getLocale(
            Erebot_Interface_I18n::LC_MESSAGES
        );
        $parsedLocale   = Locale::parseLocale($locale);

        $text   = $event->getText();
        $index  = $this->_indexes[strtolower($text[0])];
        $method = in_array($index.'.post.1.name', $params)
            ? HTTP_Request2::METHOD_POST
            : HTTP_Request2::METHOD_GET;

        // Prepare context.
        $context = array(
            'language'  => $parsedLocale['language'],
            'region'    => $parsedLocale['region'],
            'locale'    => $locale,
            'locale2'   => str_replace('_', '-', $locale),
            '0'         => $text->getTokens(1),
        );
        foreach ($text as $i => $token) {
            if ($i == 0)
                continue;
            $context[(string) $i] = $token;
        }

        // Prepare request.
        $url = self::_injectContext(
            $this->parseString($index.'.url'),
            $context
        );
        $request = new HTTP_Request2(
            $url,
            $method,
            array(
                'follow_redirects'  => TRUE,
                'ssl_verify_peer'   => FALSE,
                'ssl_verify_host'   => FALSE,
                'timeout'           => $this->parseInt('timeout', 8),
                'connect_timeout'   => $this->parseInt('conn_timeout', 3),
            )
        );
        $request->setCookieJar(TRUE);
        $url = $request->getUrl();

        // GET variables.
        for ($i = 1; in_array($index.'.get.'.$i.'.name', $params) &&
            in_array($index.'.get.'.$i.'.value', $params); $i++) {
            $name   = $this->parseString($index.'.get.'.$i.'.name');
            $value  = self::_injectContext(
                $this->parseString($index.'.get.'.$i.'.value'),
                $context
            );
            $url->setQueryVariable($name, $value);
        }

        // POST variables.
        for ($i = 1; in_array($index.'.post.'.$i.'.name', $params) &&
            in_array($index.'.post.'.$i.'.value', $params); $i++) {
            $name   = $this->parseString($index.'.post.'.$i.'.name');
            $value  = self::_injectContext(
                $this->parseString($index.'.post.'.$i.'.value'),
                $context
            );
            $request->addPostParameter($name, $value);
        }

        // Parse the result.
        $mimes      = array(
            'application/xml',
            'text/xml',
            'text/html',
            'application/xhtml+xml',
        );
        $logger->debug(
            $botFmt->_('Retrieving "<var name="url"/>"'),
            array('url' => (string) $url)
        );
        try {
            $response   = $request->send();
        }
        catch (HTTP_Request2_Exception $e) {
            $msg = $fmt->_(
                'An error occurred while retrieving '.
                'the information (<var name="error"/>)',
                array('error', $e->getMessage())
            );
            return $this->sendMessage($target, $msg);
        }
        $mimeType   = $response->getHeader('content-type');
        $mimeType   = substr($mimeType, 0, strcspn($mimeType, ';'));
        if (!in_array($mimeType, $mimes))
            return $this->sendMessage(
                $target,
                $fmt->_('Invalid response received')
            );

        $domdoc = new DOMDocument();
        $domdoc->validateOnParse        = FALSE;
        $domdoc->preserveWhitespace     = FALSE;
        $domdoc->strictErrorChecking    = FALSE;
        $domdoc->substituteEntities     = TRUE;
        $domdoc->resolveExternals       = FALSE;
        $domdoc->recover                = TRUE;
        $uie = libxml_use_internal_errors(TRUE);
        $domdoc->loadHTML($response->getBody());
        libxml_clear_errors();
        libxml_use_internal_errors($uie);

        // Prepare input encoding.
        if (in_array($index.'.encoding', $params))
            $encoding = $this->parseString($index.'.encoding');
        else
            $encoding = NULL;

        // Apply XPath selections & do the rendering.
        $xpath = new DOMXPath($domdoc);
        for ($i = 1; in_array($index.'.vars.'.$i, $params); $i++) {
            $res = $xpath->evaluate(
                self::_injectContext(
                    $this->parseString($index.'.vars.'.$i),
                    $context
                )
            );
            if (is_object($res)) {
                if ($res instanceof DOMNode)
                    $res = $res->textContent;
                else if ($res instanceof DOMNodeList) {
                    $res = implode(
                        '',
                        array_map(
                            array('self', '_getNodeContent'),
                            (array) $res
                        )
                    );
                }
            }

            // If an encoding was supplied, use it.
            if ($encoding !== NULL) {
                try {
                    $res = Erebot_Utils::toUTF8($res, $encoding);
                }
                catch (Erebot_InvalidValueException $e) {
                }
                catch (Erebot_NotImplementedException $e) {
                }
            }
            $context['vars.'.$i] = $res;
        }

        return $this->sendMessage(
            $target,
            self::_injectContext(
                $this->parseString($index.'.format'),
                $context
            )
        );
    }
}

