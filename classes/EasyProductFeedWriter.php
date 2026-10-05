<?php
/**
 * Streams feed items to disk (XML RSS 2.0 with the g: namespace, or CSV).
 * Items are written one at a time so memory usage stays flat regardless of
 * catalogue size.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EasyProductFeedWriter
{
    private $handle;
    private $format;
    private $columns;
    private $count = 0;

    /**
     * @param string $path    file to write (a temp path; the caller renames it when done)
     * @param string $format  'xml' or 'csv'
     * @param array  $columns ordered list of item keys to output
     * @param array  $channel XML only: ['title' => .., 'link' => .., 'description' => ..]
     */
    public function __construct($path, $format, array $columns, array $channel = array())
    {
        $this->format = $format === 'csv' ? 'csv' : 'xml';
        $this->columns = $columns;
        $this->handle = @fopen($path, 'wb');
        if (!$this->handle) {
            throw new RuntimeException('Cannot open feed file for writing: ' . $path);
        }

        if ($this->format === 'xml') {
            fwrite($this->handle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
            fwrite($this->handle, '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n<channel>\n");
            fwrite($this->handle, '<title>' . self::xml(isset($channel['title']) ? $channel['title'] : '') . "</title>\n");
            fwrite($this->handle, '<link>' . self::xml(isset($channel['link']) ? $channel['link'] : '') . "</link>\n");
            fwrite($this->handle, '<description>' . self::xml(isset($channel['description']) ? $channel['description'] : '') . "</description>\n");
        } else {
            fputcsv($this->handle, $this->columns, ',', '"', '\\');
        }
    }

    public function writeItem(array $item)
    {
        if ($this->format === 'xml') {
            $xml = "<item>\n";
            foreach ($this->columns as $col) {
                if (!isset($item[$col]) || $item[$col] === '' || $item[$col] === array()) {
                    continue;
                }
                $values = is_array($item[$col]) ? $item[$col] : array($item[$col]);
                foreach ($values as $value) {
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $xml .= '<g:' . $col . '>' . self::xml($value) . '</g:' . $col . ">\n";
                }
            }
            $xml .= "</item>\n";
            fwrite($this->handle, $xml);
        } else {
            $row = array();
            foreach ($this->columns as $col) {
                $value = isset($item[$col]) ? $item[$col] : '';
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $row[] = $value;
            }
            fputcsv($this->handle, $row, ',', '"', '\\');
        }
        $this->count++;
    }

    public function getCount()
    {
        return $this->count;
    }

    public function close()
    {
        if (!$this->handle) {
            return;
        }
        if ($this->format === 'xml') {
            fwrite($this->handle, "</channel>\n</rss>\n");
        }
        fclose($this->handle);
        $this->handle = null;
    }

    /** Escape for XML and drop characters that are illegal in XML 1.0. */
    public static function xml($value)
    {
        $value = (string) $value;
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        if ($value === null) {
            $value = '';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
