<?php

namespace Namshi\Cuzzle\Formatter;

use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\RequestInterface;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

class CurlFormatter
{
    /**
     * @var string
     */
    protected $command;

    /**
     * @var int
     */
    protected $currentLineLength;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var int
     */
    protected $commandLineLength;

    /**
     * @param int $commandLineLength
     */
    function __construct($commandLineLength =  100)
    {
        $this->commandLineLength = $commandLineLength;
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     * @return string
     */
    public function format(RequestInterface $request, array $options = [])
    {
        $this->command           = 'curl';
        $this->currentLineLength = strlen($this->command);
        $this->options           = [];

        $this->extractArguments($request, $options);
        $this->addOptionsToCommand();

        return $this->command;
    }

    /**
     * @param int $commandLineLength
     *
     * @return void
     */
    public function setCommandLineLength($commandLineLength)
    {
        $this->commandLineLength = $commandLineLength;
    }

    /**
     * @param mixed $name
     * @param mixed|null $value
     *
     * @return void
     */
    protected function addOption($name, $value = null)
    {
        if (isset($this->options[$name])) {
            if (!is_array($this->options[$name])) {
                $this->options[$name] = (array)$this->options[$name];
            }

            $this->options[$name][] = $value;
        } else {
            $this->options[$name] = $value;
        }

    }

    /**
     * @param mixed $part
     *
     * @return void
     */
    protected function addCommandPart($part)
    {
        $this->command .= ' ';

        if ($this->commandLineLength > 0 && $this->currentLineLength + strlen($part) > $this->commandLineLength) {
            $this->currentLineLength = 0;
            $this->command .= "\\\n  ";
        }

        $this->command .= $part;
        $this->currentLineLength += strlen($part) + 2;
    }

    /**
     * @param RequestInterface $request
     *
     * @return void
     */
    protected function extractHttpMethodArgument(RequestInterface $request)
    {
        if ('GET' !== $request->getMethod() ) {
            if ('HEAD' === $request->getMethod()) {
                $this->addOption('-head');
            } else {
                $this->addOption('X', $request->getMethod());
            }
        }
    }

    /**
     * @param RequestInterface $request
     *
     * @return void
     */
    protected function extractBodyArgument(RequestInterface $request)
    {
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $previousPosition = $body->tell();
            $body->rewind();
        }

        $contents = $body->getContents();

        if ($body->isSeekable()) {
            $body->seek($previousPosition);
        }

        if ($contents) {
            // clean input of null bytes
            $contents = str_replace(chr(0), '', $contents);
            $this->addOption('d', $this->escapeShellArgument($contents));
        }

        //if get request has data Add G otherwise curl will make a post request
        if (!empty($this->options['d']) && ('GET' === $request->getMethod())){
            $this->addOption('G');
        }
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return void
     */
    protected function extractCookiesArgument(RequestInterface $request, array $options)
    {
        if (!isset($options['cookies']) || !$options['cookies'] instanceof CookieJarInterface) {
            return;
        }

        $values = [];
        $scheme = $request->getUri()->getScheme();
        $host   = $request->getUri()->getHost();
        $path   = $request->getUri()->getPath();

        /** @var SetCookie $cookie */
        foreach ($options['cookies'] as $cookie) {
            if ($cookie->matchesPath($path) && $cookie->matchesDomain($host) &&
                ! $cookie->isExpired() && ( ! $cookie->getSecure() || $scheme === 'https')) {

                $values[] = $cookie->getName() . '=' . $cookie->getValue();
            }
        }

        if ($values) {
            $this->addOption('b', $this->escapeShellArgument(implode('; ', $values)));
        }
    }

    /**
     * @param RequestInterface $request
     *
     * @return void
     */
    protected function extractHeadersArgument(RequestInterface $request)
    {
        foreach ($request->getHeaders() as $name => $header) {
            if ('host' === strtolower($name) && $header[0] === $request->getUri()->getHost()) {
                continue;
            }

            if ('user-agent' === strtolower($name)) {
                $this->addOption('A', $this->escapeShellArgument($header[0]));
                continue;
            }

            foreach ((array)$header as $headerValue) {
                $this->addOption('H', $this->escapeShellArgument("{$name}: {$headerValue}"));
            }
        }
    }

    /**
     * @return void
     */
    protected function addOptionsToCommand()
    {
        ksort($this->options);

        if ($this->options) {
            foreach ($this->options as $name => $value) {
                if (is_array($value)) {
                    foreach ($value as $subValue) {
                        $this->addCommandPart("-{$name} {$subValue}");
                    }
                } else {
                    $this->addCommandPart("-{$name} {$value}");
                }
            }
        }
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return void
     */
    protected function extractArguments(RequestInterface $request, array $options)
    {
        $this->extractHttpMethodArgument($request);
        $this->extractBodyArgument($request);
        $this->extractCookiesArgument($request, $options);
        $this->extractHeadersArgument($request);
        $this->extractUrlArgument($request);
        $this->extractDataArgument($options);
        $this->extractFormArgument($options);
    }

    /**
     * @param RequestInterface $request
     *
     * @return void
     */
    protected function extractUrlArgument(RequestInterface $request)
    {
        $this->addCommandPart($this->escapeShellArgument((string)$request->getUri()->withFragment('')));
    }

    protected function escapeShellArgument($argument)
    {
        $process = new Process([$argument]);
        return $process->getCommandLine();
    }

    /**
     * @param array $options
     */
    protected function extractFormArgument(array $options)
    {
        if (!isset($options['multipart'])) {
            return;
        }
        $parsedArray = [];
        foreach ($options['multipart'] as $key => $value) {
            if (is_array($value) && array_key_exists('contents', $value) && array_key_exists('name', $value)) {
                $parsedArray[$value['name']] = $value['contents'];
            }
        }
        $values = [];
        foreach ($parsedArray as $key => $value) {
            $values[] = "$key=$value";
        }
        foreach ($values as $value) {
            $this->addOption('F', $this->escapeShellArgument($value));
        }
    }

    /**
     * @param array $options
     */
    protected function extractDataArgument(array $options)
    {
        if (!isset($options['form_params'])) {
            return;
        }
        $values = [];
        foreach ($this->parseFormdataArray($options['form_params']) as $key => $value) {
            $values[] = "$key=$value";
        }
        foreach ($values as $value) {
            $this->addOption('d', $this->escapeShellArgument($value));
        }
    }

    /**
     * Flattens multidimensional array by keys concatenation
     *
     * @param array $array
     * @return array
     */
    public function parseFormDataArray(array $array = []) : array
    {
        $res = [];
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($array),
            RecursiveIteratorIterator::LEAVES_ONLY);
        $it->rewind();
        while ($it->valid()) {
            $value = $it->current();
            $i = 0;
            $sub = $it->getSubIterator($i++);
            $keys = $sub->key();
            while ($sub = $it->getSubIterator($i++)) {
                $key = $sub->key();
                $keys .= "[$key]";
            }
            $res[$keys] =  $value;
            $it->next();
        }
        return $res;
    }
}
