<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\Mail\Header;

use Zend\Mail\Headers;

class ContentDisposition implements HeaderInterface
{
    const DISPOSITION_ATTACHMENT = 'attachment';
    const DISPOSITION_INLINE     = 'inline';

    /**
     * Allowed Content-Disposition parameters specified by RFC 1521
     * (reduced set)
     * @var array
     */
    protected static $allowedDispositions = array(
        self::DISPOSITION_ATTACHMENT,
        self::DISPOSITION_INLINE
    );

    /**
     * @var string
     */
    protected $disposition;

    /**
     * @var array
     */
    protected $parameters = array();

    /**
     * @var string
     */
    protected $filename = '';

    public function setFilename($name)
    {
      $this->filename = $name;
    }

    public static function fromString($headerLine)
    {
        $headerLine = iconv_mime_decode($headerLine, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        list($name, $value) = GenericHeader::splitHeaderLine($headerLine);

        // check to ensure proper header type for this factory
        if (strtolower($name) !== 'content-disposition') {
            throw new Exception\InvalidArgumentException('Invalid header line for Content-Disposition string');
        }

        $value  = str_replace(Headers::FOLDING, " ", $value);
        $values = preg_split('#\s*;\s*#', $value);
        $type   = array_shift($values);

        $header = new static();
        $header->setDisposition($type);

        if (count($values)) {
          foreach ($values as $keyValuePair) {
            list($key, $value) = explode('=', $keyValuePair, 2);
            $value = trim($value, "'\" \t\n\r\0\x0B");
            if($key != "filename") $header->addParameter($key, $value);
            else $header->setFilename($value);
          }
        }

        return $header;
    }

    public function addParameter($key, $value)
    {
        $this->parameters[$key] = $value;
    }

    public function getFilename()
    {
      return $this->filename;
    }

    public function getFieldName()
    {
        return 'Content-Disposition';
    }

    public function getFieldValue($format = HeaderInterface::FORMAT_RAW)
    {
        return $this->disposition;
    }

    public function setEncoding($encoding)
    {
        // Header must be always in US-ASCII
        return $this;
    }

    public function getEncoding()
    {
        return 'ASCII';
    }

    public function toString()
    {
        return 'Content-Disposition: ' . $this->getFieldValue();
    }

    /**
     * Set the disposition
     *
     * @param  string $disposition
     * @throws Exception\InvalidArgumentException
     * @return self
     */
    public function setDisposition($disposition)
    {
        if (!in_array($disposition, self::$allowedDispositions)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects one of "'. implode(', ', self::$allowedDispositions) . '"; received "%s"',
                __METHOD__,
                (string) $transferEncoding
            ));
        }
        $this->disposition = $disposition;
        return $this;
    }

    /**
     * Retrieve the content transfer encoding
     *
     * @return string
     */
    public function getDisposition()
    {
        return $this->disposition;
    }
}
