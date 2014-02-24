<?php

namespace TechDivision\Http;

use TechDivision\Socket\SocketInterface;
use TechDivision\Http\RequestInterface;
use TechDivision\Http\ParserInterface;
use TechDivision\Http\ConnectionException;
use TechDivision\Http\HttpProtocol;

class HttpConnection implements ConnectionInterface
{

    /**
     * Holds the socket implementation to use for connection handling.
     *
     * @var \TechDivision\Socket\SocketInterface
     */
    protected $socket;

    /**
     * The connection needs a socket implementation to handle the connection.
     *
     * @param \TechDivision\Socket\SocketInterface $socket  The socket implementation to use for connection handling.
     * @param \TechDivision\Http\ParserInterface   $parser  The parser to use for
     */
    public function __construct(SocketInterface $socket, ParserInterface $parser)
    {
        $this->socket = $socket;
        $this->parser = $parser;
    }

    /**
     * Return's the socket implementation
     *
     * @return \TechDivision\Socket\SocketInterface
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * @return \TechDivision\Http\ParserInterface
     */
    public function getParser()
    {
        return $this->parser;
    }

    /**
     * Negotiates the connection with the connected client in a proper way the given
     * protocol type and version expects. The result will be a request instance if all data was valid.
     *
     * @return \TechDivision\Http\RequestInterface The request instance
     */
    public function negotiate()
    {
        try {
            // get instances for short calls
            $parser = $this->getParser();
            $socket = $this->getSocket();

            // read first line from connection socket
            $line = $socket->readLine();

            /**
             * In the interest of robustness, servers SHOULD ignore any empty
             * line(s) received where a Request-Line is expected. In other words, if
             * the server is reading the protocol stream at the beginning of a
             * message and receives a CRLF first, it should ignore the CRLF.
             *
             * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec4.html#sec4.1
             */
            if ($line === "\r\n") {
                // ignore the first CRLF and go on reading the expected start-line.
                $line = $socket->readLine();
            }
            // parse read line
            $parser->parseStartLine($line);

            /**
             * Parse headers in a proper way
             *
             * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec4.html#sec4.2
             */
            $messageHeaders = '';
            while($line != "\r\n") {
                // read next line
                $line = $socket->readLine();
                // enhance headers
                $messageHeaders .= $line;
            }

            // parse headers
            $parser->parseHeaders($messageHeaders);

            // check if message body will be transmitted
            if ($parser->getRequest()->hasHeader(HttpProtocol::HEADER_CONTENT_LENGTH)) {
                // get content-length header
                $contentLength = (int)$parser->getRequest()->getHeader(HttpProtocol::HEADER_CONTENT_LENGTH);
                // read content until given content-length
                while(ftell($parser->getRequest()->getBodyStream()) < $contentLength) {
                    // read next line
                    $line = $socket->readLine();
                    // enhance body with new line
                    fwrite($parser->getRequest()->getBodyStream(), $line, strlen($line));
                }
                // set pointer offset to zero
                fseek($parser->getRequest()->getBodyStream(), 0);
            }

            // write response headers
            $socket->write($parser->getResponse()->getHeaderString());
            // stream response body to connection
            $socket->copyStream($parser->getResponse()->getBodyStream());

            $socket->close();

        } catch (\Exception $e) {
            $socket->write($e->getMessage() . PHP_EOL);
            $socket->close();
        }
    }
}
