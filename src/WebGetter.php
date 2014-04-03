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

namespace Erebot\Module;

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
class WebGetter extends \Erebot\Module\Base implements \Erebot\Interfaces\HelpEnabled
{
    /// Maps triggers to their index in the configuration.
    protected $indexes;

    /**
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot::Module::Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     */
    public function reload($flags)
    {
        if ($flags & self::RELOAD_HANDLERS) {
            $registry   = $this->connection->getModule(
                '\\Erebot\\Module\\TriggerRegistry'
            );

            $config         = $this->connection->getConfig($this->channel);
            $moduleConfig   = $config->getModule(get_called_class());
            $params         = $moduleConfig->getParamsNames();
            $filter         = new \Erebot\Event\Match\Any();

            $this->indexes = array();
            for ($index = 1; in_array($index.'.trigger', $params); $index++) {
                if (!in_array($index.'.url', $params)) {
                    throw new \Erebot\InvalidValueException(
                        'Missing URL #'.$index
                    );
                }

                if (!in_array($index.'.format', $params)) {
                    throw new \Erebot\InvalidValueException(
                        'Missing format #'.$index
                    );
                }

                $trigger    = trim($this->parseString($index.'.trigger'));
                $token = $registry->registerTriggers($trigger, $registry::MATCH_ANY);
                if ($token === null) {
                    $fmt = $this->getFormatter(false);
                    throw new \Exception(
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

                $filter->add(new \Erebot\Event\Match\TextWildcard($trigger.' *', false));
                $this->indexes[strtolower($trigger)] = $index;
            }

            $handler = new \Erebot\EventHandler(
                \Erebot\CallableWrapper::wrap(array($this, 'handleRequest')),
                new \Erebot\Event\Match\All(
                    new \Erebot\Event\Match\Type(
                        '\\Erebot\\Interfaces\\Event\\Base\\TextMessage'
                    ),
                    $filter
                )
            );
            $this->connection->addEventHandler($handler);
        }
    }

    /**
     * Provides help about this module.
     *
     * \param Erebot::Interfaces::Event::Base::TextMessage $event
     *      Some help request.
     *
     * \param Erebot::Interfaces::TextWrapper $words
     *      Parameters passed with the request. This is the same
     *      as this module's name when help is requested on the
     *      module itself (in opposition with help on a specific
     *      command provided by the module).
     */
    public function getHelp(
        \Erebot\Interfaces\Event\Base\TextMessage   $event,
        \Erebot\Interfaces\TextWrapper              $words
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Base\PrivateMessage) {
            $target = $event->getSource();
            $chan   = null;
        } else {
            $target = $chan = $event->getChan();
        }

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
            return true;
        }

        if (count($nbArgs) < 2) {
            return false;
        }

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
                    'default' => $this->defaultGroup,
                    'groups' => $this->customMappings,
                )
            );
            $this->sendMessage($target, $msg);
            return true;
        }
    }

    /**
     * Python-like formatting, compatible
     * with dictionary-based formats (%(foo)s).
     *
     * \param string $msg
     *
     * \param mixed $args
     *      Either \b null or a scalar type or an array,
     *      possibly containing the values that will
     *      make up the substitutions in $msg.
     *
     * \retval string
     *      Resulting string after all substitutions
     *      have been performed.
     *
     * \note
     *      If $args is \b null, $msg is returned untouched.
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
    protected static function injectContext($msg, $args)
    {
        if ($args === null || (is_array($args) && !count($args))) {
            return $msg;
        }

        if (!is_array($args)) {
            return sprintf($msg, $args);
        }

        $mapKeys = $mapValues = array();

        $msg        = strtr($msg, array('%' => '%%'));
        $keys       = array_keys($args);
        $mapping    = array_flip($keys);

        foreach ($keys as $key) {
            $mapKeys[] = '$('.$key.')';
        }
        foreach ($mapping as $value) {
            $mapValues[] = '%'.($value + 1).'$';
        }

        // Mapping = array(name => index)
        $mapping    = array_combine($mapKeys, $mapValues);
        $msg        = strtr($msg, $mapping);
        return vsprintf($msg, array_values($args));
    }

    /**
     * Returns the context for a new HTTP request.
     *
     * \param Erebot::IntlInterface $botFmt
     *      Main formatter used by the bot.
     *
     * \param Erebot::Interfaces::TextWrapper $text
     *      The text from the IRC event.
     *
     * \retval array
     *      Array containing the new context.
     */
    protected function prepareContext($botFmt, $text)
    {
        // Prepare context.
        $locale = $botFmt->getTranslator()->getLocale(\Erebot\IntlInterface::LC_MESSAGES);
        $parsedLocale = \Locale::parseLocale($locale);
        $context = array(
            'language'  => $parsedLocale['language'],
            'region'    => $parsedLocale['region'],
            'locale'    => $locale,
            'locale2'   => str_replace('_', '-', $locale),
            '0'         => $text->getTokens(1),
        );

        foreach ($text as $i => $token) {
            if ($i == 0) {
                continue;
            }
            $context[(string) $i] = $token;
        }
        return $context;
    }

    /**
     * Returns the GET parameters for the request.
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
     * \retval string
     *      The request's parameters, in query string format.
     */
    protected function getParams($index, $params, $context)
    {
        $data = array();
        for ($i = 1; in_array($index.'.get.'.$i.'.name', $params) &&
            in_array($index.'.get.'.$i.'.value', $params); $i++) {
            $name   = $this->parseString($index.'.get.'.$i.'.name');
            $value  = self::injectContext(
                $this->parseString($index.'.get.'.$i.'.value'),
                $context
            );
            $data[$name] = $value;
        }
        return http_build_query($data, 'arg', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Returns POST data for the HTTP request.
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
     * \retval string
     *      POST data.
     */
    protected function getPostData($index, $params, $context)
    {
        $data = array();
        for ($i = 1; in_array($index.'.post.'.$i.'.name', $params) &&
            in_array($index.'.post.'.$i.'.value', $params); $i++) {
            $name   = $this->parseString($index.'.post.'.$i.'.name');
            $value  = self::injectContext(
                $this->parseString($index.'.post.'.$i.'.value'),
                $context
            );
            $data[$name] = $value;
        }
        return http_build_query($data, 'arg', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Processes the response to an HTTP request.
     *
     * \param opaque $response
     *      Response to the HTTP request.
     *
     * \param mixed $encoding
     *      Encoding for the response. Either \b null
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
    protected function processResponse(
        \Requests_Response $response,
        $encoding,
        $index,
        $params,
        &$context
    ) {
        $isPre  = in_array($index.'.pre', $params);
        $domdoc = new \DOMDocument();
        $domdoc->validateOnParse        = false;
        $domdoc->preserveWhitespace     = false;
        $domdoc->strictErrorChecking    = false;
        $domdoc->substituteEntities     = true;
        $domdoc->resolveExternals       = false;
        $domdoc->recover                = true;
        $uie = libxml_use_internal_errors(true);
        $domdoc->loadHTML($response->body);
        libxml_clear_errors();
        libxml_use_internal_errors($uie);

        // Apply XPath selections & add data to the context.
        $xpath          = new \DOMXPath($domdoc);
        $docEncoding    = $domdoc->encoding ? $domdoc->encoding : 'UTF-8';
        for ($i = 1; in_array($index.'.vars.'.$i, $params); $i++) {
            $res = $xpath->query(
                self::injectContext(
                    $this->parseString($index.'.vars.'.$i),
                    $context
                )
            );

            if (is_object($res)) {
                if ($res instanceof \DOMNode) {
                    $res = htmlspecialchars(
                        $res->textContent,
                        ENT_QUOTES,
                        $docEncoding
                    );
                    if (!$isPre) {
                        $res = trim($res);
                    }
                    $res = array($res);
                } elseif ($res instanceof \DOMNodeList) {
                    $nodesRes = array();
                    $nbNodes = $res->length;
                    for ($j = 0; $j < $nbNodes; $j++) {
                        $textContent = htmlspecialchars(
                            $res->item($j)->textContent,
                            ENT_QUOTES,
                            $docEncoding
                        );
                        if (!$isPre) {
                            $textContent = trim($textContent);
                        }
                        if ($textContent != "") {
                            $nodesRes[] = $textContent;
                        }
                    }
                    $res = $nodesRes;
                }
            } elseif (is_string($res)) {
                ;
            } else {
                $res = array("");
            }

            // If an encoding was supplied, use it.
            if ($encoding === null) {
                $encoding = $docEncoding;
            }

            if (count($res)) {
                try {
                    $res = array_map(
                        '\\Erebot\\Utils::toUTF8',
                        $res,
                        array_fill(0, count($res), $encoding)
                    );
                } catch (\Erebot\InvalidValueException $e) {
                } catch (\Erebot\NotImplementedException $e) {
                }
            }

            switch (count($res)) {
                case 0:
                    $res = "";
                    break;

                case 1:
                    $res = $res[0];
                    break;
            }
            $context['vars.'.$i] = $res;
        }
    }

    /**
     * Handles a request to fetch some content
     * off an internet website.
     *
     * \param Erebot::Interfaces::EventHandler $handler
     *      Handler responsible for triggering this method.
     *
     * \param Erebot::Interfaces::Event::Base::TextMessage $event
     *      Actual request to fetch some content.
     *
     * \retval null
     *      This method does not return any value.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function handleRequest(
        \Erebot\Interfaces\EventHandler             $handler,
        \Erebot\Interfaces\Event\Base\TextMessage   $event
    ) {
        if ($event instanceof \Erebot\Interfaces\Event\Base\PrivateMessage) {
            $target = $event->getSource();
            $chan   = null;
        } else {
            $target = $chan = $event->getChan();
        }

        $logger     = \Plop::getInstance();
        $config     = $this->connection->getConfig($this->channel);
        $modConfig  = $config->getModule(get_class($this));
        $params     = $modConfig->getParamsNames();
        $fmt        = $this->getFormatter($chan);
        $botFmt     = $this->getFormatter(null);

        $text       = $event->getText();
        $index      = $this->indexes[strtolower($text[0])];
        $method     = in_array($index.'.post.1.name', $params) ? \Requests::POST : \Requests::GET;

        $context    = $this->prepareContext($botFmt, $text);
        $url        = new \Erebot\URI(self::injectContext($this->parseString($index.'.url'), $context));
        $getParams  = $this->getParams($index, $params, $context);
        $postData   = $this->getPostData($index, $params, $context);
        $options    = array(
            'verify'        => false,
            'verifyname'    => false,
            'timeout'       => $this->parseInt('timeout', 8),
        );
        $query      = $url->getQuery();
        $url->setQuery(rtrim($getParams . '&' . $query, '&'));

        if (in_array($index.'.user-agent', $params)) {
            $userAgent = $this->parseString($index.'.user-agent');
            if ($userAgent == "") {
                $options['useragent'] = null;
            } else {
                $options['useragent'] = $userAgent;
            }
        }

        $response = \Requests::request(
            (string) $url,
            array(),
            $postData,
            $method,
            $options
        );

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
        $mimeType   = $response->headers['content-type'];
        $mimeType   = substr($mimeType, 0, strcspn($mimeType, ';'));
        if (!in_array($mimeType, $mimes)) {
            return $this->sendMessage(
                $target,
                $fmt->_('Invalid response received')
            );
        }

        // Prepare input encoding.
        if (in_array($index.'.encoding', $params)) {
            $encoding = $this->parseString($index.'.encoding');
        } else {
            $encoding = null;
        }

        $this->processResponse($response, $encoding, $index, $params, $context);

        if (in_array($index.'.pre', $params)) {
            foreach ($context as $name => &$value) {
                if (substr($name, 0, 5) == 'vars.' && !is_array($value)) {
                    $value = preg_split('/\\r\\n?|\\n/', $value);
                }
            }
            unset($value);
        }

        $multiples = array();
        foreach ($context as $name => $value) {
            if (substr($name, 0, 5) == 'vars.' && is_array($value)) {
                if (count($multiples) &&
                    count($context[$name]) != count($context[$multiples[0]])) {
                    return $this->sendMessage(
                        $target,
                        $fmt->_("Multiple arrays with varying lengths found")
                    );
                }
                $multiples[] = $name;
            }
        }

        if (!count($multiples)) {
            $output = self::injectContext(
                $this->parseString($index.'.format'),
                $context
            );

            if ($output == "") {
                return $this->sendMessage(
                    $target,
                    $fmt->_("Oops, nothing to send!")
                );
            }
            return $this->sendMessage($target, $fmt->render($output));
        }

        // Duplicate unique values to match length of other arrays.
        $nbValues   = count($context[$multiples[0]]);
        $keys       = array_keys($context);
        $linesSent  = 0;
        $oldContext = $context;

        for ($i = 0; $i < $nbValues; $i++) {
            $context = array();
            foreach ($keys as $key) {
                if (!is_array($oldContext[$key])) {
                    $context[$key] = $oldContext[$key];
                } else {
                    $context[$key] = $oldContext[$key][$i];
                }
            }

            $output = self::injectContext(
                $this->parseString($index.'.format'),
                $context
            );
            if ($output == "") {
                continue;
            }
            $this->sendMessage($target, $fmt->render($output));
            $linesSent++;
        }

        if (!$linesSent) {
            $this->sendMessage($target, $fmt->_("Oops, nothing to send!"));
        }
    }
}
