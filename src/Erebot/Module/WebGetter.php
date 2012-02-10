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
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot_Module_Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
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

            $cls = $this->getFactory('!Callable');
            $this->registerHelpMethod(new $cls(array($this, 'getHelp')));
        }
    }

    /**
     * Provides help about this module.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Some help request.
     *
     * \param Erebot_Interface_TextWrapper $words
     *      Parameters passed with the request. This is the same
     *      as this module's name when help is requested on the
     *      module itself (in opposition with help on a specific
     *      command provided by the module).
     */
    public function getHelp(
        Erebot_Interface_Event_Base_TextMessage $event,
        Erebot_Interface_TextWrapper            $words
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $trigger    = $this->parseString('trigger', 'tv');

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

        $mapKeys = $mapValues = array();

        $msg        = strtr($msg, array('%' => '%%'));
        $keys       = array_keys($args);
        $mapping    = array_flip($keys);

        foreach ($keys as $key)
            $mapKeys[] = '$('.$key.')';
        foreach ($mapping as $value)
            $mapValues[] = '%'.($value + 1).'$';

        // Mapping = array(name => index)
        $mapping    = array_combine($mapKeys, $mapValues);
        $msg        = strtr($msg, $mapping);
        return vsprintf($msg, array_values($args));
    }

    /**
     * Returns the context for a new HTTP request.
     *
     * \param Erebot_Interface_I18n $botFmt
     *      Main formatter used by the bot.
     *
     * \param Erebot_Interface_TextWrapper $text
     *      The text from the IRC event.
     *
     * \retval array
     *      Array containing the new context.
     */
    protected function _prepareContext($botFmt, $text)
    {
        // Prepare context.
        $locale         = $botFmt->getTranslator()->getLocale(
            Erebot_Interface_I18n::LC_MESSAGES
        );
        $parsedLocale   = Locale::parseLocale($locale);
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
        return $context;
    }

    /**
     * Creates a new HTTP request.
     *
     * \param int $index
     *      Index of the trigger.
     *
     * \param array $context
     *      Array containing the context for this request.
     *
     * \param opaque $method
     *      HTTP method, either HTTP_Request2::METHOD_GET
     *      or HTTP_Request2::METHOD_POST.
     *
     * \retval HTTP_Request2
     *      The new HTTP request.
     */
    protected function _prepareRequest($index, $context, $method)
    {
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
        return $request;
    }

    /**
     * Adds GET parameters to an URL.
     *
     * \param int $index
     *      Index of the trigger.
     *
     * \param array $params
     *      A list with the names of the parameters associated
     *      with this module.
     *
     * \param array $context
     *      Context for this HTTP request.
     *
     * \param opaque $url
     *      URL the parameters will be added to.
     *
     * \return
     *      This method does not return anything.
     *
     * \post
     *      The URL is updated with the GET parameters.
     */
    protected function _addGetParams($index, $params, $context, $url)
    {
        for ($i = 1; in_array($index.'.get.'.$i.'.name', $params) &&
            in_array($index.'.get.'.$i.'.value', $params); $i++) {
            $name   = $this->parseString($index.'.get.'.$i.'.name');
            $value  = self::_injectContext(
                $this->parseString($index.'.get.'.$i.'.value'),
                $context
            );
            $url->setQueryVariable($name, $value);
        }
    }

    /**
     * Adds POST parameters to an HTTP request.
     *
     * \param int $index
     *      Index of the trigger.
     *
     * \param array $params
     *      A list with the names of the parameters associated
     *      with this module.
     *
     * \param array $context
     *      Context for this HTTP request.
     *
     * \param HTTP_Request2 $request
     *      HTTP request the parameters will be added to.
     *
     * \return
     *      This method does not return anything.
     *
     * \post
     *      The HTTP request is updated with the POST parameters.
     */
    protected function _addPostParams($index, $params, $context, $request)
    {
        for ($i = 1; in_array($index.'.post.'.$i.'.name', $params) &&
            in_array($index.'.post.'.$i.'.value', $params); $i++) {
            $name   = $this->parseString($index.'.post.'.$i.'.name');
            $value  = self::_injectContext(
                $this->parseString($index.'.post.'.$i.'.value'),
                $context
            );
            $request->addPostParameter($name, $value);
        }
    }

    /**
     * Processes the response to an HTTP request.
     *
     * \param opaque $response
     *      Response to the HTTP request.
     *
     * \param mixed $encoding
     *      Encoding for the response. Either NULL
     *      (do not change the encoding) or a string
     *      with the name of the encoding.
     *
     * \param int $index
     *      Index of the trigger.
     *
     * \param array $params
     *      A list with the names of the parameters
     *      associated with this module.
     *
     * \param array $context
     *      Context for this HTTP request.
     *
     * \return
     *      This method does not return anything.
     *
     * \post
     *      The context is updated with the information
     *      extracted from the HTTP response.
     */
    protected function _processResponse(
        $response,
        $encoding,
        $index,
        $params,
        &$context
    )
    {
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

        // Apply XPath selections & add date to the context.
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
                    $nodesRes = '';
                    foreach ((array) $res as $node)
                        $nodesRes .= $node->textContent;
                    $res = $nodesRes;
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
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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

        $text   = $event->getText();
        $index  = $this->_indexes[strtolower($text[0])];
        $method = in_array($index.'.post.1.name', $params)
            ? HTTP_Request2::METHOD_POST
            : HTTP_Request2::METHOD_GET;

        $context    = $this->_prepareContext($botFmt, $text);
        $request    = $this->_prepareRequest($index, $context, $method);
        $url        = $request->getUrl();
        $this->_addGetParams($index, $params, $context, $url);
        $this->_addPostParams($index, $params, $context, $request);

        // Parse the result.
        $mimes      = array(
            'application/xml',
            'text/xml',
            'text/html',
            'application/xhtml+xml',
        );
        $logger->debug(
            $botFmt->_(
                'Retrieving "<var name="url"/>"',
                array('url' => (string) $url)
            )
        );
        try {
            $response   = $request->send();
        }
        catch (HTTP_Request2_Exception $e) {
            $msg = $fmt->_(
                'An error occurred while retrieving '.
                'the information (<var name="error"/>)',
                array('error' => $e->getMessage())
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

        // Prepare input encoding.
        if (in_array($index.'.encoding', $params))
            $encoding = $this->parseString($index.'.encoding');
        else
            $encoding = NULL;

        $this->_processResponse(
            $response, $encoding, $index,
            $params, $context
        );

        $output = self::_injectContext(
            $this->parseString($index.'.format'),
            $context
        );
        return $this->sendMessage($target, $output);

    }
}

