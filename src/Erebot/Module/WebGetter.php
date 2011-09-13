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

class   Erebot_Module_WebGetter
extends Erebot_Module_Base
{
    protected $_indexes;

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
                    $translator = $this->getTranslator(FALSE);
                    throw new Exception(
                        $translator->gettext(
                            'Could not register trigger #'.$index.
                            ' for "'.$trigger.'"'
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

    protected function _unload()
    {
    }

    public function getHelp(
        Erebot_Interface_Event_Base_TextMessage $event,
                                                $words
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $translator = $this->getTranslator($chan);
        $trigger    = $this->parseString('trigger', 'tv');

        $bot        = $this->_connection->getBot();
        $moduleName = strtolower(get_class());
        $nbArgs     = count($words);

        if ($nbArgs == 1 && $words[0] == $moduleName) {
            $msg = $translator->gettext(
                'Provides the <b><var name="trigger"/></b> command which '.
                'retrieves information about TV schedules off the internet.'
            );
            $formatter = new Erebot_Styling($msg, $translator);
            $formatter->assign('trigger', $trigger);
            $this->sendMessage($target, $formatter->render());
            return TRUE;
        }

        if (count($nbArgs) < 2)
            return FALSE;

        if ($words[1] == $trigger) {
            $msg = $translator->gettext(
                "<b>Usage:</b> !<var name='trigger'/> [<u>time</u>] ".
                "[<u>channels</u>]. Returns TV schedules for the given ".
                "channels at the given time. [<u>time</u>] can be expressed ".
                "using either 12h or 24h notation. [<u>channels</u>] can be ".
                "a single channel name, a list of channels (separated by ".
                "commas) or one of the pre-defined groups of channels."
            );
            $formatter = new Erebot_Styling($msg, $translator);
            $formatter->assign('trigger', $trigger);
            $this->sendMessage($target, $formatter->render());

            $msg = $translator->gettext(
                "If none is given, the default group (<b><var ".
                "name='default'/></b>) is used. The following ".
                "groups are available: <for from='groups' key='group' ".
                "item='dummy'><b><var name='group'/></b></for>."
            );
            $formatter = new Erebot_Styling($msg, $translator);
            $formatter->assign('default', $this->_defaultGroup);
            $formatter->assign('groups', $this->_customMappings);
            $this->sendMessage($target, $formatter->render());

            return TRUE;
        }
    }

    // Taken from PLOP :)
    static protected function _injectContext($msg, $args)
    {
        if ($args === NULL || (is_array($args) && !count($args)))
            return $msg;

        if (!is_array($args))
            return sprintf($msg, $args);

        // Mapping = array(name => index)
        $keys       = array_keys($args);
        $mapping    = array_flip($keys);
        $pctPrefix  = create_function('$a', 'return "$(".$a.")";');
        $increment  = create_function('$a', 'return "%".($a + 1)."\\$";');
        $keys       = array_map($pctPrefix, $keys);
        $values     = array_map($increment, $mapping);
        $mapping    = array_combine($keys, $values);
        $msg        = strtr($msg, $mapping);
        return vsprintf($msg, array_values($args));
    }

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
        $translator     = $this->getTranslator($chan);
        $logTranslator  = $this->getTranslator(NULL);
        $locale         = $logTranslator->getLocale(
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
        $logger->debug($logTranslator->gettext('Retrieving "%s"'), (string) $url);
        try {
            $response   = $request->send();
        }
        catch (HTTP_Request2_Exception $e) {
            $msg = $translator->gettext(
                'An error occurred while retrieving '.
                'the information (<var name="error"/>)'
            );
            $tpl = new Erebot_Styling($msg, $translator);
            $tpl->assign('error', $e->getMessage());
            return $this->sendMessage($target, $tpl->render());
        }
        $mimeType   = $response->getHeader('content-type');
        $mimeType   = substr($mimeType, 0, strcspn($mimeType, ';'));
        if (!in_array($mimeType, $mimes))
            return $this->sendMessage(
                $target,
                $translator->gettext('Invalid response received')
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

        // Apply XPath selections & render the result.
        $xpath = new DOMXPath($domdoc);
        $serializer = create_function('$node', 'return $node->textContent;');
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
                else if ($res instanceof DOMNodeList)
                    $res = implode('', array_map($serializer, (array) $res));
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

